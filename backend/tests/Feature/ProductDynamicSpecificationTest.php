<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\StorageRequirement;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Models\TireTraCode;
use App\Domain\Tire\Models\TireTraStarRating;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal - Products" (authoritative document,
 * Phase 2): the Class-Table-Inheritance spec tables + ProductSpecificationService
 * for all six Item Types, their Conditional-Mandatory rules, Tire's
 * system-derived values, Category-scoped-by-Item-Type, the extended
 * Vehicle Compatibility dimensions, and the Warehouse->Zone->Rack->Bin
 * storage hierarchy.
 */
class ProductDynamicSpecificationTest extends TestCase
{
    /**
     * "Next Improvement Tenant Portal - Products" (gap-correction cycle):
     * Default Storage Location is Mandatory, so every `base()` payload
     * needs a real bin. Stashed here so the many existing call sites
     * (`[, $token] = $this->setUpTenant();`) don't all need editing.
     */
    private ?string $defaultStorageBinId = null;

    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'PDS-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['product.view', 'product.create', 'product.update', 'product.delete', 'warehouse.view', 'warehouse.update']);
        $this->defaultStorageBinId = $this->makeWarehouseBin($tenant)->id;

        return [$tenant, $token];
    }

    private function base(string $productType, array $overrides = []): array
    {
        return array_merge([
            ...$this->componentClassification(), 'name' => 'Test Item',
            'product_category_id' => $this->makeProductCategory()->id,
            'product_type' => $productType,
            'uom_id' => $this->makeUom()->id,
            'default_storage_bin_id' => $this->defaultStorageBinId,
        ], $overrides);
    }

    // --- Sparepart ---

    public function test_sparepart_requires_at_least_one_vehicle_compatibility(): void
    {
        [, $token] = $this->setUpTenant();

        $this->postJson('/api/v1/app/products', $this->base('SPARE_PART', [
            'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-1', 'part_type' => 'GENUINE'],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['compatibilities']);
    }

    public function test_sparepart_requires_brand(): void
    {
        [, $token] = $this->setUpTenant();

        $this->postJson('/api/v1/app/products', $this->base('SPARE_PART', [
            'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-1', 'part_type' => 'GENUINE', 'compatibilities' => [$this->vehicleFit()]],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['brand']);
    }

    public function test_sparepart_full_creation_persists_spec_and_compatibility_dimensions(): void
    {
        [, $token] = $this->setUpTenant();

        $response = $this->postJson('/api/v1/app/products', $this->base('SPARE_PART', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'part_number' => 'PN-100', 'part_type' => 'OEM', 'oem_part_number' => 'OEM-1',
                'alternate_part_numbers' => ['ALT-1', 'ALT-2'], 'applicable_position' => ['FRONT', 'LEFT'],
                'critical_part' => true, 'warranty_period_value' => 12, 'warranty_period_unit' => 'MONTHS',
                'compatibilities' => [[
                    ...$this->vehicleFit(), 'variant' => 'G',
                    'year_from' => 2018, 'year_to' => 2022, 'position' => 'FRONT',
                ]],
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('PN-100', $response->json('data.sparepart_spec.part_number'));
        $this->assertSame(['ALT-1', 'ALT-2'], $response->json('data.sparepart_spec.alternate_part_numbers'));

        $productId = $response->json('data.id');
        $this->assertDatabaseHas('product_compatibilities', [
            'product_id' => $productId, 'vehicle_brand' => 'Toyota', 'variant' => 'G', 'year_from' => 2018, 'year_to' => 2022, 'position' => 'FRONT',
        ]);
    }

    // --- Consumable ---

    public function test_consumable_shelf_life_required_when_expiry_tracking_enabled(): void
    {
        [, $token] = $this->setUpTenant();

        $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'track_batch' => false,
            'spec' => ['track_expiry' => true, 'is_hazardous' => false],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['shelf_life_value']);
    }

    public function test_consumable_storage_requirement_required_when_hazardous(): void
    {
        [, $token] = $this->setUpTenant();

        $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'track_batch' => false,
            'spec' => ['track_expiry' => false, 'is_hazardous' => true],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['storage_requirement_ids']);
    }

    public function test_consumable_conversion_required_when_purchase_uom_differs_from_base_uom(): void
    {
        [, $token] = $this->setUpTenant();
        $baseUom = $this->makeUom(['code' => 'LTR-'.Str::random(4)]);
        $purchaseUom = $this->makeUom(['code' => 'DRUM-'.Str::random(4)]);

        $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'uom_id' => $baseUom->id, 'track_batch' => false,
            'spec' => ['track_expiry' => false, 'is_hazardous' => false, 'purchase_uom_id' => $purchaseUom->id],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['conversion_to_base_uom']);
    }

    public function test_consumable_full_creation_with_storage_requirements_and_conversion(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $baseUom = $this->makeUom(['code' => 'LTR2-'.Str::random(4)]);
        $purchaseUom = $this->makeUom(['code' => 'DRUM2-'.Str::random(4)]);
        $storageReq = StorageRequirement::query()->create(['tenant_id' => $tenant->id, 'code' => 'COOL', 'name' => 'Cool Storage', 'is_system' => false, 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'uom_id' => $baseUom->id, 'track_batch' => true,
            'spec' => [
                'grade_specification' => 'SAE 15W-40', 'purchase_uom_id' => $purchaseUom->id, 'conversion_to_base_uom' => 200,
                'track_expiry' => true, 'shelf_life_value' => 24, 'shelf_life_unit' => 'MONTHS',
                'is_hazardous' => true, 'storage_requirement_ids' => [$storageReq->id],
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('SAE 15W-40', $response->json('data.consumable_spec.grade_specification'));
        $this->assertCount(1, $response->json('data.consumable_spec.storage_requirements'));
    }

    /**
     * Owner decision: Specification/Grade's Conditional-Mandatory trigger is the Product's
     * Category/Subcategory (a Superadmin-managed `requires_specification_grade` flag on the
     * category row), not a hardcoded frontend/backend name comparison against "Oil"/"Coolant".
     */
    public function test_consumable_grade_specification_required_when_category_flags_it(): void
    {
        [, $token] = $this->setUpTenant();
        $engineOilCategory = $this->makeProductCategory(['code' => 'ENGINE-OIL', 'name' => 'Engine Oil', 'item_type' => 'CONSUMABLE', 'requires_specification_grade' => true]);

        $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'product_category_id' => $engineOilCategory->id, 'track_batch' => false,
            'spec' => ['track_expiry' => false, 'is_hazardous' => false],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['grade_specification']);

        $response = $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'product_category_id' => $engineOilCategory->id, 'track_batch' => false,
            'spec' => ['grade_specification' => 'SAE 15W-40', 'track_expiry' => false, 'is_hazardous' => false],
        ]), $this->authHeaders($token))->assertStatus(201);
        $this->assertSame('SAE 15W-40', $response->json('data.consumable_spec.grade_specification'));
    }

    public function test_consumable_grade_specification_optional_when_category_does_not_flag_it(): void
    {
        [, $token] = $this->setUpTenant();
        $ragsCategory = $this->makeProductCategory(['code' => 'SHOP-RAGS', 'name' => 'Shop Rags', 'item_type' => 'CONSUMABLE', 'requires_specification_grade' => false]);

        $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'product_category_id' => $ragsCategory->id, 'track_batch' => false,
            'spec' => ['track_expiry' => false, 'is_hazardous' => false],
        ]), $this->authHeaders($token))->assertStatus(201);
    }

    public function test_consumable_grade_specification_requirement_is_recalculated_on_edit_category_change(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);
        $ragsCategory = $this->makeProductCategory(['code' => 'SHOP-RAGS-2', 'item_type' => 'CONSUMABLE', 'requires_specification_grade' => false]);
        $oilCategory = $this->makeProductCategory(['code' => 'ENGINE-OIL-2', 'item_type' => 'CONSUMABLE', 'requires_specification_grade' => true]);

        $productId = $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'product_category_id' => $ragsCategory->id, 'track_batch' => false,
            'spec' => ['track_expiry' => false, 'is_hazardous' => false],
        ]), $headers)->assertStatus(201)->json('data.id');

        // Changing Category to one that now requires Grade, without supplying it, is rejected.
        $this->putJson("/api/v1/app/products/{$productId}", [
            'product_category_id' => $oilCategory->id, 'track_batch' => false,
            'spec' => ['track_expiry' => false, 'is_hazardous' => false],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['grade_specification']);

        // Supplying it alongside the new category succeeds.
        $this->putJson("/api/v1/app/products/{$productId}", [
            'product_category_id' => $oilCategory->id, 'track_batch' => false,
            'spec' => ['grade_specification' => 'SAE 5W-30', 'track_expiry' => false, 'is_hazardous' => false],
        ], $headers)->assertStatus(200)->assertJsonPath('data.consumable_spec.grade_specification', 'SAE 5W-30');
    }

    public function test_consumable_grade_specification_requirement_uses_existing_category_when_edit_omits_it(): void
    {
        [, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);
        $oilCategory = $this->makeProductCategory(['code' => 'ENGINE-OIL-3', 'item_type' => 'CONSUMABLE', 'requires_specification_grade' => true]);

        $productId = $this->postJson('/api/v1/app/products', $this->base('CONSUMABLE', [
            'product_category_id' => $oilCategory->id, 'track_batch' => false,
            'spec' => ['grade_specification' => 'SAE 15W-40', 'track_expiry' => false, 'is_hazardous' => false],
        ]), $headers)->assertStatus(201)->json('data.id');

        // An edit that never touches product_category_id must still enforce the existing
        // category's rule — omitting grade_specification here is rejected, not silently allowed.
        $this->putJson("/api/v1/app/products/{$productId}", [
            'track_batch' => false, 'spec' => ['track_expiry' => false, 'is_hazardous' => false],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['grade_specification']);

        // Preserving the existing value (full resubmit, the established Edit pattern) succeeds
        // and leaves the data untouched — an edit must never silently null it out.
        $this->putJson("/api/v1/app/products/{$productId}", [
            'track_batch' => false, 'spec' => ['grade_specification' => 'SAE 15W-40', 'track_expiry' => false, 'is_hazardous' => false],
        ], $headers)->assertStatus(200)->assertJsonPath('data.consumable_spec.grade_specification', 'SAE 15W-40');
    }

    // --- Rim ---

    public function test_rim_full_creation_with_optional_compatibility(): void
    {
        [, $token] = $this->setUpTenant();

        $response = $this->postJson('/api/v1/app/products', $this->base('RIM', [
            'brand' => 'Enkei', 'track_serial_number' => true,
            'spec' => [
                'rim_type' => 'ALLOY', 'diameter_inch' => 17.5, 'width_inch' => 6.0,
                'bolt_holes' => 6, 'pcd_mm' => 139.7, 'offset_mm' => -10,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('ALLOY', $response->json('data.rim_spec.rim_type'));
        $this->assertSame(6, $response->json('data.rim_spec.bolt_holes'));
    }

    public function test_rim_requires_serialized_flag(): void
    {
        [, $token] = $this->setUpTenant();

        $this->postJson('/api/v1/app/products', $this->base('RIM', [
            'brand' => 'Enkei',
            'spec' => ['rim_type' => 'ALLOY', 'diameter_inch' => 17.5, 'width_inch' => 6.0, 'bolt_holes' => 6, 'pcd_mm' => 139.7],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['track_serial_number']);
    }

    // --- Tire ---

    private function tireRefs(): array
    {
        return [
            'single' => TireLoadIndex::query()->create(['tenant_id' => null, 'code' => '92-'.Str::random(3), 'max_load_single_kg' => 630, 'max_load_dual_kg' => 580, 'is_system' => true, 'status' => 'ACTIVE']),
            'dual' => TireLoadIndex::query()->create(['tenant_id' => null, 'code' => '148-'.Str::random(3), 'max_load_single_kg' => 3350, 'max_load_dual_kg' => 3150, 'is_system' => true, 'status' => 'ACTIVE']),
            'speed' => TireSpeedRating::query()->create(['tenant_id' => null, 'code' => 'T-'.Str::random(3), 'max_speed_kmh' => 190, 'is_system' => true, 'status' => 'ACTIVE']),
            'ply' => TirePlyRating::query()->create(['tenant_id' => null, 'code' => '16PR-'.Str::random(3), 'load_range' => 'H', 'is_system' => true, 'status' => 'ACTIVE']),
        ];
    }

    public function test_tire_car_creation_computes_derived_values(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();

        $response = $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Dunlop',
            'spec' => [
                'vehicle_group' => 'CAR', 'pattern_name' => 'Enasave', 'width_mm' => 185, 'aspect_ratio_percent' => 70,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 14, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('185/70 R14', $response->json('data.tire_spec.tire_size_computed'));
        $this->assertSame('630.00', $response->json('data.tire_spec.single_max_load_kg_computed'));
        $this->assertSame('190.00', $response->json('data.tire_spec.max_speed_kmh_computed'));
        $this->assertNull($response->json('data.tire_spec.dual_max_load_kg_computed'));
    }

    public function test_tire_truck_bus_requires_dual_load_index_and_ply_rating(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();

        $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Michelin',
            'spec' => [
                'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['dual_load_index_id']);
    }

    public function test_tire_truck_bus_star_rating_required_once_tra_code_selected(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();
        $traCode = TireTraCode::query()->create(['tenant_id' => null, 'code' => 'G2-'.Str::random(3), 'profile' => 'G', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Michelin',
            'spec' => [
                'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
                'dual_load_index_id' => $refs['dual']->id, 'ply_rating_id' => $refs['ply']->id,
                'tra_code_id' => $traCode->id,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['tra_star_rating_id']);
    }

    public function test_tire_truck_bus_full_creation_computes_all_derived_values(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();
        $traCode = TireTraCode::query()->create(['tenant_id' => null, 'code' => 'G2-'.Str::random(3), 'profile' => 'G', 'is_system' => true, 'status' => 'ACTIVE']);
        $star = TireTraStarRating::query()->create(['tenant_id' => null, 'tra_code_id' => $traCode->id, 'star_rating' => '2', 'purpose' => 'On/Off Road']);

        $response = $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Michelin',
            'spec' => [
                'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
                'dual_load_index_id' => $refs['dual']->id, 'ply_rating_id' => $refs['ply']->id,
                'tra_code_id' => $traCode->id, 'tra_star_rating_id' => $star->id,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('295/80 R22.5', $response->json('data.tire_spec.tire_size_computed'));
        $this->assertSame('3150.00', $response->json('data.tire_spec.dual_max_load_kg_computed'));
        $this->assertSame('H', $response->json('data.tire_spec.load_range_computed'));
        $this->assertSame('G', $response->json('data.tire_spec.tra_profile_computed'));
        $this->assertSame('On/Off Road', $response->json('data.tire_spec.purpose_computed'));
    }

    /**
     * "Next Improvement Tenant Portal - Products" (gap-correction cycle,
     * section 8): Truck & Bus-only fields must never become a client-
     * writable back door for a Car tire — even if a request bypassing the
     * UI sends them, the server must ignore/null them, never persist them.
     */
    public function test_tire_car_ignores_truck_bus_only_fields_even_if_supplied(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();

        $response = $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Dunlop',
            'spec' => [
                'vehicle_group' => 'CAR', 'pattern_name' => 'Enasave', 'width_mm' => 185, 'aspect_ratio_percent' => 70,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 14, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
                // Bypassing the UI: a Car submission still sends Truck/Bus-only fields.
                'dual_load_index_id' => $refs['dual']->id, 'ply_rating_id' => $refs['ply']->id,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertNull($response->json('data.tire_spec.dual_load_index_id'));
        $this->assertNull($response->json('data.tire_spec.ply_rating_id'));
        $this->assertNull($response->json('data.tire_spec.dual_max_load_kg_computed'));
        $this->assertNull($response->json('data.tire_spec.load_range_computed'));
    }

    /**
     * Reproduces the latent bug found during the SupplyChainSeeder
     * correction: a valid Truck & Bus tire that genuinely OMITS the
     * optional `tra_code_id`/`tra_star_rating_id` keys (not merely sends
     * them as null) previously crashed persistTire() with "Undefined
     * array key" — Laravel's Validator::validate() does not add an absent
     * optional key to its returned array, and persistTire() accessed both
     * unconditionally. Exercises the real POST /products API path.
     */
    public function test_tire_truck_bus_creation_succeeds_with_tra_fields_genuinely_absent(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();

        $spec = [
            'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
            'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
            'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
            'dual_load_index_id' => $refs['dual']->id, 'ply_rating_id' => $refs['ply']->id,
        ];
        $this->assertArrayNotHasKey('tra_code_id', $spec);
        $this->assertArrayNotHasKey('tra_star_rating_id', $spec);

        $response = $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Michelin', 'spec' => $spec,
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertNull($response->json('data.tire_spec.tra_code_id'));
        $this->assertNull($response->json('data.tire_spec.tra_star_rating_id'));
        $this->assertNull($response->json('data.tire_spec.tra_profile_computed'));
        $this->assertNull($response->json('data.tire_spec.purpose_computed'));
        $this->assertSame('3150.00', $response->json('data.tire_spec.dual_max_load_kg_computed'));
    }

    /** Explicit null (rather than genuinely absent) must remain supported too — this is the shape SupplyChainSeeder's workaround relied on. */
    public function test_tire_truck_bus_creation_succeeds_with_tra_fields_explicitly_null(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();

        $response = $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Michelin',
            'spec' => [
                'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
                'dual_load_index_id' => $refs['dual']->id, 'ply_rating_id' => $refs['ply']->id,
                'tra_code_id' => null, 'tra_star_rating_id' => null,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertNull($response->json('data.tire_spec.tra_code_id'));
        $this->assertNull($response->json('data.tire_spec.tra_star_rating_id'));
    }

    public function test_tire_star_rating_must_belong_to_selected_tra_code(): void
    {
        [, $token] = $this->setUpTenant();
        $refs = $this->tireRefs();
        $traCodeA = TireTraCode::query()->create(['tenant_id' => null, 'code' => 'GA-'.Str::random(3), 'profile' => 'G', 'is_system' => true, 'status' => 'ACTIVE']);
        $traCodeB = TireTraCode::query()->create(['tenant_id' => null, 'code' => 'GB-'.Str::random(3), 'profile' => 'G', 'is_system' => true, 'status' => 'ACTIVE']);
        $starForB = TireTraStarRating::query()->create(['tenant_id' => null, 'tra_code_id' => $traCodeB->id, 'star_rating' => '3', 'purpose' => 'Severe Service']);

        $this->postJson('/api/v1/app/products', $this->base('TIRE', [
            'brand' => 'Michelin',
            'spec' => [
                'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $refs['single']->id, 'speed_rating_id' => $refs['speed']->id,
                'dual_load_index_id' => $refs['dual']->id, 'ply_rating_id' => $refs['ply']->id,
                'tra_code_id' => $traCodeA->id, 'tra_star_rating_id' => $starForB->id,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['tra_star_rating_id']);
    }

    // --- Tool ---

    public function test_tool_calibration_interval_required_when_calibration_required(): void
    {
        [, $token] = $this->setUpTenant();
        $toolType = \App\Domain\ProductMaster\Models\ToolType::query()->create(['tenant_id' => null, 'code' => 'MEASURE', 'name' => 'Measuring Tool', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('TOOL', [
            'track_serial_number' => true,
            'spec' => ['tool_type_id' => $toolType->id, 'checkout_required' => true, 'calibration_required' => true, 'maintenance_required' => false],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['calibration_interval_value']);
    }

    public function test_tool_full_creation(): void
    {
        [, $token] = $this->setUpTenant();
        $toolType = \App\Domain\ProductMaster\Models\ToolType::query()->create(['tenant_id' => null, 'code' => 'MEASURE2', 'name' => 'Measuring Tool', 'is_system' => true, 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/app/products', $this->base('TOOL', [
            'brand' => 'Tekiro', 'track_serial_number' => true,
            'spec' => [
                'model' => 'TW-200', 'tool_type_id' => $toolType->id, 'checkout_required' => true,
                'calibration_required' => true, 'calibration_interval_value' => 6, 'calibration_interval_unit' => 'MONTHS',
                'maintenance_required' => false,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('TW-200', $response->json('data.tool_spec.model'));
    }

    /**
     * "Next Improvement Tenant Portal - Products" (gap-correction cycle,
     * section 9): every Conditional-Mandatory trigger that IS defined in
     * the document must be verified with both a positive and a negative
     * case. Calibration's own interval-required rule was already covered;
     * Maintenance's mirror rule was not — closing that test-coverage gap.
     */
    public function test_tool_maintenance_interval_required_when_maintenance_required(): void
    {
        [, $token] = $this->setUpTenant();
        $toolType = \App\Domain\ProductMaster\Models\ToolType::query()->create(['tenant_id' => null, 'code' => 'MEASURE3', 'name' => 'Measuring Tool', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('TOOL', [
            'track_serial_number' => true,
            'spec' => ['tool_type_id' => $toolType->id, 'checkout_required' => false, 'calibration_required' => false, 'maintenance_required' => true],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['maintenance_interval_value']);
    }

    // --- Equipment ---

    public function test_equipment_maintenance_interval_required_when_maintenance_required(): void
    {
        [, $token] = $this->setUpTenant();
        $equipType = \App\Domain\ProductMaster\Models\EquipmentType::query()->create(['tenant_id' => null, 'code' => 'LIFT', 'name' => 'Vehicle Lift', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('EQUIPMENT', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'model' => 'L-2000', 'equipment_type_id' => $equipType->id,
                'maintenance_required' => true, 'inspection_required' => false, 'calibration_required' => false,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['maintenance_interval_value']);
    }

    public function test_equipment_full_creation(): void
    {
        [, $token] = $this->setUpTenant();
        $equipType = \App\Domain\ProductMaster\Models\EquipmentType::query()->create(['tenant_id' => null, 'code' => 'LIFT2', 'name' => 'Vehicle Lift', 'is_system' => true, 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/app/products', $this->base('EQUIPMENT', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'model' => 'L-2000', 'equipment_type_id' => $equipType->id, 'power_source' => 'ELECTRIC', 'voltage_v' => 380,
                'maintenance_required' => true, 'maintenance_interval_value' => 3, 'maintenance_interval_unit' => 'MONTHS',
                'inspection_required' => false, 'calibration_required' => false,
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('L-2000', $response->json('data.equipment_spec.model'));
        $this->assertSame(380, $response->json('data.equipment_spec.voltage_v'));
    }

    /**
     * "Next Improvement Tenant Portal - Products" (gap-correction cycle,
     * section 9): Equipment has four independent Conditional-Mandatory
     * triggers (Maintenance, Inspection, Calibration, Certification).
     * Only Maintenance's negative case existed before this cycle; the
     * other three are added here for full positive/negative coverage.
     */
    public function test_equipment_inspection_interval_required_when_inspection_required(): void
    {
        [, $token] = $this->setUpTenant();
        $equipType = \App\Domain\ProductMaster\Models\EquipmentType::query()->create(['tenant_id' => null, 'code' => 'LIFT3', 'name' => 'Vehicle Lift', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('EQUIPMENT', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'model' => 'L-3000', 'equipment_type_id' => $equipType->id,
                'maintenance_required' => false, 'inspection_required' => true, 'calibration_required' => false,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['inspection_interval_value']);
    }

    public function test_equipment_calibration_interval_required_when_calibration_required(): void
    {
        [, $token] = $this->setUpTenant();
        $equipType = \App\Domain\ProductMaster\Models\EquipmentType::query()->create(['tenant_id' => null, 'code' => 'LIFT4', 'name' => 'Vehicle Lift', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('EQUIPMENT', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'model' => 'L-4000', 'equipment_type_id' => $equipType->id,
                'maintenance_required' => false, 'inspection_required' => false, 'calibration_required' => true,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['calibration_interval_value']);
    }

    public function test_equipment_certification_type_required_when_certification_required(): void
    {
        [, $token] = $this->setUpTenant();
        $equipType = \App\Domain\ProductMaster\Models\EquipmentType::query()->create(['tenant_id' => null, 'code' => 'LIFT5', 'name' => 'Vehicle Lift', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('EQUIPMENT', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'model' => 'L-5000', 'equipment_type_id' => $equipType->id,
                'maintenance_required' => false, 'inspection_required' => false, 'calibration_required' => false,
                'certification_required' => true,
            ],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['certification_type']);
    }

    public function test_equipment_certification_type_persisted_when_certification_required(): void
    {
        [, $token] = $this->setUpTenant();
        $equipType = \App\Domain\ProductMaster\Models\EquipmentType::query()->create(['tenant_id' => null, 'code' => 'LIFT6', 'name' => 'Vehicle Lift', 'is_system' => true, 'status' => 'ACTIVE']);

        $response = $this->postJson('/api/v1/app/products', $this->base('EQUIPMENT', [
            'brand' => 'Bosch', 'track_serial_number' => true,
            'spec' => [
                'model' => 'L-6000', 'equipment_type_id' => $equipType->id,
                'maintenance_required' => false, 'inspection_required' => false, 'calibration_required' => false,
                'certification_required' => true, 'certification_type' => 'ISO 45001 Lifting Equipment',
            ],
        ]), $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('ISO 45001 Lifting Equipment', $response->json('data.equipment_spec.certification_type'));
    }

    // --- Category scoped by Item Type ---

    public function test_product_creation_rejects_category_from_a_different_item_type(): void
    {
        [, $token] = $this->setUpTenant();
        $category = ProductCategory::query()->create(['tenant_id' => null, 'code' => 'TIRE-ONLY', 'name' => 'Tire Only', 'item_type' => 'TIRE', 'is_system' => true, 'status' => 'ACTIVE']);

        $this->postJson('/api/v1/app/products', $this->base('SPARE_PART', [
            'product_category_id' => $category->id, 'brand' => 'Bosch', 'track_serial_number' => false,
            'spec' => ['part_number' => 'PN-1', 'part_type' => 'GENUINE', 'compatibilities' => [$this->vehicleFit()]],
        ]), $this->authHeaders($token))->assertStatus(422)->assertJsonValidationErrors(['product_category_id']);
    }

    // --- Warehouse storage hierarchy ---

    public function test_warehouse_zone_rack_bin_hierarchy_and_default_storage_assignment(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $headers = $this->authHeaders($token);
        $warehouse = $this->makeWarehouse($tenant);

        $zone = $this->postJson('/api/v1/app/warehouse-zones', ['warehouse_id' => $warehouse->id, 'code' => 'A', 'name' => 'Zone A'], $headers)->assertStatus(201);
        $rack = $this->postJson('/api/v1/app/warehouse-racks', ['warehouse_zone_id' => $zone->json('data.id'), 'code' => 'R1', 'name' => 'Rack 1'], $headers)->assertStatus(201);
        $bin = $this->postJson('/api/v1/app/warehouse-bins', ['warehouse_rack_id' => $rack->json('data.id'), 'code' => 'B1', 'name' => 'Bin 1'], $headers)->assertStatus(201);

        // Duplicate code within the same rack is rejected.
        $this->postJson('/api/v1/app/warehouse-bins', ['warehouse_rack_id' => $rack->json('data.id'), 'code' => 'b1', 'name' => 'Bin 1 dup'], $headers)->assertStatus(422);

        $product = $this->makeProduct($tenant);
        $this->putJson("/api/v1/app/products/{$product->id}", ['default_storage_bin_id' => $bin->json('data.id')], $headers)
            ->assertOk()->assertJsonPath('data.default_storage_bin_id', $bin->json('data.id'));

        // A bin assigned as a product's default storage location cannot be deleted.
        $this->deleteJson("/api/v1/app/warehouse-bins/{$bin->json('data.id')}", [], $headers)->assertStatus(422);
    }
}
