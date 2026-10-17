<?php

namespace App\Domain\Governance;

use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth2 client-credentials tokens of OptiFleet's service account in OptiNexus, one cached token per scope set (least
 * privilege: governance reads, delegation and the gateway each ask only for what they use). The token is cached until shortly
 * before the lifetime OptiNexus reports and is dropped when OptiNexus answers 401, so a rotated or revoked credential is picked
 * up on the next call. The secret never leaves this class and is never logged.
 */
class NexusTokenProvider
{
    public const SCOPES_GATEWAY = 'gateway.fleet.write gateway.fleet.read gateway.telematics.read event.write';

    public const SCOPES_GOVERNANCE = 'authorization.check commercial.read';

    public const SCOPES_DELEGATION = 'tenant.delegate';

    public function token(string $scopes, bool $fresh = false): string
    {
        $key = $this->cacheKey($scopes);

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->request($scopes);

        $token = (string) $response->json('access_token');
        $lifetime = (int) ($response->json('expires_in') ?: 3600);
        // Renew a minute early so a token never expires in flight; never cache longer than OptiNexus said.
        Cache::put($key, $token, max(30, min($lifetime - 60, 3000)));

        return $token;
    }

    public function forget(string $scopes): void
    {
        Cache::forget($this->cacheKey($scopes));
    }

    private function request(string $scopes): Response
    {
        $clientId = config('optinexus.gateway.client_id');
        $clientSecret = config('optinexus.gateway.client_secret');
        if (! $clientId || ! $clientSecret) {
            throw new GovernanceUnavailableException('The OptiNexus service account is not configured.');
        }

        try {
            $response = Http::asForm()
                ->connectTimeout((int) config('optinexus.governance.connect_timeout_seconds'))
                ->timeout((int) config('optinexus.governance.timeout_seconds'))
                ->post(config('optinexus.base_url').'/api/v1/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $scopes,
                ]);
        } catch (ConnectionException $e) {
            throw new GovernanceUnavailableException('OptiNexus could not be reached to authenticate.', null, $e);
        }

        if (! $response->ok() || ! $response->json('access_token')) {
            throw new GovernanceUnavailableException('OptiNexus gateway authentication failed.');
        }

        return $response;
    }

    private function cacheKey(string $scopes): string
    {
        // The client id is part of the key so a replaced credential never reuses the previous client's token.
        return 'optinexus.m2m.token.'.hash('sha256', config('optinexus.gateway.client_id').'|'.config('optinexus.base_url').'|'.$scopes);
    }
}
