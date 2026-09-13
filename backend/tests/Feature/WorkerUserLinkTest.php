<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Role;
use App\Domain\Identity\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-14: workers.user_id existed since Phase 4 but had no
 * relation and no endpoint to ever set it — it was fully dead.
 */
class WorkerUserLinkTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WUL-'.Str::random(4)]);
        $this->grantModule($tenant, 'WORKSHOP');
        [, $token] = $this->makeTenantUser($tenant, ['worker.view', 'worker.manage']);
        $branch = $this->makeBranch($tenant);
        $worker = $this->makeWorker($tenant, $branch);

        return [$tenant, $token, $worker];
    }

    public function test_worker_can_be_linked_to_an_active_tenant_member(): void
    {
        [$tenant, $token, $worker] = $this->setUpTenant();
        [$loginUser] = $this->makeTenantUser($tenant, []);

        $response = $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", [
            'user_id' => $loginUser->id,
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame($loginUser->id, $response->json('data.user_id'));
        $this->assertSame($loginUser->id, $worker->fresh()->user_id);
    }

    public function test_worker_contact_and_rate_fields_are_optional_and_stored(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $branch = $this->makeBranch($tenant);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/workers', [
            'employee_code' => 'EMP-1', 'name' => 'Budi', 'branch_id' => $branch->id, 'worker_type' => 'MECHANIC',
            'phone' => '081234567890', 'email' => 'budi@example.test', 'monthly_rate' => 5000000, 'hourly_rate' => 50000,
        ], $headers)->assertStatus(201);

        $this->assertSame('081234567890', $create->json('data.phone'));
        $this->assertSame('budi@example.test', $create->json('data.email'));

        $id = $create->json('data.id');
        $this->putJson("/api/v1/app/workers/{$id}", ['address' => 'Jl. Contoh No. 5'], $headers)
            ->assertOk()->assertJsonPath('data.address', 'Jl. Contoh No. 5');
    }

    /**
     * Final reconciliation (queued ADJUST): the frontend "Add User" flow now
     * chains create-user -> link-worker -> assign-role as a convenience —
     * this proves the three already-independent endpoints compose correctly
     * end-to-end in that exact sequence, using the exact response shapes
     * (`data.user.id`, `data.membership.id`) the frontend reads.
     */
    public function test_user_creation_can_be_chained_with_worker_link_and_role_assignment(): void
    {
        [$tenant, $token, $worker] = $this->setUpTenant();
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Mechanic', 'scope' => 'tenant', 'is_system' => false]);
        [, $adminToken] = $this->makeTenantUser($tenant, ['user.create', 'worker.manage', 'user.assign']);
        $headers = $this->authHeaders($adminToken);

        $create = $this->postJson('/api/v1/app/users', [
            'name' => 'Budi Santoso', 'email' => 'budi.santoso@example.test', 'password' => 'password123',
        ], $headers)->assertStatus(201);

        $userId = $create->json('data.user.id');
        $membershipId = $create->json('data.membership.id');
        $this->assertNotEmpty($userId);
        $this->assertNotEmpty($membershipId);

        $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", ['user_id' => $userId], $headers)
            ->assertOk()->assertJsonPath('data.user_id', $userId);

        $this->postJson("/api/v1/app/users/{$membershipId}/roles", ['role_id' => $role->id], $headers)->assertOk();

        $list = $this->getJson('/api/v1/app/users', $headers)->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $membershipId);
        $this->assertContains('Mechanic', $row['roles']);
    }

    public function test_worker_cannot_be_linked_to_a_user_outside_the_tenant(): void
    {
        [, $token, $worker] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'WULB-'.Str::random(4)]);
        [$foreignUser] = $this->makeTenantUser($otherTenant, []);

        $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", [
            'user_id' => $foreignUser->id,
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_worker_cannot_be_linked_to_a_user_with_no_tenant_membership_at_all(): void
    {
        [, $token, $worker] = $this->setUpTenant();
        $unaffiliatedUser = User::query()->create([
            'name' => 'Unaffiliated', 'email' => Str::random(8).'@optifleet.test', 'password' => 'password', 'user_type' => 'tenant', 'status' => 'active',
        ]);

        $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", [
            'user_id' => $unaffiliatedUser->id,
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_worker_cannot_be_linked_to_an_inactive_tenant_membership(): void
    {
        [$tenant, $token, $worker] = $this->setUpTenant();
        [$inactiveUser] = $this->makeTenantUser($tenant, []);
        TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $inactiveUser->id)->update(['status' => 'inactive']);

        $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", [
            'user_id' => $inactiveUser->id,
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_worker_user_link_can_be_removed(): void
    {
        [$tenant, $token, $worker] = $this->setUpTenant();
        [$loginUser] = $this->makeTenantUser($tenant, []);
        $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", ['user_id' => $loginUser->id], $this->authHeaders($token))->assertOk();

        $this->postJson("/api/v1/app/workers/{$worker->id}/unlink-user", [], $this->authHeaders($token))->assertOk()
            ->assertJsonPath('data.user_id', null);

        $this->assertNull($worker->fresh()->user_id);
    }

    public function test_linking_a_worker_from_another_tenant_is_denied(): void
    {
        [, $token, $worker] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'WULC-'.Str::random(4)]);
        $this->grantModule($otherTenant, 'WORKSHOP');
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['worker.view', 'worker.manage']);
        [$otherLoginUser] = $this->makeTenantUser($otherTenant, []);

        $this->postJson("/api/v1/app/workers/{$worker->id}/link-user", [
            'user_id' => $otherLoginUser->id,
        ], $this->authHeaders($otherToken))->assertStatus(404);
    }
}
