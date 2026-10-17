<?php

namespace Tests\Feature\Governance;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Governance\Delegation\DelegationErrors;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\Messages;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\Support\FakeNexus;
use Tests\Support\FakeNexusDelegation;
use Tests\TestCase;

/**
 * Users and roles of a centrally governed tenant, managed from OptiFleet through the OptiNexus delegation API (audit R08/A25),
 * against FakeNexusDelegation, which enforces the documented rules (Admin Tenant, delegable set, protections).
 */
class DelegationTest extends TestCase
{
    use InteractsWithCentralGovernance;

    private const ACCESS = ['user.view', 'user.create', 'user.assign', 'user.update', 'role.view', 'role.create', 'role.update', 'role.assign_permission'];

    private const DELEGABLE = [
        ['permission' => 'optifleet.vehicle.view', 'name' => 'Vehicle: View', 'description' => null, 'capability' => 'optifleet.vehicle'],
        ['permission' => 'optifleet.vehicle.create', 'name' => 'Vehicle: Create', 'description' => null, 'capability' => 'optifleet.vehicle'],
        ['permission' => 'optifleet.branch.view', 'name' => 'Branch: View', 'description' => null, 'capability' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    /** @return array{0: Tenant, 1: User, 2: string} tenant, the Admin Tenant (signed in with OptiNexus), token */
    private function adminTenant(array $permissions = self::ACCESS): array
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [$admin, $token] = $this->makeCentralUser($tenant, $permissions);
        FakeNexusDelegation::seed($this->nexus, $tenant->optinexus_tenant_id, [$admin->optinexus_subject], self::DELEGABLE);

        return [$tenant, $admin, $token];
    }

    private function state(Tenant $tenant): array
    {
        return $this->nexus->delegation[$tenant->optinexus_tenant_id];
    }

    private function delegationCalls(): array
    {
        return array_values(array_filter($this->nexus->calls, fn (array $c) => str_contains($c['path'], '/delegation/')));
    }

    public function test_the_catalog_is_grouped_by_module_and_feature_with_the_assignable_roles(): void
    {
        [, , $token] = $this->adminTenant();

        $data = $this->getJson('/api/v1/app/delegation/catalog', $this->authHeaders($token))->assertOk()->json('data');

        $this->assertSame(3, $data['delegable_count']);
        $vehicle = collect($data['modules'])->firstWhere('code', 'VEHICLE');
        $this->assertNotNull($vehicle, 'the vehicle permissions are grouped under the Vehicle module');
        $keys = collect($vehicle['features'])->flatMap(fn ($f) => collect($f['permissions'])->pluck('key'))->all();
        $this->assertEqualsCanonicalizing(['optifleet.vehicle.create', 'optifleet.vehicle.view'], $keys);
        $this->assertNotEmpty($vehicle['features'][0]['name']);
        $this->assertSame([], $data['roles']);
    }

    public function test_the_acting_user_is_the_signed_in_person_never_something_the_request_says(): void
    {
        [$tenant, $admin, $token] = $this->adminTenant();

        $this->getJson('/api/v1/app/delegation/users?acting_user_id='.Str::uuid().'&tenant_id='.Str::uuid(), $this->authHeaders($token))->assertOk();
        $this->postJson('/api/v1/app/delegation/users', ['email' => 'a@example.test', 'name' => 'A', 'acting_user_id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid(), 'initial_password' => 'secret-password-123'], $this->authHeaders($token))->assertCreated();

        foreach ($this->delegationCalls() as $call) {
            $this->assertStringContainsString('/delegation/tenants/'.$tenant->optinexus_tenant_id, $call['path']);
            $this->assertSame($admin->optinexus_subject, $call['body']['acting_user_id'] ?? $call['query']['acting_user_id'] ?? null);
            $this->assertArrayNotHasKey('initial_password', $call['body'], 'OptiFleet never forwards or stores a password');
            $this->assertArrayNotHasKey('tenant_id', $call['body']);
        }
    }

    public function test_the_service_account_asks_for_the_least_scope(): void
    {
        [, , $token] = $this->adminTenant();

        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertOk();

        $scopes = collect($this->nexus->calls)->pluck('scope')->filter()->unique()->values()->all();
        $this->assertContains('tenant.delegate', $scopes);
    }

    public function test_a_person_who_is_not_an_admin_tenant_is_refused_with_a_translated_message_and_it_is_audited(): void
    {
        [$tenant] = $this->adminTenant();
        [$member, $token] = $this->makeCentralUser($tenant, self::ACCESS);
        $member->forceFill(['preferred_locale' => 'id'])->save(); // the person's own language decides first, as everywhere else

        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'ACTOR_NOT_TENANT_ADMIN')
            ->assertJsonPath('message', Messages::text('errors.delegation.actorNotTenantAdmin', [], 'id'))
            ->assertJsonPath('outcome_unknown', false);

        $this->assertNotSame(Messages::text('errors.delegation.actorNotTenantAdmin', [], 'en'), Messages::text('errors.delegation.actorNotTenantAdmin', [], 'id'));
        $this->postJson('/api/v1/app/delegation/users', ['email' => 'x@example.test', 'name' => 'X'], $this->authHeaders($token))->assertForbidden();
        // Every authorization refusal by OptiNexus is audited: the read above and the change here (outages are not: nothing was decided).
        $this->assertSame(2, AuditLog::query()->where('action', 'delegation.refused')->where('tenant_id', $tenant->id)->count());
        $this->assertNotNull($member);
    }

    public function test_the_fleet_permission_gate_comes_first_and_nexus_is_not_called(): void
    {
        [$tenant] = $this->adminTenant();
        [, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);

        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertForbidden();
        $this->postJson('/api/v1/app/delegation/roles', ['name' => 'R', 'permissions' => ['optifleet.vehicle.view']], $this->authHeaders($token))->assertForbidden();
        $this->assertSame([], $this->delegationCalls());
    }

    public function test_a_legacy_tenant_has_no_delegation(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        [$user] = $this->makeTenantUser($tenant, self::ACCESS);
        $token = $user->createToken('t', ['tenant:'.$tenant->id])->plainTextToken;

        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'NOT_CENTRALLY_GOVERNED');
        $this->assertSame([], $this->delegationCalls());
    }

    public function test_add_user_creates_once_then_reports_existing_and_audits_without_a_password(): void
    {
        [$tenant, , $token] = $this->adminTenant();

        $first = $this->postJson('/api/v1/app/delegation/users', ['email' => 'New.Person@Example.test', 'name' => 'New Person'], $this->authHeaders($token))->assertCreated();
        $first->assertJsonPath('data.outcome', 'created')->assertJsonPath('data.email', 'new.person@example.test');
        $this->postJson('/api/v1/app/delegation/users', ['email' => 'new.person@example.test', 'name' => 'New Person'], $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.outcome', 'existing');

        $this->assertCount(1, $this->state($tenant)['users']);
        $audit = AuditLog::query()->where('action', 'delegation.user_created')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('created', $audit->new_values['outcome']);
        $this->assertStringNotContainsString('password', json_encode($audit->new_values));
    }

    public function test_the_list_marks_people_who_already_signed_in_here_for_their_local_data_scope(): void
    {
        [$tenant, $admin, $token] = $this->adminTenant();
        $this->postJson('/api/v1/app/delegation/users', ['email' => 'late@example.test', 'name' => 'Late'], $this->authHeaders($token))->assertCreated();
        // The admin has a local projection (signed in here); the new person has not.
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['users'][$admin->optinexus_subject] = ['id' => $admin->optinexus_subject, 'name' => $admin->name, 'email' => $admin->email,
            'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => true, 'application_access' => 'ACTIVE', 'roles' => []];

        $rows = collect($this->getJson('/api/v1/app/delegation/users?per_page=50', $this->authHeaders($token))->assertOk()->json('data'));

        $this->assertSame($this->membership($admin, $tenant)->id, $rows->firstWhere('id', $admin->optinexus_subject)['local_member_id']);
        $this->assertNull($rows->firstWhere('email', 'late@example.test')['local_member_id']);
        $this->assertTrue($rows->firstWhere('id', $admin->optinexus_subject)['is_tenant_admin']);
    }

    public function test_role_lifecycle_through_the_delegable_set_only(): void
    {
        [$tenant, , $token] = $this->adminTenant();

        $created = $this->postJson('/api/v1/app/delegation/roles', ['name' => 'Clerk', 'description' => 'Reads vehicles', 'permissions' => ['optifleet.vehicle.view', 'optifleet.branch.view']], $this->authHeaders($token))
            ->assertCreated()->json('data');
        $this->assertTrue($created['editable']);

        // Not delegable: refused by OptiNexus with the offending key.
        $this->postJson('/api/v1/app/delegation/roles', ['name' => 'Boss', 'permissions' => ['optifleet.user.create']], $this->authHeaders($token))
            ->assertStatus(422)->assertJsonPath('code', 'PERMISSION_NOT_DELEGABLE')->assertJsonPath('details.permissions.0', 'optifleet.user.create');
        // Another application's key: refused by OptiFleet before any call.
        $before = count($this->delegationCalls());
        $this->postJson('/api/v1/app/delegation/roles', ['name' => 'Alien', 'permissions' => ['optiradar.map.view']], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['permissions.0']);
        $this->assertSame($before, count($this->delegationCalls()));

        $this->putJson('/api/v1/app/delegation/roles/'.$created['id'], ['name' => 'Senior clerk', 'permissions' => ['optifleet.vehicle.view', 'optifleet.vehicle.create']], $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.name', 'Senior clerk');
        $this->deleteJson('/api/v1/app/delegation/roles/'.$created['id'], [], $this->authHeaders($token))->assertOk();

        $this->assertSame([], $this->state($tenant)['roles']);
        $this->assertSame(['delegation.role_created', 'delegation.role_deleted', 'delegation.role_updated'], AuditLog::query()->where('tenant_id', $tenant->id)->where('action', 'like', 'delegation.role_%')->pluck('action')->sort()->values()->all());
    }

    public function test_changing_the_permissions_of_a_role_needs_the_assign_permission_right_too(): void
    {
        [$tenant, $admin] = $this->adminTenant();
        [$editor, $token] = $this->makeCentralUser($tenant, ['role.view', 'role.update']);
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['admins'][] = $editor->optinexus_subject;
        $id = (string) Str::uuid();
        $role = $this->nexus->delegation[$tenant->optinexus_tenant_id]['roles'][$id] = ['id' => $id, 'code' => 'R', 'name' => 'R', 'description' => null, 'template' => false, 'editable' => true, 'permissions' => ['optifleet.vehicle.view']];

        $this->putJson('/api/v1/app/delegation/roles/'.$id, ['name' => 'Renamed'], $this->authHeaders($token))->assertOk();
        $this->putJson('/api/v1/app/delegation/roles/'.$id, ['permissions' => ['optifleet.vehicle.create']], $this->authHeaders($token))->assertForbidden();
        $this->assertSame(['optifleet.vehicle.view'], $this->state($tenant)['roles'][$id]['permissions']);
        $this->assertNotNull($role);
        $this->assertNotNull($admin);
    }

    public function test_assigning_roles_and_refusals_are_mapped(): void
    {
        [$tenant, $admin, $token] = $this->adminTenant();
        $role = $this->postJson('/api/v1/app/delegation/roles', ['name' => 'Clerk', 'permissions' => ['optifleet.vehicle.view']], $this->authHeaders($token))->json('data');
        $person = $this->postJson('/api/v1/app/delegation/users', ['email' => 'p@example.test', 'name' => 'P'], $this->authHeaders($token))->json('data');

        $this->putJson('/api/v1/app/delegation/users/'.$person['id'].'/roles', ['role_ids' => [$role['id']]], $this->authHeaders($token))->assertOk()->assertJsonPath('data.roles.0.name', 'Clerk');
        $this->putJson('/api/v1/app/delegation/users/'.$person['id'].'/roles', ['role_ids' => [(string) Str::uuid()]], $this->authHeaders($token))->assertStatus(422)->assertJsonPath('code', 'ROLE_NOT_ASSIGNABLE');
        $this->putJson('/api/v1/app/delegation/users/'.$person['id'].'/roles', ['role_ids' => ['not-a-uuid']], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['role_ids.0']);

        // Role in use; the unknown user; the Admin Tenant; oneself.
        $this->deleteJson('/api/v1/app/delegation/roles/'.$role['id'], [], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'ROLE_IN_USE');
        $this->putJson('/api/v1/app/delegation/users/'.Str::uuid().'/roles', ['role_ids' => []], $this->authHeaders($token))->assertNotFound()->assertJsonPath('code', 'USER_NOT_IN_TENANT');
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['users'][$admin->optinexus_subject] = ['id' => $admin->optinexus_subject, 'name' => 'A', 'email' => 'a@example.test',
            'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => true, 'application_access' => 'ACTIVE', 'roles' => []];
        $this->deleteJson('/api/v1/app/delegation/users/'.$admin->optinexus_subject, [], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'TENANT_ADMIN_PROTECTED');

        $this->deleteJson('/api/v1/app/delegation/users/'.$person['id'], [], $this->authHeaders($token))->assertOk();
        $this->assertArrayNotHasKey($person['id'], $this->state($tenant)['users']);
        $this->assertSame(1, AuditLog::query()->where('action', 'delegation.user_access_revoked')->where('tenant_id', $tenant->id)->count());
    }

    public function test_an_admin_cannot_remove_their_own_access_and_operator_identities_cannot_be_added(): void
    {
        [$tenant, $admin, $token] = $this->adminTenant();
        // A second administrator is not protected by TENANT_ADMIN_PROTECTED in the fake only when not flagged: flag the actor as a plain user.
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['users'][$admin->optinexus_subject] = ['id' => $admin->optinexus_subject, 'name' => 'A', 'email' => 'a@example.test',
            'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'application_access' => 'ACTIVE', 'roles' => []];
        $this->deleteJson('/api/v1/app/delegation/users/'.$admin->optinexus_subject, [], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'SELF_REVOCATION_DENIED');

        $this->nexus->delegation[$tenant->optinexus_tenant_id]['operators'] = ['operator@example.test'];
        $this->postJson('/api/v1/app/delegation/users', ['email' => 'operator@example.test', 'name' => 'Op'], $this->authHeaders($token))->assertStatus(409)->assertJsonPath('code', 'IDENTITY_NOT_LINKABLE');
    }

    public function test_a_change_drops_the_cached_access_of_the_people_it_affects(): void
    {
        [$tenant, , $token] = $this->adminTenant();
        [$member, $memberToken] = $this->makeCentralUser($tenant, ['vehicle.view']);
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($memberToken))->assertOk();
        $before = $this->nexus->callsTo('effective-permissions');

        // The person is removed in OptiNexus (the fake keeps its own state for effective permissions: withdraw it as OptiNexus would).
        $this->nexus->delegation[$tenant->optinexus_tenant_id]['users'][$member->optinexus_subject] = ['id' => $member->optinexus_subject, 'name' => 'M', 'email' => 'm@example.test',
            'status' => 'ACTIVE', 'membership_status' => 'ACTIVE', 'is_tenant_admin' => false, 'application_access' => 'ACTIVE', 'roles' => []];
        $this->nexus->withdraw($tenant->optinexus_tenant_id, $member->optinexus_subject);
        $this->deleteJson('/api/v1/app/delegation/users/'.$member->optinexus_subject, [], $this->authHeaders($token))->assertOk();

        // Not "within one TTL": the very next request already sees the withdrawal.
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($memberToken))->assertUnauthorized()->assertJsonPath('code', 'SESSION_ENDED_CENTRALLY');
        $this->assertGreaterThan($before, $this->nexus->callsTo('effective-permissions') + $this->nexus->callsTo('product-context'));
    }

    public function test_an_outage_is_a_503_that_tells_whether_a_change_may_have_been_applied(): void
    {
        [, , $token] = $this->adminTenant();
        // The first call warms the (short lived) access answer, so the gate is passed and only the delegation call fails.
        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertOk();
        $this->nexus->mode = 'down';

        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))
            ->assertStatus(503)->assertJsonPath('code', 'DELEGATION_UNAVAILABLE')->assertJsonPath('retryable', true)->assertJsonPath('outcome_unknown', false)
            ->assertJsonPath('message', Messages::text('errors.delegation.unavailable', [], 'en'));

        $this->postJson('/api/v1/app/delegation/users', ['email' => 'z@example.test', 'name' => 'Z'], $this->authHeaders($token))
            ->assertStatus(503)->assertJsonPath('code', 'DELEGATION_UNAVAILABLE')->assertJsonPath('outcome_unknown', true)
            ->assertJsonPath('message', Messages::text('errors.delegation.unavailableChange', [], 'en'));
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'delegation.%')->count(), 'an outage decided nothing: no audit entry for a refusal');
    }

    public function test_every_documented_code_has_a_status_and_a_message_in_both_languages(): void
    {
        $documented = ['ACTOR_NOT_TENANT_ADMIN', 'PERMISSION_NOT_DELEGABLE', 'ROLE_NOT_ASSIGNABLE', 'ROLE_NOT_EDITABLE', 'ROLE_IN_USE', 'USER_NOT_IN_TENANT',
            'TENANT_ADMIN_PROTECTED', 'IDENTITY_NOT_LINKABLE', 'MEMBERSHIP_NOT_ACTIVE', 'SELF_REVOCATION_DENIED', 'TENANT_MISMATCH', 'SERVICE_ACCOUNT_NOT_BOUND',
            'APPLICATION_NOT_DELEGABLE', 'APPLICATION_NOT_SUBSCRIBED', 'TENANT_NOT_ACTIVE', 'APPLICATION_NOT_ACTIVE'];

        foreach ($documented as $code) {
            $this->assertArrayHasKey($code, DelegationErrors::MAP, "{$code} is not mapped");
            $e = DelegationErrors::fromResponse(['status' => 409, 'error' => ['code' => $code, 'message' => 'x', 'details' => null], 'correlation_id' => 'c-1'], true);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame('c-1', $e->correlationId);
        }

        foreach (DelegationErrors::messageKeys() as $key) {
            $en = Messages::text($key, [], 'en');
            $id = Messages::text($key, [], 'id');
            $this->assertNotSame($key, $en, "{$key} has no English text");
            $this->assertNotSame($key, $id, "{$key} has no Indonesian text");
            $this->assertNotSame($en, $id, "{$key} is not translated");
        }
    }

    public function test_misconfiguration_of_the_service_account_is_not_shown_as_the_users_fault(): void
    {
        [, , $token] = $this->adminTenant();
        $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertOk();

        foreach (['unbound' => 'SERVICE_ACCOUNT_NOT_BOUND', 'tenant_mismatch' => 'TENANT_MISMATCH'] as $mode => $code) {
            $this->nexus->mode = $mode;
            $this->getJson('/api/v1/app/delegation/users', $this->authHeaders($token))->assertStatus(503)->assertJsonPath('code', $code)->assertJsonPath('retryable', true);
        }
        $this->assertInstanceOf(FakeNexus::class, $this->nexus);
    }
}
