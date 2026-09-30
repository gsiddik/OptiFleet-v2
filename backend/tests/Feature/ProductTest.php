<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\ProductCompatibility;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductTest extends TestCase
{
    public function test_product_crud_works(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRD-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        $bin = $this->makeWarehouseBin($tenant);
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Brake Pad Set',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
            'default_storage_bin_id' => $bin->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => [
                'part_number' => 'BRK-PAD-01', 'part_type' => 'GENUINE',
                'compatibilities' => [$this->vehicleFit()],
            ],
        ], $headers)->assertStatus(201);
        $productId = $create->json('data.id');

        $this->getJson("/api/v1/app/products/{$productId}", $headers)->assertOk()->assertJsonPath('data.name', 'Brake Pad Set');

        $this->putJson("/api/v1/app/products/{$productId}", ['name' => 'Brake Pad Set (Front)'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Brake Pad Set (Front)');

        $list = $this->getJson('/api/v1/app/products', $headers)->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains('id', $productId));
    }

    public function test_product_spec_fields_are_optional_and_stored(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDS-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        $bin = $this->makeWarehouseBin($tenant);
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'Brake Pad Set',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
            'default_storage_bin_id' => $bin->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'manufacturer' => 'Bosch', 'material' => 'Ceramic', 'production_year' => 2024,
            'spec' => [
                'part_number' => 'BRK-PAD-02', 'part_type' => 'GENUINE',
                'compatibilities' => [$this->vehicleFit()],
            ],
        ], $headers)->assertStatus(201);

        $this->assertSame('Bosch', $create->json('data.manufacturer'));
        $this->assertSame('Ceramic', $create->json('data.material'));
        $this->assertSame(2024, $create->json('data.production_year'));

        $id = $create->json('data.id');
        $this->putJson("/api/v1/app/products/{$id}", [
            'weight_kg' => 1.5, 'length_mm' => 200, 'width_mm' => 100, 'height_mm' => 50,
            'image_url' => 'https://files.example/products/brake-pad.jpg',
        ], $headers)->assertOk()->assertJsonPath('data.image_url', 'https://files.example/products/brake-pad.jpg');
    }

    /**
     * "Next Improvement Tenant Portal - Products" (gap-correction cycle):
     * Default Storage Location is Mandatory for every NEW Product — the
     * document's earlier "Optional for now" implementation gap is closed
     * here. Backend validation is authoritative regardless of frontend UX.
     */
    public function test_default_storage_location_is_mandatory_for_new_products(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDN-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        [, $token] = $this->makeTenantUser($tenant, ['product.create']);

        $this->postJson('/api/v1/app/products', [
            ...$this->componentClassification(), 'name' => 'No Bin Item',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => [
                'part_number' => 'PN-NOBIN', 'part_type' => 'GENUINE',
                'compatibilities' => [$this->vehicleFit()],
            ],
        ], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['default_storage_bin_id']);
    }

    /**
     * A pre-existing Product created before this rule became mandatory may
     * still carry a NULL default_storage_bin_id — no destructive database
     * backfill was performed. It must remain readable, and an unrelated
     * partial update (e.g. renaming) must not be blocked by the missing
     * value. Only once the caller explicitly supplies the field must it
     * resolve to a real bin.
     */
    public function test_legacy_product_with_null_storage_location_remains_readable_and_editable(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDL2-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $legacyProduct = $this->makeProduct($tenant, null, null, ['default_storage_bin_id' => null]);
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.update']);
        $headers = $this->authHeaders($token);

        $this->getJson("/api/v1/app/products/{$legacyProduct->id}", $headers)
            ->assertOk()->assertJsonPath('data.default_storage_bin_id', null);

        // Untouched by this update — must succeed even though storage location is still NULL.
        $this->putJson("/api/v1/app/products/{$legacyProduct->id}", ['name' => 'Renamed Legacy Item'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Renamed Legacy Item');

        // Explicitly touching the field with an invalid/empty value is rejected.
        $this->putJson("/api/v1/app/products/{$legacyProduct->id}", ['default_storage_bin_id' => null], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['default_storage_bin_id']);

        // Explicitly supplying a real bin resolves the gap going forward.
        $bin = $this->makeWarehouseBin($tenant);
        $this->putJson("/api/v1/app/products/{$legacyProduct->id}", ['default_storage_bin_id' => $bin->id], $headers)
            ->assertOk()->assertJsonPath('data.default_storage_bin_id', $bin->id);
    }

    public function test_product_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant(['code' => 'PRDA-'.Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'PRDB-'.Str::random(4)]);
        $this->grantModule($tenantA, 'INVENTORY');
        $this->grantModule($tenantB, 'INVENTORY');
        $product = $this->makeProduct($tenantA);

        [, $tokenB] = $this->makeTenantUser($tenantB, ['product.view']);

        $this->getJson("/api/v1/app/products/{$product->id}", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_product_compatibility_resolution_prefers_more_specific_rule(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDC-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['brand' => 'Hino', 'model' => 'Ranger']);
        $componentGroup = $this->makeComponentGroup();

        $genericProduct = $this->makeProduct($tenant, null, null, ['name' => 'Generic Brake Pad']);
        $specificProduct = $this->makeProduct($tenant, null, null, ['name' => 'Hino Ranger Brake Pad']);

        ProductCompatibility::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $genericProduct->id, 'component_group_id' => $componentGroup->id,
            'vehicle_category_id' => $category->id,
        ]);
        ProductCompatibility::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $specificProduct->id, 'component_group_id' => $componentGroup->id,
            'vehicle_category_id' => $category->id, 'vehicle_brand' => 'Hino', 'vehicle_model' => 'Ranger', ...$this->vehicleFit('Hino', 'Ranger'),
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'vehicle.view']);

        $result = $this->getJson("/api/v1/app/products/compatible?vehicle_id={$vehicle->id}&component_group_id={$componentGroup->id}", $this->authHeaders($token))->assertOk();
        $ids = collect($result->json('data'))->pluck('id')->all();

        $this->assertSame($specificProduct->id, $ids[0]);
        $this->assertContains($genericProduct->id, $ids);
    }

    /** Final reconciliation (queued ADJUST): the PO/PR item-picker's Model Compatibility filter needs `compatibilities` on the list endpoint, not just show(). */
    public function test_product_list_eager_loads_compatibilities_for_the_item_picker_model_filter(): void
    {
        $tenant = $this->makeTenant(['code' => 'PRDL-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $category = $this->makeVehicleCategory();
        $componentGroup = $this->makeComponentGroup();
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Hino Ranger Brake Pad']);

        ProductCompatibility::query()->create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $componentGroup->id,
            'vehicle_category_id' => $category->id, 'vehicle_brand' => 'Hino', 'vehicle_model' => 'Ranger', ...$this->vehicleFit('Hino', 'Ranger'),
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['product.view']);

        $list = $this->getJson('/api/v1/app/products', $this->authHeaders($token))->assertOk();
        $entry = collect($list->json('data'))->firstWhere('id', $product->id);

        $this->assertSame('Ranger', $entry['compatibilities'][0]['vehicle_model']);
    }
}
