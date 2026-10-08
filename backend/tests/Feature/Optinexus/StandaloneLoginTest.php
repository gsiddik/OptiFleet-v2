<?php

namespace Tests\Feature\Optinexus;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OptiFleet must stay usable on its own: with no OptiNexus configured, or with
 * OptiNexus switched on but unreachable, password login keeps working.
 */
class StandaloneLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'optinexus.enabled' => false,
            'optinexus.base_url' => '',
            'optinexus.sso.client_id' => null,
            'optinexus.sso.client_secret' => null,
            'optinexus.gateway.client_id' => null,
            'optinexus.gateway.client_secret' => null,
        ]);
        Http::preventStrayRequests();
    }

    private function tenantUser(array $permissions = []): array
    {
        $tenant = $this->makeTenant();
        [$user] = $this->makeTenantUser($tenant, $permissions);
        $user->forceFill(['password' => Hash::make('secret-pass')])->save();

        return [$tenant, $user->fresh()];
    }

    public function test_tenant_user_logs_in_and_uses_the_app_without_any_optinexus_setting(): void
    {
        [$tenant, $user] = $this->tenantUser(['vehicle.view']);
        $this->grantModule($tenant, 'VEHICLE');

        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.scope', 'tenant')
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->withToken($token)->getJson('/api/v1/app/vehicles')->assertOk();
        Http::assertNothingSent();
    }

    public function test_platform_user_logs_in_without_any_optinexus_setting(): void
    {
        [$user] = $this->makePlatformUser();
        $user->forceFill(['password' => Hash::make('secret-pass')])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.scope', 'platform');
        Http::assertNothingSent();
    }

    public function test_sso_reports_disabled_and_the_login_page_can_hide_the_button(): void
    {
        $this->getJson('/api/v1/auth/sso/status')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_sso_is_not_offered_when_the_flag_is_on_but_the_client_is_not_configured(): void
    {
        config(['optinexus.enabled' => true, 'optinexus.base_url' => 'http://nexus.test']);

        $this->getJson('/api/v1/auth/sso/status')->assertOk()->assertJsonPath('data.enabled', false);
    }

    public function test_password_login_still_works_while_optinexus_is_down(): void
    {
        config([
            'optinexus.enabled' => true,
            'optinexus.base_url' => 'http://nexus.test',
            'optinexus.sso.client_id' => 'onx_fleet',
            'optinexus.sso.client_secret' => 'secret',
            'optinexus.sso.redirect_uri' => 'http://fleet.test/api/v1/auth/sso/callback',
        ]);
        Http::fake(fn () => throw new ConnectionException('OptiNexus is unreachable'));
        [, $user] = $this->tenantUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();
        // SSO itself fails soft, back to the login page.
        $this->get('/api/v1/auth/sso/callback?code=abc&state=nope')->assertRedirect();
    }

    public function test_sync_command_is_a_no_op_when_disabled(): void
    {
        $this->makeTenant(['optinexus_tenant_id' => (string) Str::uuid()]);

        $this->assertSame(0, Artisan::call('optinexus:sync'));
        $this->assertStringContainsString('disabled', Artisan::output());
        Http::assertNothingSent();
    }
}
