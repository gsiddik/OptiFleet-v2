<?php

namespace Tests\Feature;

use App\Domain\Workshop\Models\WorkerType;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal" (Mechanic): Worker Type as real,
 * tenant-manageable master data. Worker create/update/filter now go
 * through worker_type_id, with the legacy worker_type enum column
 * mirrored automatically when it maps to one of the 5 original values —
 * and, critically, the workerTypeMaster relation must never collide
 * with (and silently overwrite) that legacy string column in the JSON
 * response.
 */
class WorkerTypeWiringTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WTW-'.Str::random(4)]);
        $this->grantModule($tenant, 'WORKSHOP');
        [, $token] = $this->makeTenantUser($tenant, ['worker.view', 'worker.manage']);
        $branch = $this->makeBranch($tenant);

        return [$tenant, $token, $branch];
    }

    public function test_worker_can_be_created_with_worker_type_id_and_legacy_type_is_mirrored(): void
    {
        [$tenant, $token, $branch] = $this->setUpTenant();
        $mechanicType = WorkerType::query()->where('code', 'MECHANIC')->whereNull('tenant_id')->firstOrFail();

        $response = $this->postJson('/api/v1/app/workers', [
            'employee_code' => 'EMP-1', 'name' => 'Budi', 'branch_id' => $branch->id, 'worker_type_id' => $mechanicType->id,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame($mechanicType->id, $response->json('data.worker_type_id'));
        $this->assertSame('MECHANIC', $response->json('data.worker_type'));
        $this->assertSame($mechanicType->id, $response->json('data.worker_type_master.id'));
        $this->assertSame('Mechanic', $response->json('data.worker_type_master.name'));
    }

    public function test_worker_type_master_relation_does_not_overwrite_legacy_worker_type_string(): void
    {
        [$tenant, $token, $branch] = $this->setUpTenant();
        $mechanicType = WorkerType::query()->where('code', 'MECHANIC')->whereNull('tenant_id')->firstOrFail();

        $created = $this->postJson('/api/v1/app/workers', [
            'employee_code' => 'EMP-2', 'name' => 'Andi', 'branch_id' => $branch->id, 'worker_type_id' => $mechanicType->id,
        ], $this->authHeaders($token))->assertStatus(201)->json('data');

        // show() eager-loads workerTypeMaster — confirm the legacy string column survives.
        $response = $this->getJson("/api/v1/app/workers/{$created['id']}", $this->authHeaders($token))->assertOk();
        $this->assertSame('MECHANIC', $response->json('data.worker_type'));
        $this->assertIsArray($response->json('data.worker_type_master'));
    }

    public function test_worker_can_be_created_with_a_custom_tenant_defined_type(): void
    {
        [$tenant, $token, $branch] = $this->setUpTenant();
        $customType = WorkerType::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'DETAILER', 'name' => 'Detailer', 'is_system' => false, 'status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/app/workers', [
            'employee_code' => 'EMP-3', 'name' => 'Citra', 'branch_id' => $branch->id, 'worker_type_id' => $customType->id,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame($customType->id, $response->json('data.worker_type_id'));
        $this->assertSame('Detailer', $response->json('data.worker_type_master.name'));
        // Not one of the 5 legacy codes — the legacy column is left untouched at its DB default.
        $this->assertSame('MECHANIC', $response->json('data.worker_type'));
    }

    public function test_worker_list_can_be_filtered_by_worker_type_id(): void
    {
        [$tenant, $token, $branch] = $this->setUpTenant();
        $mechanicType = WorkerType::query()->where('code', 'MECHANIC')->whereNull('tenant_id')->firstOrFail();
        $inspectorType = WorkerType::query()->where('code', 'INSPECTOR')->whereNull('tenant_id')->firstOrFail();
        $this->makeWorker($tenant, $branch, null, ['employee_code' => 'EMP-M', 'worker_type' => 'MECHANIC', 'worker_type_id' => $mechanicType->id]);
        $this->makeWorker($tenant, $branch, null, ['employee_code' => 'EMP-I', 'worker_type' => 'INSPECTOR', 'worker_type_id' => $inspectorType->id]);

        $response = $this->getJson('/api/v1/app/workers?worker_type_id='.$mechanicType->id, $this->authHeaders($token))->assertOk();
        $codes = collect($response->json('data'))->pluck('employee_code');

        $this->assertTrue($codes->contains('EMP-M'));
        $this->assertFalse($codes->contains('EMP-I'));
    }

    public function test_worker_update_can_change_worker_type_id(): void
    {
        [$tenant, $token, $branch] = $this->setUpTenant();
        $mechanicType = WorkerType::query()->where('code', 'MECHANIC')->whereNull('tenant_id')->firstOrFail();
        $inspectorType = WorkerType::query()->where('code', 'INSPECTOR')->whereNull('tenant_id')->firstOrFail();
        $worker = $this->makeWorker($tenant, $branch, null, ['worker_type' => 'MECHANIC', 'worker_type_id' => $mechanicType->id]);

        $response = $this->putJson("/api/v1/app/workers/{$worker->id}", [
            'worker_type_id' => $inspectorType->id,
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame('INSPECTOR', $response->json('data.worker_type'));
        $this->assertSame($inspectorType->id, $response->json('data.worker_type_id'));
    }

    public function test_worker_type_id_must_belong_to_this_tenant_or_be_system(): void
    {
        [$tenant, $token, $branch] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'WTW-OTHER-'.Str::random(4)]);
        $otherType = WorkerType::query()->create([
            'tenant_id' => $otherTenant->id, 'code' => 'FOREMAN', 'name' => 'Foreman', 'is_system' => false, 'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/v1/app/workers', [
            'employee_code' => 'EMP-4', 'name' => 'Dedi', 'branch_id' => $branch->id, 'worker_type_id' => $otherType->id,
        ], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['worker_type_id']);
    }
}
