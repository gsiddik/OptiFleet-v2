<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_platform_user_can_login(): void
    {
        [$user] = $this->makePlatformUser();
        $user->forceFill(['password' => Hash::make('secret123')])->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJsonPath('data.user.scope', 'platform');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        [$user] = $this->makePlatformUser();
        $user->forceFill(['password' => Hash::make('secret123')])->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ]);

        $response->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@optifleet.test',
            'password' => Hash::make('secret123'),
            'user_type' => 'platform',
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        [$user, $token] = $this->makePlatformUser(['tenant.view']);

        $response = $this->getJson('/api/v1/auth/me', $this->authHeaders($token));

        $response->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.permissions.0', 'tenant.view');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        [, $token] = $this->makePlatformUser();

        $this->postJson('/api/v1/auth/logout', [], $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertStatus(401);
    }

    public function test_tenant_switching_requires_active_membership(): void
    {
        $tenantA = $this->makeTenant(['code' => 'TA']);
        $tenantB = $this->makeTenant(['code' => 'TB']);
        [$user, $token] = $this->makeTenantUser($tenantA);

        $response = $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $tenantB->id], $this->authHeaders($token));
        $response->assertStatus(422);
    }

    public function test_user_can_switch_between_multiple_active_tenants(): void
    {
        $tenantA = $this->makeTenant(['code' => 'TA2']);
        $tenantB = $this->makeTenant(['code' => 'TB2']);
        [$user, $token] = $this->makeTenantUser($tenantA);

        TenantUser::query()->create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $tenantB->id], $this->authHeaders($token));
        $response->assertOk();
        $newToken = $response->json('data.token');

        $me = $this->getJson('/api/v1/auth/me', $this->authHeaders($newToken));
        $me->assertOk();
    }

    public function test_platform_scope_route_rejects_tenant_user(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makeTenantUser($tenant, ['branch.view']);

        $this->getJson('/api/v1/platform/dashboard', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_tenant_scope_route_rejects_platform_user(): void
    {
        [, $token] = $this->makePlatformUser();

        $this->getJson('/api/v1/app/dashboard', $this->authHeaders($token))->assertStatus(403);
    }
}
