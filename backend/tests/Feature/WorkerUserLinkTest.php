<?php

namespace Tests\Feature;

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
