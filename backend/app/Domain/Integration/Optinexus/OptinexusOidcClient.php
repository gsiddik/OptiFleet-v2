<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Relying-party side of OptiNexus SSO (authorization code + PKCE).
 *
 * State, nonce and PKCE verifier live server-side in the cache keyed by the
 * opaque `state`, so the browser only ever carries the state. A state is
 * single use. The id_token is verified (signature, iss, aud, exp, nonce) and
 * then cross-checked against /userinfo, which re-validates the user's access
 * to this application at OptiNexus at that moment.
 */
class OptinexusOidcClient
{
    public function __construct(private readonly JwtRs256Verifier $verifier) {}

    public function enabled(): bool
    {
        return config('optinexus.enabled')
            && config('optinexus.base_url') !== ''
            && config('optinexus.sso.client_id')
            && config('optinexus.sso.client_secret');
    }

    public function authorizationUrl(?string $tenantHint = null): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $nonce = Str::random(32);

        Cache::put($this->cacheKey($state), ['nonce' => $nonce, 'verifier' => $verifier], config('optinexus.sso.state_ttl_seconds'));

        return $this->discovery()['authorization_endpoint'].'?'.http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => config('optinexus.sso.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'tenant_hint' => $tenantHint,
        ]));
    }

    /**
     * @return array<string, mixed> verified claims (sub, email, tenant_id, apps, ...)
     *
     * @throws SsoException
     */
    public function completeLogin(string $code, string $state): array
    {
        $pending = Cache::pull($this->cacheKey($state));
        if (! $pending) {
            throw new SsoException('state_invalid');
        }

        $discovery = $this->discovery();
        $response = Http::asForm()
            ->withBasicAuth(config('optinexus.sso.client_id'), config('optinexus.sso.client_secret'))
            ->timeout(15)
            ->post($discovery['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'code_verifier' => $pending['verifier'],
            ]);

        if (! $response->ok()) {
            throw new SsoException($response->json('error') === 'access_denied' ? 'access_denied' : 'token_exchange_failed');
        }

        $claims = $this->verifier->verify((string) $response->json('id_token'), $this->jwks());
        if (! $claims
            || ($claims['iss'] ?? null) !== $discovery['issuer']
            || ($claims['aud'] ?? null) !== config('optinexus.sso.client_id')
            || ($claims['exp'] ?? 0) < time()
            || ! hash_equals($pending['nonce'], (string) ($claims['nonce'] ?? ''))) {
            throw new SsoException('id_token_invalid');
        }

        $userinfo = Http::withToken((string) $response->json('access_token'))->timeout(15)->get($discovery['userinfo_endpoint']);
        if (! $userinfo->ok() || $userinfo->json('sub') !== $claims['sub'] || $userinfo->json('tenant_id') !== ($claims['tenant_id'] ?? null)) {
            throw new SsoException('access_denied');
        }

        return $claims;
    }

    /**
     * Validates a logout token pushed by OptiNexus (OIDC Back-Channel Logout
     * 1.0 section 2.6): signature, issuer, audience, freshness, the logout
     * event, a subject, no nonce, and a jti we have not seen before.
     *
     * @return array<string, mixed>
     *
     * @throws SsoException
     */
    public function verifyLogoutToken(string $jwt): array
    {
        // An id_token is typed "JWT"; it must never pass for a logout token. An untyped token is still accepted.
        $header = json_decode((string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/'), true), true);
        if (is_array($header) && isset($header['typ']) && strtolower((string) $header['typ']) !== 'logout+jwt') {
            throw new SsoException('logout_token_invalid');
        }

        $issuer = $this->discovery()['issuer'];
        $claims = $this->verifier->verify($jwt, $this->jwks());
        if (! $claims) {
            // The signing key may have rotated since we cached the key set.
            Cache::forget('optinexus.jwks');
            $claims = $this->verifier->verify($jwt, $this->jwks());
        }

        $now = time();
        $audience = (array) ($claims['aud'] ?? []);
        $valid = $claims
            && ($claims['iss'] ?? null) === $issuer
            && in_array(config('optinexus.sso.client_id'), $audience, true)
            && is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + 60 && $claims['iat'] >= $now - 600
            && (! isset($claims['exp']) || (is_int($claims['exp']) && $claims['exp'] >= $now - 60))
            && is_array($claims['events'] ?? null) && array_key_exists(BackchannelLogoutService::EVENT_BACKCHANNEL_LOGOUT, $claims['events'])
            && ! array_key_exists('nonce', $claims)
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && is_string($claims['jti'] ?? null) && $claims['jti'] !== '';

        if (! $valid) {
            throw new SsoException('logout_token_invalid');
        }

        if (! Cache::add('optinexus.logout.jti.'.hash('sha256', $claims['jti']), 1, 900)) {
            throw new SsoException('logout_token_replayed');
        }

        return $claims;
    }

    public function logoutUrl(): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;

        return $endpoint ? $endpoint.'?'.http_build_query(['client_id' => config('optinexus.sso.client_id')]) : null;
    }

    private function discovery(): array
    {
        return Cache::remember('optinexus.discovery', 3600, function () {
            $response = Http::timeout(10)->get(config('optinexus.base_url').'/.well-known/openid-configuration');
            if (! $response->ok()) {
                throw new RuntimeException('OptiNexus discovery document is unavailable.');
            }

            return $response->json();
        });
    }

    private function jwks(): array
    {
        return Cache::remember('optinexus.jwks', 300, fn () => Http::timeout(10)->get($this->discovery()['jwks_uri'])->throw()->json());
    }

    private function redirectUri(): string
    {
        return config('optinexus.sso.redirect_uri') ?: url('/api/v1/auth/sso/callback');
    }

    private function cacheKey(string $state): string
    {
        return 'optinexus.sso.state.'.hash('sha256', $state);
    }
}
