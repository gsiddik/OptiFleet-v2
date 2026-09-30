<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal - Products" Phase 1: the new
 * foundation/master-data resources that back the future Dynamic Product
 * Form (Worker Type, Tool Type, Equipment Type, Storage Requirement, the
 * five Tire reference tables) plus the Product Item Code auto-numbering
 * that replaces client-typed `code`.
 */
class ProductFoundationMasterDataTest extends TestCase
{
    private function setUpTenant(array $permissions): array
    {
        $tenant = $this->makeTenant(['code' => 'PFM-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'WORKSHOP');
        [, $token] = $this->makeTenantUser($tenant, $permissions);

        return [$tenant, $token];
    }

    public function test_product_item_code_is_server_generated_sequential_and_not_client_overridable(): void
    {
        [$tenant, $token] = $this->setUpTenant(['product.view', 'product.create']);
        $headers = $this->authHeaders($token);
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        $bin = $this->makeWarehouseBin($tenant);

        $sparepartSpec = [
            'part_number' => 'PN-'.Str::random(4), 'part_type' => 'GENUINE',
            'compatibilities' => [$this->vehicleFit()],
        ];

        $first = $this->postJson('/api/v1/app/products', [
            'code' => 'CLIENT-SUPPLIED-SHOULD-BE-IGNORED',
            ...$this->componentClassification(), 'name' => 'Item A',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
            'default_storage_bin_id' => $bin->id,
            'brand' => 'Bosch', 'track_serial_number' => false, 'spec' => $sparepartSpec,
        ], $headers)->assertStatus(201);

        $second = $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Item B',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
            'default_storage_bin_id' => $bin->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-'.Str::random(4), 'part_type' => 'GENUINE', 'compatibilities' => [$this->vehicleFit()]],
        ], $headers)->assertStatus(201);

        $firstCode = $first->json('data.code');
        $secondCode = $second->json('data.code');

        $this->assertNotSame('CLIENT-SUPPLIED-SHOULD-BE-IGNORED', $firstCode);
        $this->assertStringStartsWith('ITM/', $firstCode);
        $this->assertNotSame($firstCode, $secondCode);
        $this->assertNotNull($first->json('data.id'));

        $this->assertDatabaseHas('products', ['id' => $first->json('data.id'), 'code' => $firstCode]);
        $this->assertNotNull(\App\Domain\ProductMaster\Models\Product::query()->find($first->json('data.id'))->numbering_configuration_version_id);
    }

    public function test_product_type_accepts_rim(): void
    {
        [$tenant, $token] = $this->setUpTenant(['product.view', 'product.create']);
        $headers = $this->authHeaders($token);
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        $bin = $this->makeWarehouseBin($tenant);

        $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Alloy Rim',
            'product_category_id' => $category->id, 'product_type' => 'RIM', 'uom_id' => $uom->id,
            'default_storage_bin_id' => $bin->id,
            'brand' => 'Enkei', 'track_serial_number' => true,
            'spec' => [
                'rim_type' => 'ALLOY', 'diameter_inch' => 17.5, 'width_inch' => 6.0, 'bolt_holes' => 6, 'pcd_mm' => 139.7,
            ],
        ], $headers)->assertStatus(201)->assertJsonPath('data.product_type', 'RIM');
    }

    public function test_worker_type_crud_and_worker_assignment(): void
    {
        [$tenant, $token] = $this->setUpTenant(['worker.view', 'worker.manage']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/worker-types', ['code' => 'ALIGN-TECH', 'name' => 'Alignment Technician'], $headers)
            ->assertStatus(201);
        $id = $create->json('data.id');

        $this->putJson("/api/v1/app/worker-types/{$id}", ['name' => 'Wheel Alignment Technician'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Wheel Alignment Technician');

        $list = $this->getJson('/api/v1/app/worker-types', $headers)->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('id', $id));

        // System-seeded worker types from the backfill migration are visible and immutable.
        $systemType = \App\Domain\Workshop\Models\WorkerType::query()->where('code', 'MECHANIC')->where('tenant_id', null)->firstOrFail();
        $this->assertTrue(collect($list->json('data'))->contains('id', $systemType->id));
        $this->putJson("/api/v1/app/worker-types/{$systemType->id}", ['name' => 'Hacked'], $headers)->assertStatus(403);

        $this->deleteJson("/api/v1/app/worker-types/{$id}", [], $headers)->assertOk();
        $this->assertSoftDeleted('worker_types', ['id' => $id]);
    }

    public function test_worker_type_in_use_cannot_be_deleted(): void
    {
        [$tenant, $token] = $this->setUpTenant(['worker.view', 'worker.manage']);
        $branch = $this->makeBranch($tenant);
        $workerType = \App\Domain\Workshop\Models\WorkerType::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'IN-USE', 'name' => 'In Use', 'is_system' => false, 'status' => 'ACTIVE',
        ]);
        $this->makeWorker($tenant, $branch, null, ['worker_type_id' => $workerType->id]);

        $this->deleteJson("/api/v1/app/worker-types/{$workerType->id}", [], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_tool_equipment_and_storage_requirement_master_data_crud(): void
    {
        [, $token] = $this->setUpTenant(['product.view', 'product.create', 'product.update', 'product.delete']);
        $headers = $this->authHeaders($token);

        foreach ([
            ['tool-types', 'IMPACT-WRENCH', 'Impact Wrench'],
            ['equipment-types', 'LIFT', 'Vehicle Lift'],
            ['storage-requirements', 'DRY', 'Dry Storage'],
        ] as [$path, $code, $name]) {
            $create = $this->postJson("/api/v1/app/{$path}", ['code' => $code, 'name' => $name], $headers)->assertStatus(201);
            $id = $create->json('data.id');

            $this->putJson("/api/v1/app/{$path}/{$id}", ['name' => $name.' (Updated)'], $headers)
                ->assertOk()->assertJsonPath('data.name', $name.' (Updated)');

            $this->getJson("/api/v1/app/{$path}", $headers)->assertOk();

            $this->deleteJson("/api/v1/app/{$path}/{$id}", [], $headers)->assertOk();
        }
    }

    public function test_tire_reference_master_data_crud(): void
    {
        [, $token] = $this->setUpTenant(['product.view', 'product.create', 'product.update', 'product.delete']);
        $headers = $this->authHeaders($token);

        $loadIndex = $this->postJson('/api/v1/app/tire-load-indices', [
            'code' => '92', 'max_load_single_kg' => 630, 'max_load_dual_kg' => 580,
        ], $headers)->assertStatus(201);
        $this->assertSame('630.00', $loadIndex->json('data.max_load_single_kg'));

        $speedRating = $this->postJson('/api/v1/app/tire-speed-ratings', ['code' => 'T', 'max_speed_kmh' => 190], $headers)
            ->assertStatus(201);
        $this->assertNotNull($speedRating->json('data.id'));

        $plyRating = $this->postJson('/api/v1/app/tire-ply-ratings', ['code' => '16 PR', 'load_range' => 'H'], $headers)
            ->assertStatus(201);
        $this->assertSame('H', $plyRating->json('data.load_range'));

        $this->putJson("/api/v1/app/tire-load-indices/{$loadIndex->json('data.id')}", ['max_load_single_kg' => 650], $headers)
            ->assertOk()->assertJsonPath('data.max_load_single_kg', '650.00');

        $this->deleteJson("/api/v1/app/tire-speed-ratings/{$speedRating->json('data.id')}", [], $headers)->assertOk();
        $this->assertSoftDeleted('tire_speed_ratings', ['id' => $speedRating->json('data.id')]);
    }

    public function test_tire_tra_code_with_nested_star_ratings(): void
    {
        [, $token] = $this->setUpTenant(['product.view', 'product.create', 'product.update', 'product.delete']);
        $headers = $this->authHeaders($token);

        $traCode = $this->postJson('/api/v1/app/tire-tra-codes', ['code' => 'G2', 'profile' => 'G'], $headers)->assertStatus(201);
        $traCodeId = $traCode->json('data.id');

        $star = $this->postJson("/api/v1/app/tire-tra-codes/{$traCodeId}/star-ratings", [
            'star_rating' => '2', 'purpose' => 'General Purpose',
        ], $headers)->assertStatus(201);
        $starId = $star->json('data.id');

        $show = $this->getJson("/api/v1/app/tire-tra-codes/{$traCodeId}", $headers)->assertOk();
        $this->assertCount(1, $show->json('data.star_ratings'));

        $this->putJson("/api/v1/app/tire-tra-codes/{$traCodeId}/star-ratings/{$starId}", ['purpose' => 'On/Off Road'], $headers)
            ->assertOk()->assertJsonPath('data.purpose', 'On/Off Road');

        // A duplicate star rating for the same TRA code is rejected.
        $this->postJson("/api/v1/app/tire-tra-codes/{$traCodeId}/star-ratings", ['star_rating' => '2'], $headers)
            ->assertStatus(422);

        $this->deleteJson("/api/v1/app/tire-tra-codes/{$traCodeId}/star-ratings/{$starId}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('tire_tra_star_ratings', ['id' => $starId]);
    }

    public function test_uom_measure_type_is_stored_and_optional(): void
    {
        [, $token] = $this->setUpTenant(['product.view', 'product.create']);
        $headers = $this->authHeaders($token);

        $withType = $this->postJson('/api/v1/app/uoms', ['code' => 'MM', 'name' => 'Millimeter', 'measure_type' => 'LENGTH'], $headers)
            ->assertStatus(201);
        $this->assertSame('LENGTH', $withType->json('data.measure_type'));

        $this->postJson('/api/v1/app/uoms', ['code' => 'PCS', 'name' => 'Pieces'], $headers)
            ->assertStatus(201)->assertJsonPath('data.measure_type', null);
    }

    public function test_new_master_data_resources_are_tenant_isolated(): void
    {
        [, $token] = $this->setUpTenant(['worker.view', 'worker.manage', 'product.view', 'product.create', 'product.update']);
        $otherTenant = $this->makeTenant(['code' => 'PFMB-'.Str::random(4)]);
        $foreignWorkerType = \App\Domain\Workshop\Models\WorkerType::query()->create([
            'tenant_id' => $otherTenant->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'is_system' => false, 'status' => 'ACTIVE',
        ]);
        $foreignToolType = \App\Domain\ProductMaster\Models\ToolType::query()->create([
            'tenant_id' => $otherTenant->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'is_system' => false, 'status' => 'ACTIVE',
        ]);

        $headers = $this->authHeaders($token);
        $this->putJson("/api/v1/app/worker-types/{$foreignWorkerType->id}", ['name' => 'X'], $headers)->assertStatus(404);
        $this->putJson("/api/v1/app/tool-types/{$foreignToolType->id}", ['name' => 'X'], $headers)->assertStatus(404);
    }
}
