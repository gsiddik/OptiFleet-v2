<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Integration\Optinexus\SsoLoginService;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackchannelLogoutTest extends TestCase
{
    private const NEXUS = 'http://nexus.test';

    private const CLIENT_ID = 'onx_fleet_test';

    private const LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';

    private const REVOKED = 'https://schemas.optinexus.io/event/access-revoked';

    private $signingKey;

    private string $nexusTenantId;

    private Tenant $tenant;

    private User $user;

    private string $subject;

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
        $this->subject = (string) Str::uuid();
        Http::fake($this->discoveryStubs());

        $this->tenant = $this->makeTenant(['optinexus_tenant_id' => $this->nexusTenantId]);
        [$this->user] = $this->makeTenantUser($this->tenant);
        $this->user->forceFill(['optinexus_subject' => $this->subject, 'password' => Hash::make('secret-pass')])->save();
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

    private function jwt(array $claims, $key = null): string
    {
        $key ??= $this->signingKey;
        $input = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'k1'])).'.'.$this->b64(json_encode($claims));
        openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);

        return $input.'.'.$this->b64($sig);
    }

    private function jwks(): array
    {
        $d = openssl_pkey_get_details($this->signingKey);

        return ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $this->b64($d['rsa']['n']), 'e' => $this->b64($d['rsa']['e'])]]];
    }

    /** A logout token as OptiNexus sends it. */
    private function logoutToken(array $override = [], ?array $revoked = null, $key = null): string
    {
        $events = [self::LOGOUT => new \stdClass];
        if ($revoked !== null) {
            $events[self::REVOKED] = $revoked;
        }

        return $this->jwt(array_merge([
            'iss' => self::NEXUS, 'aud' => self::CLIENT_ID, 'iat' => time(), 'exp' => time() + 120, 'jti' => (string) Str::uuid(),
            'sub' => $this->subject, 'email' => $this->user->email, 'events' => $events,
        ], $override), $key);
    }

    private function push(string $token)
    {
        return $this->post('/api/v1/auth/sso/backchannel-logout', ['logout_token' => $token]);
    }

    private function sessionToken(?User $user = null, ?Tenant $tenant = null): string
    {
        return ($user ?? $this->user)->createToken('tenant-session', ['tenant:'.($tenant ?? $this->tenant)->id])->plainTextToken;
    }

    public function test_a_logout_ends_every_session_but_leaves_the_account_active(): void
    {
        $first = $this->sessionToken();
        $second = $this->sessionToken();
        $this->withToken($first)->getJson('/api/v1/auth/me')->assertOk();

        $this->push($this->logoutToken())->assertOk();

        $this->assertSame(0, $this->user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertSame('active', $this->user->fresh()->status);
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'secret-pass'])->assertOk();
    }

    public function test_access_revoked_for_the_user_deactivates_the_account_and_blocks_password_login(): void
    {
        $this->sessionToken();

        $this->push($this->logoutToken(revoked: ['reason' => 'user_suspended', 'scope' => 'user']))->assertOk();

        $user = $this->user->fresh();
        $this->assertSame('inactive', $user->status);
        $this->assertNotNull($user->optinexus_deactivated_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertStatus(422);
    }

    public function test_access_revoked_for_one_tenant_only_deactivates_that_membership(): void
    {
        $otherTenant = $this->makeTenant(['optinexus_tenant_id' => (string) Str::uuid()]);
        TenantUser::query()->create(['tenant_id' => $otherTenant->id, 'user_id' => $this->user->id, 'status' => 'active']);
        $this->sessionToken();
        $this->sessionToken($this->user, $otherTenant);

        $this->push($this->logoutToken(revoked: ['reason' => 'tenant_membership_removed', 'scope' => 'tenant', 'tenant_id' => $this->nexusTenantId]))->assertOk();

        $membership = TenantUser::query()->where('user_id', $this->user->id)->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('inactive', $membership->status);
        $this->assertNotNull($membership->optinexus_deactivated_at);
        $this->assertSame('active', TenantUser::query()->where('user_id', $this->user->id)->where('tenant_id', $otherTenant->id)->value('status'));
        $this->assertSame('active', $this->user->fresh()->status);
        // Only the sessions of that tenant ended.
        $this->assertSame(1, $this->user->tokens()->count());
        $this->assertStringContainsString('tenant:'.$otherTenant->id, json_encode($this->user->tokens()->first()->abilities));
    }

    public function test_the_next_optinexus_sign_in_reactivates_only_what_optinexus_deactivated(): void
    {
        $this->push($this->logoutToken(revoked: ['reason' => 'user_suspended', 'scope' => 'user']))->assertOk();
        $this->assertSame('inactive', $this->user->fresh()->status);

        $ticket = app(SsoLoginService::class)->ticketFor([
            'sub' => $this->subject, 'tenant_id' => $this->nexusTenantId, 'email' => $this->user->email, 'email_verified' => true,
        ]);

        $this->assertNotEmpty($ticket);
        $user = $this->user->fresh();
        $this->assertSame('active', $user->status);
        $this->assertNull($user->optinexus_deactivated_at);
    }

    public function test_an_account_an_administrator_switched_off_is_never_reactivated(): void
    {
        $this->user->forceFill(['status' => 'inactive'])->save(); // local decision, no OptiNexus marker

        $this->push($this->logoutToken(revoked: ['reason' => 'user_suspended', 'scope' => 'user']))->assertOk();
        $this->assertNull($this->user->fresh()->optinexus_deactivated_at);

        $this->expectException(SsoException::class);
        $this->expectExceptionMessage('user_inactive');
        app(SsoLoginService::class)->ticketFor([
            'sub' => $this->subject, 'tenant_id' => $this->nexusTenantId, 'email' => $this->user->email, 'email_verified' => true,
        ]);
    }

    public function test_an_account_that_never_signed_in_through_optinexus_is_matched_by_email(): void
    {
        [$other] = $this->makeTenantUser($this->tenant);
        $other->forceFill(['email' => 'driver@example.test'])->save();

        $this->push($this->logoutToken(['sub' => (string) Str::uuid(), 'email' => 'Driver@Example.test'], ['reason' => 'user_disabled', 'scope' => 'user']))->assertOk();

        $this->assertSame('inactive', $other->fresh()->status);
        $this->assertSame('active', $this->user->fresh()->status);
    }

    public function test_an_account_bound_to_another_identity_is_not_touched(): void
    {
        $this->push($this->logoutToken(['sub' => (string) Str::uuid()], ['reason' => 'user_disabled', 'scope' => 'user']))->assertOk();

        $this->assertSame('active', $this->user->fresh()->status);
    }

    public function test_an_unknown_user_is_accepted_without_effect(): void
    {
        $this->push($this->logoutToken(['sub' => (string) Str::uuid(), 'email' => 'nobody@example.test'], ['reason' => 'user_disabled', 'scope' => 'user']))->assertOk();

        $this->assertSame('active', $this->user->fresh()->status);
    }

    public function test_a_platform_user_is_never_affected(): void
    {
        [$platform] = $this->makePlatformUser();
        $platform->forceFill(['email' => 'ops@example.test'])->save();

        $this->push($this->logoutToken(['sub' => (string) Str::uuid(), 'email' => 'ops@example.test'], ['reason' => 'user_disabled', 'scope' => 'user']))->assertOk();

        $this->assertSame('active', $platform->fresh()->status);
    }

    public function test_a_tenant_scope_for_an_unlinked_tenant_does_nothing(): void
    {
        $this->push($this->logoutToken(revoked: ['reason' => 'tenant_suspended', 'scope' => 'tenant', 'tenant_id' => (string) Str::uuid()]))->assertOk();

        $this->assertSame('active', TenantUser::query()->where('user_id', $this->user->id)->value('status'));
    }

    public function test_invalid_tokens_are_refused_and_change_nothing(): void
    {
        $this->sessionToken();
        $sessions = $this->user->tokens()->count();
        $stranger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $bad = [
            'wrong signature' => $this->logoutToken(revoked: ['scope' => 'user'], key: $stranger),
            'wrong issuer' => $this->logoutToken(['iss' => 'http://evil.test'], ['scope' => 'user']),
            'wrong audience' => $this->logoutToken(['aud' => 'someone-else'], ['scope' => 'user']),
            'stale' => $this->logoutToken(['iat' => time() - 3600, 'exp' => time() + 120], ['scope' => 'user']),
            'expired' => $this->logoutToken(['exp' => time() - 600], ['scope' => 'user']),
            'has a nonce' => $this->logoutToken(['nonce' => 'abc'], ['scope' => 'user']),
            'no logout event' => $this->jwt(['iss' => self::NEXUS, 'aud' => self::CLIENT_ID, 'iat' => time(), 'jti' => 'x1', 'sub' => $this->subject, 'events' => ['other' => new \stdClass]]),
            'no subject' => $this->logoutToken(['sub' => ''], ['scope' => 'user']),
            'no jti' => $this->logoutToken(['jti' => ''], ['scope' => 'user']),
            'not a jwt' => 'garbage',
        ];

        foreach ($bad as $label => $token) {
            $this->push($token)->assertStatus(400, "Expected 400 for: {$label}");
        }

        $this->assertSame('active', $this->user->fresh()->status);
        $this->assertSame($sessions, $this->user->tokens()->count());
    }

    public function test_a_token_cannot_be_replayed(): void
    {
        $token = $this->logoutToken();

        $this->push($token)->assertOk();
        $this->push($token)->assertStatus(400)->assertJsonPath('error_description', 'logout_token_replayed');
    }

    public function test_it_is_refused_when_the_integration_is_off(): void
    {
        config(['optinexus.enabled' => false]);

        $this->push($this->logoutToken())->assertStatus(400)->assertJsonPath('error', 'sso_disabled');
    }

    public function test_a_rotated_signing_key_is_picked_up(): void
    {
        $this->push($this->logoutToken())->assertOk(); // caches the key set

        $this->signingKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        Http::swap(new Factory);
        Http::fake($this->discoveryStubs());

        $this->push($this->logoutToken())->assertOk();
    }
}
