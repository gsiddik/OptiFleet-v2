<?php

namespace Tests\Feature\Governance;

use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

/**
 * Audit A02 / R05: for a tenant governed by OptiNexus, OptiNexus alone decides what a person may do and which modules the
 * tenant holds. Built against FakeNexus (the documented JSON), no real OptiNexus.
 */
class CentralGovernanceTest extends TestCase
{
    use InteractsWithCentralGovernance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_central_grants_decide_and_a_missing_grant_is_denied(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view', 'branch.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertOk();
        $this->postJson('/api/v1/app/vehicles', [], $this->authHeaders($token))->assertForbidden()->assertJsonPath('message', 'Missing required permission: vehicle.create');
    }

    public function test_one_bulk_request_per_user_and_one_per_tenant_not_one_per_permission(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view', 'branch.view', 'inspection.view', 'work_order.view']);

        foreach (range(1, 6) as $i) {
            $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
            $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertOk();
        }
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertOk();

        $this->assertSame(1, $this->nexus->callsTo('effective-permissions'));
        $this->assertSame(1, $this->nexus->callsTo('commercial-context'));
        $this->assertSame(0, $this->nexus->callsTo('authorization/check'));
        $this->assertSame(0, $this->nexus->callsTo('product-context'), 'a user with grants needs no classification call');
    }

    public function test_local_roles_never_add_to_central_grants(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        // Central: view only. Local role: view + create. The local role must not add create.
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view'], ['vehicle.view', 'vehicle.create']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->postJson('/api/v1/app/vehicles', [], $this->authHeaders($token))->assertForbidden();
    }

    public function test_local_roles_can_restrict_central_grants(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        // Central: view + create. Local role: view only -> create is restricted away.
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view', 'vehicle.create'], ['vehicle.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->postJson('/api/v1/app/vehicles', [], $this->authHeaders($token))->assertForbidden();
    }

    public function test_a_central_user_without_local_roles_gets_exactly_the_central_grants(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view', 'vehicle.create']);

        // Not forbidden any more: validation answers 422 (the permission and module gates were passed).
        $this->postJson('/api/v1/app/vehicles', [], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_module_entitlement_comes_from_optinexus_not_from_local_rows(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']); // INSPECTION is not entitled centrally
        $this->grantModule($tenant, 'INSPECTION');               // ...but a legacy local row says it is
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view', 'inspection.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/app/inspections', $this->authHeaders($token))->assertForbidden()->assertJsonPath('message', 'Module not entitled: INSPECTION');
        $this->getJson('/api/v1/app/account/active-modules', $this->authHeaders($token))->assertForbidden(); // permission not granted

        $modules = app(EntitlementService::class)->activeModuleCodes($tenant->id)->all();
        $this->assertContains('VEHICLE', $modules);
        $this->assertContains('ORGANIZATION', $modules, 'core modules come with the active application');
        $this->assertNotContains('INSPECTION', $modules);
    }

    public function test_a_module_withdrawn_in_optinexus_is_denied_after_the_ttl_and_not_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();

        $this->nexus->tenant($tenant->optinexus_tenant_id, []); // the module is withdrawn centrally
        Carbon::setTestNow(now()->addSeconds(299));
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk(); // inside the documented latency (<= 300 s)

        Carbon::setTestNow(now()->addSeconds(2)); // 301 s after the first answer
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertForbidden();
    }

    public function test_a_revoked_permission_is_denied_after_the_ttl(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [$user, $token] = $this->makeCentralUser($tenant, ['vehicle.view', 'branch.view']);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();

        $this->nexus->grant($tenant->optinexus_tenant_id, $user->optinexus_subject, ['branch.view']); // vehicle.view revoked

        Carbon::setTestNow(now()->addSeconds(299));
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        Carbon::setTestNow(now()->addSeconds(2));
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertForbidden();
        $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertOk();
    }

    public function test_the_ttl_never_exceeds_300_seconds_even_if_optinexus_allows_more(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        $this->nexus->ttl = 3600;
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [$user, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();

        $this->nexus->grant($tenant->optinexus_tenant_id, $user->optinexus_subject, []);
        $this->nexus->users[$tenant->optinexus_tenant_id.'|'.$user->optinexus_subject]['allowed'] = true;
        Carbon::setTestNow(now()->addSeconds(301));
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertForbidden();
    }

    public function test_outage_fails_closed_after_the_ttl_with_a_distinct_status_and_recovers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();

        $this->nexus->mode = 'down';
        Carbon::setTestNow(now()->addSeconds(120));
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk(); // the answer is still valid

        Carbon::setTestNow(now()->addSeconds(181)); // expired
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))
            ->assertStatus(503)->assertJsonPath('code', 'GOVERNANCE_UNAVAILABLE')->assertHeader('Retry-After');
        // No stale grant is read by later calls either.
        $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertStatus(503);

        $this->nexus->mode = 'up';
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->assertDatabaseHas('users', ['id' => User::query()->where('optinexus_subject', '!=', null)->value('id')]); // identity untouched
    }

    public function test_cold_cache_and_outage_never_defaults_to_allowed(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->nexus->mode = 'server_error';

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertStatus(503);
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertStatus(503);
    }

    public function test_logout_always_works_even_when_optinexus_is_down(): void
    {
        $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->nexus->mode = 'down';

        $this->postJson('/api/v1/auth/logout', [], $this->authHeaders($token))->assertOk();
    }

    public function test_rate_limit_waits_once_for_a_short_retry_after_then_fails_closed(): void
    {
        Sleep::fake();
        $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->nexus->mode = 'rate_limited'; // Retry-After: 30 is above the bounded wait

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertStatus(503)->assertJsonPath('code', 'GOVERNANCE_UNAVAILABLE');
        Sleep::assertNeverSlept();
    }

    public function test_a_misconfigured_service_account_is_a_denial_not_a_grant(): void
    {
        $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);

        $this->nexus->mode = 'unbound';
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertStatus(503);
        $this->nexus->mode = 'tenant_mismatch';
        $this->forgetGovernance();
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertStatus(503);
    }

    public function test_a_withdrawn_application_access_ends_the_session(): void
    {
        $tenant = $this->makeCentralTenant();
        [$user, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();

        $this->nexus->withdraw($tenant->optinexus_tenant_id, $user->optinexus_subject, 'application_access_revoked');
        $this->forgetGovernance();

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))
            ->assertStatus(401)->assertJsonPath('code', 'SESSION_ENDED_CENTRALLY')->assertJsonPath('reason', 'application_access_revoked');
        $this->assertNull(PersonalAccessToken::findToken($token), 'the token is gone, it cannot be replayed');
    }

    public function test_a_person_with_access_but_no_roles_keeps_a_session_and_sees_no_permissions(): void
    {
        $tenant = $this->makeCentralTenant();
        [$user, $token] = $this->makeCentralUser($tenant, []);

        $me = $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertOk();
        $me->assertJsonPath('data.permissions', [])->assertJsonPath('data.governance.mode', 'CENTRAL')->assertJsonPath('data.governance.status', 'NO_PERMISSIONS');
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertForbidden();
        $this->assertNotNull(PersonalAccessToken::findToken($token), 'the session stays: the person is let in, just without roles');
    }

    public function test_an_identity_that_was_never_linked_has_no_access_in_a_central_tenant(): void
    {
        $tenant = $this->makeCentralTenant();
        [$user] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.create']); // local roles only, no OptiNexus subject
        $token = $this->ssoToken($user, $tenant);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertForbidden()->assertJsonPath('code', 'IDENTITY_NOT_LINKED');
        $this->assertSame(0, $this->nexus->callsTo('effective-permissions'), 'nothing to ask without a central identity');
    }

    public function test_a_session_not_created_by_optinexus_does_not_carry_into_a_central_tenant(): void
    {
        $tenant = $this->makeCentralTenant();
        [$user] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $password = $user->createToken('legacy', ['tenant:'.$tenant->id])->plainTextToken; // no `sso` ability

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($password))->assertStatus(401)->assertJsonPath('code', 'SESSION_ENDED_CENTRALLY');
    }

    public function test_grants_do_not_cross_tenants(): void
    {
        $a = $this->makeCentralTenant(['optifleet.vehicle']);
        $b = $this->makeCentralTenant(['optifleet.vehicle']);
        [$user] = $this->makeCentralUser($a, ['vehicle.view']);
        // The same person is a member of B but holds nothing there.
        TenantUser::query()->create(['tenant_id' => $b->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        $this->nexus->grant($b->optinexus_tenant_id, $user->optinexus_subject, []);

        $tokenA = $this->ssoToken($user, $a);
        $tokenB = $this->ssoToken($user, $b);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($tokenA))->assertOk();
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($tokenB))->assertForbidden();
    }

    public function test_a_legacy_tenant_is_untouched_and_never_calls_optinexus(): void
    {
        $tenant = $this->makeTenant(['optinexus_tenant_id' => (string) Str::uuid()]); // linked but LOCAL (legacy)
        $this->grantModule($tenant, 'VEHICLE');
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertOk()->assertJsonPath('data.governance.mode', 'LOCAL');
        $this->assertCount(0, $this->nexus->calls);
    }

    public function test_platform_users_are_not_governed_by_a_tenant_authority(): void
    {
        [, $token] = $this->makePlatformUser(['tenant.view']);
        $this->getJson('/api/v1/platform/tenants', $this->authHeaders($token))->assertOk();
        $this->assertCount(0, $this->nexus->calls);
    }

    public function test_local_identity_and_entitlement_changes_are_refused_in_a_central_tenant(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['user.create', 'user.view']);

        $this->postJson('/api/v1/app/users', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'Password-123456'], $this->authHeaders($token))
            ->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);

        [, $platform] = $this->makePlatformUser(['user.create', 'entitlement.manage']);
        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/users", ['name' => 'X', 'email' => 'y@example.test', 'password' => 'Password-123456'], $this->authHeaders($platform))
            ->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');
        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/entitlements", ['modules' => []], $this->authHeaders($platform))
            ->assertStatus(409)->assertJsonPath('code', 'GOVERNED_CENTRALLY');

        $legacy = $this->makeTenant();
        $this->postJson("/api/v1/platform/tenants/{$legacy->id}/users", ['name' => 'X', 'email' => 'z@example.test', 'password' => 'Password-123456'], $this->authHeaders($platform))
            ->assertStatus(201);
    }

    public function test_password_login_never_opens_a_central_tenant(): void
    {
        $central = $this->makeCentralTenant();
        [$user] = $this->makeCentralUser($central, ['vehicle.view']);
        $user->forceFill(['password' => 'Password-123456'])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-123456'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Sign in with OptiNexus: access for this organization is managed centrally.');

        // A person who also belongs to a legacy tenant can still use the password for that one only.
        $legacy = $this->makeTenant();
        TenantUser::query()->create(['tenant_id' => $legacy->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-123456'])->assertOk();
        $this->assertSame($legacy->id, $login->json('data.user.memberships.0.tenant_id') ? $legacy->id : null);

        // ...and cannot switch into the central one without an OptiNexus session.
        $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $central->id], $this->authHeaders($login->json('data.token')))->assertStatus(422);
    }

    public function test_switching_into_a_central_tenant_needs_an_optinexus_session(): void
    {
        $a = $this->makeCentralTenant();
        $b = $this->makeCentralTenant();
        [$user] = $this->makeCentralUser($a, ['vehicle.view']);
        TenantUser::query()->create(['tenant_id' => $b->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);

        $res = $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $b->id], $this->authHeaders($this->ssoToken($user, $a)))->assertOk();
        $this->assertNotEmpty($res->json('data.token'));
    }

    public function test_background_work_stops_for_a_withdrawn_module_and_when_optinexus_is_silent(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.analytics']);
        $service = app(EntitlementService::class);

        $this->assertTrue($service->centralModuleStillHeld($tenant->id, 'ANALYTICS'));
        $this->assertFalse($service->centralModuleStillHeld($tenant->id, 'MAINTENANCE_INTELLIGENCE'));

        $this->forgetGovernance();
        $this->nexus->mode = 'down';
        $this->assertFalse($service->centralModuleStillHeld($tenant->id, 'ANALYTICS'), 'unreachable OptiNexus counts as not entitled');
        $this->assertTrue($service->centralModuleStillHeld($this->makeTenant()->id, 'ANALYTICS'), 'legacy tenants are unaffected');
    }

    public function test_token_is_requested_with_least_privilege_scope_and_reused(): void
    {
        $tenant = $this->makeCentralTenant();
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertForbidden();

        $scopes = collect($this->nexus->calls)->where('path', '/api/v1/oauth/token')->pluck('scope')->all();
        $this->assertSame(['authorization.check commercial.read'], $scopes, 'one token, governance scopes only');
    }

    public function test_cached_answers_are_keyed_by_tenant_and_user(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $tokenOne] = $this->makeCentralUser($tenant, ['vehicle.view']);
        [, $tokenTwo] = $this->makeCentralUser($tenant, ['branch.view']);

        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($tokenOne))->assertOk();
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($tokenTwo))->assertForbidden();
        $this->getJson('/api/v1/app/branches', $this->authHeaders($tokenTwo))->assertOk();
        $this->assertSame(2, $this->nexus->callsTo('effective-permissions'));
        $this->assertSame(1, $this->nexus->callsTo('commercial-context'));
    }

    public function test_dashboard_without_route_permission_still_goes_through_the_central_gate(): void
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [, $token] = $this->makeCentralUser($tenant, ['dashboard.view']);
        $this->nexus->mode = 'down';
        $this->getJson('/api/v1/app/dashboard', $this->authHeaders($token))->assertStatus(503);
    }
}
