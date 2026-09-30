<?php

namespace Database\Seeders;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\WarehouseBin;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Services\ProductCreationService;
use App\Support\TenantContext;

/**
 * Products for all six Item Types (Sections 18-25), each created through
 * ProductCreationService — the exact path POST /app/products uses — so every
 * conditional-mandatory rule, the mandatory Component Group + Category, the
 * Tire derived values and the server-generated Item Code and SKU all run for
 * real. Idempotent on tenant + product name: a rerun finds the existing row
 * before the numbering sequence is touched.
 *
 * @return object{bySku: array<string, Product>} keyed by this seeder's fixture key (e.g. TEST-SP-001), not the generated SKU
 */
class FunctionalTestProductSeeder
{
    public function run(Tenant $tenant, object $masterData): object
    {
        app(TenantContext::class)->setTenantId($tenant->id);

        $bins = WarehouseBin::query()->where('tenant_id', $tenant->id)->get()->keyBy('code');
        $binA1_01 = $bins['BIN-A1-01']->id;
        $binA1_02 = $bins['BIN-A1-02']->id;
        $binA2_01 = $bins['BIN-A2-01']->id;
        $binB1_01 = $bins['BIN-B1-01']->id;

        $cat = $masterData->productCategories;
        $uom = $masterData->uoms;
        $tireRefs = $masterData->tireRefs;
        $avanza = $masterData->vehicleModels['AVANZA'];
        $rangerFg = $masterData->vehicleModels['RANGER_FG'];
        $carCategoryId = $masterData->vehicleCategories['CAR']->id;
        $truckCategoryId = $masterData->vehicleCategories['TRUCK']->id;

        $products = [];

        // --- Sparepart (Section 19) ---
        $products['TEST-SP-001'] = $this->make($tenant, 'TEST-SP-001', '[TEST] Brake Pad Set (Standard)', $cat['SPARE_PART']->id, 'SPARE_PART', $uom['PCS']->id, $binA1_01,
            ['brand' => 'Akebono', 'track_serial_number' => false],
            [
                'part_number' => 'BRK-PAD-STD-01', 'part_type' => 'GENUINE',
                'compatibilities' => [['vehicle_brand_id' => $avanza->vehicle_brand_id, 'vehicle_model_id' => $avanza->id, 'vehicle_category_id' => $carCategoryId]],
            ]);
        $products['TEST-SP-002'] = $this->make($tenant, 'TEST-SP-002', '[TEST] Alternator (Serialized)', $cat['SPARE_PART']->id, 'SPARE_PART', $uom['PCS']->id, $binA1_01,
            ['brand' => 'Denso', 'track_serial_number' => true],
            [
                'part_number' => 'ALT-SER-01', 'part_type' => 'OEM',
                'compatibilities' => [['vehicle_brand_id' => $avanza->vehicle_brand_id, 'vehicle_model_id' => $avanza->id, 'vehicle_category_id' => $carCategoryId]],
            ]);
        $products['TEST-SP-003'] = $this->make($tenant, 'TEST-SP-003', '[TEST] ABS Sensor (Critical Part)', $cat['SPARE_PART']->id, 'SPARE_PART', $uom['PCS']->id, $binA1_02,
            ['brand' => 'Bosch', 'track_serial_number' => false],
            [
                'part_number' => 'ABS-SNS-01', 'part_type' => 'OES', 'critical_part' => true,
                'compatibilities' => [['vehicle_brand_id' => $rangerFg->vehicle_brand_id, 'vehicle_model_id' => $rangerFg->id, 'vehicle_category_id' => $truckCategoryId]],
            ]);
        $products['TEST-SP-004'] = $this->make($tenant, 'TEST-SP-004', '[TEST] Clutch Kit (Warranty)', $cat['SPARE_PART']->id, 'SPARE_PART', $uom['PCS']->id, $binA1_02,
            ['brand' => 'Exedy', 'track_serial_number' => false],
            [
                'part_number' => 'CLT-KIT-01', 'part_type' => 'AFTERMARKET',
                'warranty_period_value' => 12, 'warranty_period_unit' => 'MONTHS', 'warranty_mileage_km' => 20000,
                'compatibilities' => [['vehicle_brand_id' => $rangerFg->vehicle_brand_id, 'vehicle_model_id' => $rangerFg->id, 'vehicle_category_id' => $truckCategoryId]],
            ]);
        $products['TEST-SP-005'] = $this->make($tenant, 'TEST-SP-005', '[TEST] Wiper Blade (Multi-Vehicle Compatibility)', $cat['SPARE_PART']->id, 'SPARE_PART', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Bosch', 'track_serial_number' => false],
            [
                'part_number' => 'WPR-UNI-01', 'part_type' => 'AFTERMARKET',
                'compatibilities' => [
                    ['vehicle_brand_id' => $avanza->vehicle_brand_id, 'vehicle_model_id' => $avanza->id, 'vehicle_category_id' => $carCategoryId],
                    ['vehicle_brand_id' => $rangerFg->vehicle_brand_id, 'vehicle_model_id' => $rangerFg->id, 'vehicle_category_id' => $truckCategoryId],
                ],
            ]);

        // --- Consumable (Section 20) ---
        $products['TEST-CS-001'] = $this->make($tenant, 'TEST-CS-001', '[TEST] Engine Oil SAE 10W-40 (Standard)', $cat['CONSUMABLE']->id, 'CONSUMABLE', $uom['LTR']->id, $binB1_01,
            ['track_batch' => false],
            ['track_expiry' => false, 'is_hazardous' => false]);
        $products['TEST-CS-002'] = $this->make($tenant, 'TEST-CS-002', '[TEST] Engine Oil SAE 15W-40 (Drum, UOM Conversion)', $cat['CONSUMABLE']->id, 'CONSUMABLE', $uom['LTR']->id, $binB1_01,
            ['track_batch' => false],
            [
                'track_expiry' => false, 'is_hazardous' => false,
                'purchase_uom_id' => $uom['DRM']->id, 'conversion_to_base_uom' => 200,
            ]);
        $products['TEST-CS-003'] = $this->make($tenant, 'TEST-CS-003', '[TEST] Radiator Coolant (Expiry Tracking)', $cat['CONSUMABLE']->id, 'CONSUMABLE', $uom['LTR']->id, $binB1_01,
            ['track_batch' => false],
            ['track_expiry' => true, 'shelf_life_value' => 24, 'shelf_life_unit' => 'MONTHS', 'is_hazardous' => false]);
        $products['TEST-CS-004'] = $this->make($tenant, 'TEST-CS-004', '[TEST] Brake Cleaner Spray (Hazardous)', $cat['CONSUMABLE']->id, 'CONSUMABLE', $uom['PCS']->id, $binB1_01,
            ['track_batch' => false],
            [
                'track_expiry' => false, 'is_hazardous' => true,
                'storage_requirement_ids' => [$masterData->storageRequirement->id],
            ]);
        $products['TEST-CS-005'] = $this->make($tenant, 'TEST-CS-005', '[TEST] Chassis Grease (Batch Tracked)', $cat['CONSUMABLE']->id, 'CONSUMABLE', $uom['PCS']->id, $binB1_01,
            ['track_batch' => true],
            ['track_expiry' => false, 'is_hazardous' => false]);

        // --- Rim (Section 21) ---
        $products['TEST-RIM-001'] = $this->make($tenant, 'TEST-RIM-001', '[TEST] Alloy Rim 15x6.5', $cat['RIM']->id, 'RIM', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Enkei', 'track_serial_number' => false],
            ['rim_type' => 'ALLOY', 'diameter_inch' => 15, 'width_inch' => 6.5, 'bolt_holes' => 4, 'pcd_mm' => 100, 'material' => 'Aluminium Alloy']);
        $products['TEST-RIM-002'] = $this->make($tenant, 'TEST-RIM-002', '[TEST] Steel Rim 16x7 (Serialized, Vehicle Compat)', $cat['RIM']->id, 'RIM', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Topy', 'track_serial_number' => true],
            [
                'rim_type' => 'STEEL', 'diameter_inch' => 16, 'width_inch' => 7, 'bolt_holes' => 6, 'pcd_mm' => 139.7,
                'compatible_tire_sizes' => ['205/70R16'],
                'compatibilities' => [['vehicle_brand_id' => $rangerFg->vehicle_brand_id, 'vehicle_model_id' => $rangerFg->id, 'vehicle_category_id' => $truckCategoryId]],
            ]);

        // --- Tire: Car & Truck/Bus (Sections 22-23) ---
        $products['TEST-TIRE-CAR-001'] = $this->make($tenant, 'TEST-TIRE-CAR-001', '[TEST] Car Tire 185/70R14', $cat['TIRE']->id, 'TIRE', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Bridgestone', 'track_serial_number' => false],
            [
                'vehicle_group' => 'CAR', 'pattern_name' => 'Turanza', 'width_mm' => 185, 'aspect_ratio_percent' => 70,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 14, 'tire_type' => 'TUBELESS',
                'single_load_index_id' => $tireRefs['loadIndexCar']->id, 'speed_rating_id' => $tireRefs['speedRatingCar']->id,
            ]);
        $products['TEST-TIRE-TRUCK-001'] = $this->make($tenant, 'TEST-TIRE-TRUCK-001', '[TEST] Truck & Bus Tire 295/80R22.5', $cat['TIRE']->id, 'TIRE', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Bridgestone', 'track_serial_number' => true],
            [
                'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'R150', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
                'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBE_TYPE',
                'single_load_index_id' => $tireRefs['loadIndexTruck']->id, 'speed_rating_id' => $tireRefs['speedRatingTruck']->id,
                'dual_load_index_id' => $tireRefs['loadIndexTruck']->id, 'ply_rating_id' => $tireRefs['plyRating']->id,
                'tra_code_id' => $tireRefs['traCode']->id, 'tra_star_rating_id' => $tireRefs['traStarRating']->id,
            ]);

        // --- Tool (Section 24) ---
        $products['TEST-TOOL-001'] = $this->make($tenant, 'TEST-TOOL-001', '[TEST] Combination Wrench Set (Standard)', $cat['TOOL']->id, 'TOOL', $uom['PCS']->id, $binA2_01,
            ['track_serial_number' => false],
            ['tool_type_id' => $masterData->toolType->id, 'checkout_required' => false, 'calibration_required' => false, 'maintenance_required' => false]);
        $products['TEST-TOOL-002'] = $this->make($tenant, 'TEST-TOOL-002', '[TEST] Impact Wrench (Serialized)', $cat['TOOL']->id, 'TOOL', $uom['PCS']->id, $binA2_01,
            ['track_serial_number' => true],
            ['tool_type_id' => $masterData->toolType->id, 'checkout_required' => false, 'calibration_required' => false, 'maintenance_required' => false]);
        $products['TEST-TOOL-003'] = $this->make($tenant, 'TEST-TOOL-003', '[TEST] Diagnostic Scanner Handheld (Checkout Required)', $cat['TOOL']->id, 'TOOL', $uom['PCS']->id, $binA2_01,
            ['track_serial_number' => true],
            ['tool_type_id' => $masterData->toolType->id, 'checkout_required' => true, 'calibration_required' => false, 'maintenance_required' => false]);
        $products['TEST-TOOL-004'] = $this->make($tenant, 'TEST-TOOL-004', '[TEST] Torque Wrench (Calibration Required)', $cat['TOOL']->id, 'TOOL', $uom['PCS']->id, $binA2_01,
            ['track_serial_number' => true],
            [
                'tool_type_id' => $masterData->toolType->id, 'checkout_required' => false,
                'calibration_required' => true, 'calibration_interval_value' => 6, 'calibration_interval_unit' => 'MONTHS',
                'maintenance_required' => false,
            ]);
        $products['TEST-TOOL-005'] = $this->make($tenant, 'TEST-TOOL-005', '[TEST] Hydraulic Jack (Maintenance Required)', $cat['TOOL']->id, 'TOOL', $uom['PCS']->id, $binA2_01,
            ['track_serial_number' => false],
            [
                'tool_type_id' => $masterData->toolType->id, 'checkout_required' => false, 'calibration_required' => false,
                'maintenance_required' => true, 'maintenance_interval_value' => 12, 'maintenance_interval_unit' => 'MONTHS',
            ]);

        // --- Equipment (Section 25) ---
        $products['TEST-EQP-001'] = $this->make($tenant, 'TEST-EQP-001', '[TEST] Engine Diagnostic Scanner (Standard)', $cat['EQUIPMENT']->id, 'EQUIPMENT', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Launch', 'track_serial_number' => true],
            [
                'model' => 'X-431 PRO', 'equipment_type_id' => $masterData->equipmentType->id,
                'power_source' => 'ELECTRIC', 'voltage_v' => 220,
                'maintenance_required' => false, 'inspection_required' => false, 'calibration_required' => false,
            ]);
        $products['TEST-EQP-002'] = $this->make($tenant, 'TEST-EQP-002', '[TEST] Hydraulic Lift 2-Post (Full Compliance)', $cat['EQUIPMENT']->id, 'EQUIPMENT', $uom['PCS']->id, $binA2_01,
            ['brand' => 'Rotary', 'track_serial_number' => true],
            [
                'model' => 'SPOA9', 'equipment_type_id' => $masterData->equipmentType->id,
                'power_source' => 'HYDRAULIC', 'power_rating_value' => 2.2, 'power_rating_unit' => 'KW',
                'maintenance_required' => true, 'maintenance_interval_value' => 6, 'maintenance_interval_unit' => 'MONTHS',
                'inspection_required' => true, 'inspection_interval_value' => 12, 'inspection_interval_unit' => 'MONTHS',
                'calibration_required' => true, 'calibration_interval_value' => 12, 'calibration_interval_unit' => 'MONTHS',
                'certification_required' => true, 'certification_type' => 'SNI Lift Certification',
            ]);

        return (object) ['bySku' => $products];
    }

    /**
     * Baseline taxonomy (Component Group code, Category code) per fixture — Category is
     * mandatory for Sparepart/Consumable/Tire/Rim; Tools/Equipment stay unclassified.
     */
    private const CLASSIFICATION = [
        'TEST-SP-001' => ['CG-BRAKE', 'DISC_BRAKE'],
        'TEST-SP-002' => ['CG-ELEC', 'CHARGING'],
        'TEST-SP-003' => ['CG-BRAKE', 'ABS'],
        'TEST-SP-004' => ['CG-CLUTCH', 'CLUTCH_DISC_ASSEMBLY'],
        'TEST-SP-005' => ['CG-ELEC', 'WIPER'],
        'TEST-CS-001' => ['CG-ENGINE-LUBE', 'ENGINE_LUBRICANT'],
        'TEST-CS-002' => ['CG-ENGINE-LUBE', 'ENGINE_LUBRICANT'],
        'TEST-CS-003' => ['CG-ENGINE-COOL', 'COOLANT'],
        'TEST-CS-004' => ['CG-BRAKE', 'CHEMICAL'],
        'TEST-CS-005' => ['CG-ENGINE-LUBE', 'GREASE'],
        'TEST-RIM-001' => ['CG-TYRE', 'ALLOY_RIM'],
        'TEST-RIM-002' => ['CG-TYRE', 'STEEL_RIM'],
        'TEST-TIRE-CAR-001' => ['CG-TYRE', 'PASSENGER_TIRE'],
        'TEST-TIRE-TRUCK-001' => ['CG-TYRE', 'TRUCK_TIRE'],
    ];

    /**
     * $key is this seeder's own fixture key (callers reference products by it); the SKU itself
     * is server-generated. Idempotent on tenant + name; otherwise exactly the API creation path
     * (ProductCreationService), so every fixture is a valid, user-creatable Product.
     */
    private function make(Tenant $tenant, string $key, string $name, string $categoryId, string $productType, string $uomId, string $binId, array $general, array $spec): Product
    {
        $existing = Product::query()->where('tenant_id', $tenant->id)->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        $classification = [];
        if ($codes = self::CLASSIFICATION[$key] ?? null) {
            $group = ComponentGroup::query()->whereNull('tenant_id')->where('code', $codes[0])->firstOrFail();
            $category = ComponentCategory::query()->whereNull('tenant_id')->where('component_group_id', $group->id)->where('code', $codes[1])->firstOrFail();
            $classification = ['component_group_id' => $group->id, 'component_category_id' => $category->id];
        }

        return app(ProductCreationService::class)->createFromInput($tenant->id, [
            'name' => $name, 'product_category_id' => $categoryId, 'product_type' => $productType,
            'uom_id' => $uomId, 'default_storage_bin_id' => $binId, 'spec' => $spec,
        ] + $general + $classification);
    }
}
