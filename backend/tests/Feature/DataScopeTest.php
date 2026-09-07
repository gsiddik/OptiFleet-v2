<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use Tests\TestCase;

class DataScopeTest extends TestCase
{
    public function test_branch_scoped_user_only_sees_assigned_branch(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');

        $branch1 = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B1', 'name' => 'Branch 1', 'status' => 'ACTIVE']);
        $branch2 = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B2', 'name' => 'Branch 2', 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['branch.view'], ['BRANCH' => $branch1->id]);

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('DS-B1'));
        $this->assertFalse($codes->contains('DS-B2'));
    }

    public function test_branch_scoped_user_cannot_view_other_branch_directly(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');

        $branch1 = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B3', 'name' => 'Branch 3', 'status' => 'ACTIVE']);
        $branch2 = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B4', 'name' => 'Branch 4', 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['branch.view'], ['BRANCH' => $branch1->id]);

        $this->getJson("/api/v1/app/branches/{$branch2->id}", $this->authHeaders($token))->assertStatus(403);
        $this->getJson("/api/v1/app/branches/{$branch1->id}", $this->authHeaders($token))->assertOk();
    }

    public function test_branch_scope_cascades_to_workshops_and_warehouses(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');

        $branch1 = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B5', 'name' => 'Branch 5', 'status' => 'ACTIVE']);
        $branch2 = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B6', 'name' => 'Branch 6', 'status' => 'ACTIVE']);

        $workshop1 = Workshop::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch1->id, 'code' => 'DS-W1', 'name' => 'WS1', 'status' => 'ACTIVE']);
        $workshop2 = Workshop::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch2->id, 'code' => 'DS-W2', 'name' => 'WS2', 'status' => 'ACTIVE']);

        Warehouse::query()->create(['tenant_id' => $tenant->id, 'workshop_id' => $workshop1->id, 'code' => 'DS-WH1', 'name' => 'WH1', 'status' => 'ACTIVE']);
        Warehouse::query()->create(['tenant_id' => $tenant->id, 'workshop_id' => $workshop2->id, 'code' => 'DS-WH2', 'name' => 'WH2', 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['workshop.view', 'warehouse.view'], ['BRANCH' => $branch1->id]);

        $workshops = $this->getJson('/api/v1/app/workshops', $this->authHeaders($token))->assertOk();
        $wsCodes = collect($workshops->json('data'))->pluck('code');
        $this->assertTrue($wsCodes->contains('DS-W1'));
        $this->assertFalse($wsCodes->contains('DS-W2'));

        $warehouses = $this->getJson('/api/v1/app/warehouses', $this->authHeaders($token))->assertOk();
        $whCodes = collect($warehouses->json('data'))->pluck('code');
        $this->assertTrue($whCodes->contains('DS-WH1'));
        $this->assertFalse($whCodes->contains('DS-WH2'));
    }

    public function test_tenant_scope_grants_unrestricted_access(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');

        Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B7', 'name' => 'Branch 7', 'status' => 'ACTIVE']);
        Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'DS-B8', 'name' => 'Branch 8', 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['branch.view'], ['TENANT' => null]);

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $this->assertCount(2, $response->json('data'));
    }
}
