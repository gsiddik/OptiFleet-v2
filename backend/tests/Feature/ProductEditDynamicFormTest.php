<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Section 15/Edit Dynamic Form: Edit must reconstruct and persist the Item Type's spec
 * table (previously ProductController::update() never touched it at all), while Item
 * Type/Item Code stay immutable and Vehicle Compatibility is left to its own existing
 * add/remove endpoints.
 */
class ProductEditDynamicFormTest extends TestCase
{
    private ?string $binId = null;

    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'PED-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update']);
        $this->binId = $this->makeWarehouseBin($tenant)->id;

        return [$tenant, $token];
    }

    private function createSparepart($token, array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/app/products', array_merge([
            'sku' => 'SKU-'.Str::random(8), 'name' => 'Original Name',
            'product_category_id' => $this->makeProductCategory(['item_type' => 'SPARE_PART'])->id,
            'product_type' => 'SPARE_PART',
            'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->binId,
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => [
                'part_number' => 'PN-1', 'part_type' => 'GENUINE',
                'compatibilities' => [['vehicle_brand' => 'Toyota', 'vehicle_model' => 'Avanza']],
            ],
        ], $overrides), $this->authHeaders($token))->assertStatus(201);

        return $response->json('data');
    }

    public function test_edit_updates_sparepart_spec_fields(): void
    {
        [, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);

        $response = $this->putJson("/api/v1/app/products/{$product['id']}", [
            'name' => 'Updated Name',
            'product_category_id' => $product['product_category_id'],
            'uom_id' => $product['uom_id'],
            'default_storage_bin_id' => $this->binId,
            'brand' => 'Denso',
            'track_serial_number' => true,
            'spec' => [
                'part_number' => 'PN-2', 'part_type' => 'OEM', 'oem_part_number' => 'OEM-99',
            ],
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame('Updated Name', $response->json('data.name'));
        $this->assertSame('Denso', $response->json('data.brand'));
        $this->assertSame('PN-2', $response->json('data.sparepart_spec.part_number'));
        $this->assertSame('OEM', $response->json('data.sparepart_spec.part_type'));
        $this->assertSame('OEM-99', $response->json('data.sparepart_spec.oem_part_number'));

        // Exactly one spec row — updateOrCreate, not a second insert.
        $this->assertDatabaseCount('product_spareparts', 1);
    }

    public function test_edit_does_not_touch_vehicle_compatibility(): void
    {
        [, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);

        $this->assertDatabaseCount('product_compatibilities', 1);

        $this->putJson("/api/v1/app/products/{$product['id']}", [
            'name' => $product['name'],
            'product_category_id' => $product['product_category_id'],
            'uom_id' => $product['uom_id'],
            'default_storage_bin_id' => $this->binId,
            'brand' => 'Bosch',
            'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-1', 'part_type' => 'GENUINE'],
        ], $this->authHeaders($token))->assertOk();

        // Untouched — still exactly the one compatibility row created at Create time.
        $this->assertDatabaseCount('product_compatibilities', 1);
    }

    public function test_edit_without_spec_key_leaves_existing_spec_untouched(): void
    {
        [, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);

        $response = $this->putJson("/api/v1/app/products/{$product['id']}", [
            'status' => 'INACTIVE',
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame('INACTIVE', $response->json('data.status'));
        $this->assertSame('PN-1', $response->json('data.sparepart_spec.part_number'));
    }

    public function test_edit_rejects_invalid_spec_the_same_way_create_does(): void
    {
        [, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);

        $this->putJson("/api/v1/app/products/{$product['id']}", [
            'name' => $product['name'],
            'product_category_id' => $product['product_category_id'],
            'uom_id' => $product['uom_id'],
            'default_storage_bin_id' => $this->binId,
            'brand' => 'Bosch',
            'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-1'], // missing required part_type
        ], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['part_type']);
    }

    public function test_edit_can_change_category_uom_and_storage_location(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);
        $newCategory = $this->makeProductCategory(['item_type' => 'SPARE_PART']);
        $newUom = $this->makeUom();
        $newBin = $this->makeWarehouseBin($tenant);

        $response = $this->putJson("/api/v1/app/products/{$product['id']}", [
            'name' => $product['name'],
            'product_category_id' => $newCategory->id,
            'uom_id' => $newUom->id,
            'default_storage_bin_id' => $newBin->id,
            'brand' => 'Bosch',
            'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-1', 'part_type' => 'GENUINE'],
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame($newCategory->id, $response->json('data.product_category_id'));
        $this->assertSame($newUom->id, $response->json('data.uom_id'));
        $this->assertSame($newBin->id, $response->json('data.default_storage_bin_id'));
    }

    public function test_edit_rejects_category_from_a_different_item_type(): void
    {
        [, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);
        $wrongCategory = $this->makeProductCategory(['item_type' => 'TOOL']);

        $this->putJson("/api/v1/app/products/{$product['id']}", [
            'product_category_id' => $wrongCategory->id,
        ], $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['product_category_id']);
    }

    public function test_edit_ignores_product_type_if_sent(): void
    {
        [, $token] = $this->setUpTenant();
        $product = $this->createSparepart($token);

        $response = $this->putJson("/api/v1/app/products/{$product['id']}", [
            'product_type' => 'TOOL',
            'name' => 'Still a Sparepart',
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame('SPARE_PART', $response->json('data.product_type'));
        $this->assertSame('Still a Sparepart', $response->json('data.name'));
    }

    public function test_edit_updates_tire_spec_and_recomputes_derived_values(): void
    {
        [, $token] = $this->setUpTenant();
        $loadIndex1 = \App\Domain\Tire\Models\TireLoadIndex::query()->create(['code' => '91', 'max_load_single_kg' => 615, 'max_load_dual_kg' => null, 'status' => 'ACTIVE']);
        $loadIndex2 = \App\Domain\Tire\Models\TireLoadIndex::query()->create(['code' => '92', 'max_load_single_kg' => 630, 'max_load_dual_kg' => null, 'status' => 'ACTIVE']);
        $speedRating = \App\Domain\Tire\Models\TireSpeedRating::query()->create(['code' => 'T', 'max_speed_kmh' => 190, 'status' => 'ACTIVE']);

        $product = $this->postJson('/api/v1/app/products', [
            'sku' => 'SKU-'.Str::random(8), 'name' => 'Tire A',
            'product_category_id' => $this->makeProductCategory(['item_type' => 'TIRE'])->id,
            'product_type' => 'TIRE',
            'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->binId,
            'brand' => 'Dunlop', 'track_serial_number' => true,
            'spec' => [
                'vehicle_group' => 'CAR', 'pattern_name' => 'Enasave', 'width_mm' => 185,
                'aspect_ratio_percent' => 70, 'construction_type' => 'RADIAL', 'rim_diameter_inch' => 14,
                'tire_type' => 'TUBELESS', 'single_load_index_id' => $loadIndex1->id, 'speed_rating_id' => $speedRating->id,
            ],
        ], $this->authHeaders($token))->assertStatus(201)->json('data');

        $this->assertSame('185/70 R14', $product['tire_spec']['tire_size_computed']);
        $this->assertEquals(615, $product['tire_spec']['single_max_load_kg_computed']);

        $response = $this->putJson("/api/v1/app/products/{$product['id']}", [
            'brand' => 'Dunlop', 'track_serial_number' => true,
            'spec' => [
                'vehicle_group' => 'CAR', 'pattern_name' => 'Enasave', 'width_mm' => 195,
                'aspect_ratio_percent' => 65, 'construction_type' => 'RADIAL', 'rim_diameter_inch' => 15,
                'tire_type' => 'TUBELESS', 'single_load_index_id' => $loadIndex2->id, 'speed_rating_id' => $speedRating->id,
            ],
        ], $this->authHeaders($token))->assertOk();

        $this->assertSame('195/65 R15', $response->json('data.tire_spec.tire_size_computed'));
        $this->assertEquals(630, $response->json('data.tire_spec.single_max_load_kg_computed'));
        $this->assertDatabaseCount('product_tires', 1);
    }
}
