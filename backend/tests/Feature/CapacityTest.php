<?php

namespace Tests\Feature;

use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Organization\Models\Branch;
use Tests\TestCase;

class CapacityTest extends TestCase
{
    public function test_branch_creation_blocked_when_limit_reached(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        TenantCapacityLimit::query()->create(['tenant_id' => $tenant->id, 'resource_type' => 'branch', 'max_count' => 1]);

        Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'CAP-1', 'name' => 'Cap 1', 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['branch.create']);

        $response = $this->postJson('/api/v1/app/branches', [
            'code' => 'CAP-2',
            'name' => 'Cap 2',
        ], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertSame(1, Branch::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_branch_creation_allowed_under_limit(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        TenantCapacityLimit::query()->create(['tenant_id' => $tenant->id, 'resource_type' => 'branch', 'max_count' => 5]);

        [, $token] = $this->makeTenantUser($tenant, ['branch.create']);

        $response = $this->postJson('/api/v1/app/branches', [
            'code' => 'CAP-3',
            'name' => 'Cap 3',
        ], $this->authHeaders($token));

        $response->assertStatus(201);
    }

    public function test_no_configured_limit_means_unrestricted(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        // no TenantCapacityLimit row at all

        [, $token] = $this->makeTenantUser($tenant, ['branch.create']);

        $response = $this->postJson('/api/v1/app/branches', [
            'code' => 'CAP-4',
            'name' => 'Cap 4',
        ], $this->authHeaders($token));

        $response->assertStatus(201);
    }

    public function test_closed_branches_do_not_count_against_limit(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        TenantCapacityLimit::query()->create(['tenant_id' => $tenant->id, 'resource_type' => 'branch', 'max_count' => 1]);

        Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'CAP-5', 'name' => 'Cap 5', 'status' => 'CLOSED']);

        [, $token] = $this->makeTenantUser($tenant, ['branch.create']);

        $response = $this->postJson('/api/v1/app/branches', [
            'code' => 'CAP-6',
            'name' => 'Cap 6',
        ], $this->authHeaders($token));

        $response->assertStatus(201);
    }
}
