<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    private const NEXUS = 'http://nexus.test';

    private const CLIENT_ID = 'onx_fleet_test';

    private $signingKey;

    private string $nexusTenantId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'optinexus.enabled' => true,
            'optinexus.base_url' => self::NEXUS,
            'optinexus.sso.client_id' => self::CLIENT_ID,
            'optinexus.sso.client_secret' => 'secret',
            'optinexus.sso.redirect_uri' => 'http://fleet.test/api/v1/auth/sso/callback',
            'optinexus.sso.frontend_url' => 'http://spa.test',
        ]);
        Cache::flush();

        $this->signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->nexusTenantId = (string) Str::uuid();

        Http::fake($this->discoveryStubs());
    }

    private function discoveryStubs(): array
    {
        return [
            self::NEXUS.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::NEXUS, 'authorization_endpoint' => self::NEXUS.'/oidc/authorize', 'token_endpoint' => self::NEXUS.'/api/oidc/token',
                'userinfo_endpoint' => self::NEXUS.'/api/oidc/userinfo', 'jwks_uri' => self::NEXUS.'/oidc/jwks', 'end_session_endpoint' => self::NEXUS.'/oidc/logout',
            ]),
            self::NEXUS.'/oidc/jwks' => Http::response($this->jwks()),
        ];
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function jwt(array $claims, $key = null, string $alg = 'RS256'): string
    {
        $key ??= $this->signingKey;
        $input = $this->b64(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => 'k1'])).'.'.$this->b64(json_encode($claims));
        openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);

        return $input.'.'.$this->b64($sig);
    }

    private function jwks(): array
    {
        $d = openssl_pkey_get_details($this->signingKey);

        return ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $this->b64($d['rsa']['n']), 'e' => $this->b64($d['rsa']['e'])]]];
    }

    /** Starts a login and returns [state, nonce] as OptiNexus would receive them. */
    private function begin(): array
    {
        $location = $this->get('/api/v1/auth/sso/redirect')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith(self::NEXUS.'/oidc/authorize', $location);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(self::CLIENT_ID, $q['client_id']);

        return [$q['state'], $q['nonce']];
    }

    private function fakeNexus(string $nonce, array $claims = [], array $userinfo = [], $signWith = null, ?string $rawIdToken = null): void
    {
        $base = array_merge([
            'iss' => self::NEXUS, 'aud' => self::CLIENT_ID, 'iat' => time(), 'exp' => time() + 600, 'nonce' => $nonce,
            'sub' => (string) Str::uuid(), 'email' => 'x@example.test', 'email_verified' => true,
            'tenant_id' => $this->nexusTenantId, 'tenant_code' => 'T1', 'apps' => [['code' => 'optiradar', 'name' => 'OptiRadar', 'launch_url' => 'http://radar.test']],
        ], $claims);

        // A fresh fake per call: Laravel keeps the first matching stub, so a stale one would win.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($this->discoveryStubs() + [
            self::NEXUS.'/api/oidc/token' => Http::response(['access_token' => 'at', 'id_token' => $rawIdToken ?? $this->jwt($base, $signWith)]),
            self::NEXUS.'/api/oidc/userinfo' => Http::response(array_merge(['sub' => $base['sub'], 'tenant_id' => $base['tenant_id']], $userinfo)),
        ]);
    }

    /** A tenant linked to OptiNexus with an existing OptiFleet user. */
    private function linkedTenantUser(array $userOverrides = []): array
    {
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $this->nexusTenantId]);
        [$user] = $this->makeTenantUser($tenant);
        if ($userOverrides) {
            $user->forceFill($userOverrides)->save();
        }

        return [$tenant, $user->fresh()];
    }

    private function finish(string $state, string $nonce, array $claims, array $userinfo = [], $signWith = null)
    {
        $this->fakeNexus($nonce, $claims, $userinfo, $signWith);

        return $this->get('/api/v1/auth/sso/callback?code=abc&state='.$state);
    }

    public function test_sso_is_off_by_default_and_password_login_is_untouched(): void
    {
        config(['optinexus.enabled' => false]);

        $this->getJson('/api/v1/auth/sso/status')->assertOk()->assertJsonPath('data.enabled', false);
        $this->get('/api/v1/auth/sso/redirect')->assertRedirect('http://spa.test/login?sso_error=sso_disabled');

        $tenant = $this->makeTenant();
        [$user] = $this->makeTenantUser($tenant);
        $user->forceFill(['password' => 'secret-pass'])->save();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();
    }

    public function test_full_login_by_email_links_the_subject_and_returns_a_working_session(): void
    {
        [$tenant, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();
        $sub = (string) Str::uuid();

        $response = $this->finish($state, $nonce, ['sub' => $sub, 'email' => strtoupper($user->email)]);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://spa.test/sso/callback?ticket=', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $q);

        $exchange = $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertOk();
        $exchange->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.sso.apps.0.code', 'optiradar');
        $this->assertSame($sub, $user->fresh()->optinexus_subject);

        $this->getJson('/api/v1/auth/me', $this->authHeaders($exchange->json('data.token')))->assertOk()->assertJsonPath('data.id', $user->id);
        // The token is bound to the tenant of the verified claim.
        $this->assertTrue($user->tokens()->first()->can('tenant:'.$tenant->id));
    }

    public function test_ticket_is_single_use(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();
        parse_str(parse_url($this->finish($state, $nonce, ['email' => $user->email])->headers->get('Location'), PHP_URL_QUERY), $q);

        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertOk();
        $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $q['ticket']])->assertStatus(422);
    }

    public function test_known_subject_logs_in_even_if_the_email_changed(): void
    {
        $sub = (string) Str::uuid();
        [, $user] = $this->linkedTenantUser(['optinexus_subject' => $sub]);
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['sub' => $sub, 'email' => 'renamed@example.test'])
            ->assertRedirectContains('/sso/callback?ticket=');
    }

    public function test_state_cannot_be_replayed_or_invented(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['email' => $user->email])->assertRedirectContains('/sso/callback');
        $this->finish($state, $nonce, ['email' => $user->email])->assertRedirect('http://spa.test/login?sso_error=state_invalid');
        $this->get('/api/v1/auth/sso/callback?code=abc&state=made-up')->assertRedirect('http://spa.test/login?sso_error=state_invalid');
    }

    /** @dataProvider tamperedTokens */
    public function test_forged_or_mismatched_id_tokens_are_rejected(array $claims, bool $foreignKey): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();
        $foreign = $foreignKey ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) : null;

        $this->finish($state, $nonce, array_merge(['email' => $user->email], $claims), [], $foreign)
            ->assertRedirect('http://spa.test/login?sso_error=id_token_invalid');
        $this->assertNull($user->fresh()->optinexus_subject);
    }

    public static function tamperedTokens(): array
    {
        return [
            'signed with another key' => [[], true],
            'wrong audience' => [['aud' => 'someone-else'], false],
            'wrong issuer' => [['iss' => 'http://evil.test'], false],
            'expired' => [['exp' => time() - 10], false],
            'wrong nonce' => [['nonce' => 'not-ours'], false],
        ];
    }

    public function test_token_with_alg_none_is_rejected(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();
        $claims = ['iss' => self::NEXUS, 'aud' => self::CLIENT_ID, 'exp' => time() + 600, 'nonce' => $nonce, 'sub' => 's', 'email' => $user->email, 'email_verified' => true, 'tenant_id' => $this->nexusTenantId];
        $forged = $this->b64(json_encode(['alg' => 'none'])).'.'.$this->b64(json_encode($claims)).'.';
        $this->fakeNexus($nonce, rawIdToken: $forged);

        $this->get('/api/v1/auth/sso/callback?code=abc&state='.$state)->assertRedirect('http://spa.test/login?sso_error=id_token_invalid');
    }

    public function test_userinfo_must_agree_with_the_id_token(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();

        // OptiNexus no longer vouches for this user (e.g. access revoked after the code was issued).
        $this->finish($state, $nonce, ['email' => $user->email], ['sub' => 'a-different-user'])
            ->assertRedirect('http://spa.test/login?sso_error=access_denied');
    }

    public function test_unlinked_tenant_is_refused(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['email' => $user->email, 'tenant_id' => (string) Str::uuid()], ['tenant_id' => null])
            ->assertRedirect('http://spa.test/login?sso_error=access_denied');
    }

    public function test_unknown_tenant_claim_is_tenant_not_linked(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();
        $other = (string) Str::uuid();

        $this->finish($state, $nonce, ['email' => $user->email, 'tenant_id' => $other], ['tenant_id' => $other])
            ->assertRedirect('http://spa.test/login?sso_error=tenant_not_linked');
    }

    public function test_user_that_does_not_exist_locally_is_not_created(): void
    {
        $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();
        $before = User::query()->count();

        $this->finish($state, $nonce, ['email' => 'nobody@example.test'])->assertRedirect('http://spa.test/login?sso_error=user_not_provisioned');
        $this->assertSame($before, User::query()->count());
    }

    public function test_unverified_email_is_never_used_to_link_an_account(): void
    {
        [, $user] = $this->linkedTenantUser();
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['email' => $user->email, 'email_verified' => false])->assertRedirect('http://spa.test/login?sso_error=email_not_verified');
    }

    public function test_account_already_bound_to_another_identity_is_not_rebound(): void
    {
        [, $user] = $this->linkedTenantUser(['optinexus_subject' => (string) Str::uuid()]);
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['sub' => (string) Str::uuid(), 'email' => $user->email])->assertRedirect('http://spa.test/login?sso_error=account_conflict');
    }

    public function test_user_without_membership_in_the_tenant_is_refused(): void
    {
        $this->makeTenant(['optinexus_tenant_id' => $this->nexusTenantId]);
        $otherTenant = $this->makeTenant();
        [$outsider] = $this->makeTenantUser($otherTenant);
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['email' => $outsider->email])->assertRedirect('http://spa.test/login?sso_error=no_membership');
    }

    public function test_inactive_user_and_suspended_tenant_are_refused(): void
    {
        [$tenant, $user] = $this->linkedTenantUser();
        $user->forceFill(['status' => 'inactive'])->save();
        [$state, $nonce] = $this->begin();
        $this->finish($state, $nonce, ['email' => $user->email])->assertRedirect('http://spa.test/login?sso_error=user_inactive');

        $user->forceFill(['status' => 'active'])->save();
        $tenant->forceFill(['status' => 'SUSPENDED'])->save();
        [$state, $nonce] = $this->begin();
        $this->finish($state, $nonce, ['email' => $user->email])->assertRedirect('http://spa.test/login?sso_error=tenant_inactive');
    }

    public function test_platform_users_cannot_sign_in_through_tenant_sso(): void
    {
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $this->nexusTenantId]);
        [$platform] = $this->makePlatformUser();
        TenantUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $platform->id, 'status' => 'active', 'joined_at' => now()]);
        [$state, $nonce] = $this->begin();

        $this->finish($state, $nonce, ['email' => $platform->email])->assertRedirect('http://spa.test/login?sso_error=user_not_provisioned');
    }

    public function test_provider_error_is_reported_without_reaching_the_token_endpoint(): void
    {
        Http::fake();
        $this->get('/api/v1/auth/sso/callback?error=access_denied')->assertRedirect('http://spa.test/login?sso_error=access_denied');
        Http::assertNothingSent();
    }
}
