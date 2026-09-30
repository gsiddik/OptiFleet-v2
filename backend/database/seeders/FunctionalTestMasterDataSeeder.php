<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Tenant;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\ProductMaster\Models\EquipmentType;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\StorageRequirement;
use App\Domain\ProductMaster\Models\ToolType;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TirePlyRating;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Models\TireTraCode;
use App\Domain\Tire\Models\TireTraStarRating;

/**
 * Tenant-scoped master/reference data the Product and Vehicle domains need
 * (Section 16): vehicle brand/model, the six item-type-scoped Product
 * Categories, UOMs (incl. a conversion pair for the Consumable scenario),
 * Tool/Equipment Type + Storage Requirement lookups, and the five tire
 * reference tables the Dynamic Product Tire spec is FK-bound to. Reference
 * data (no workflow behavior), so direct Eloquent create is appropriate
 * (Section 7). All tenant-scoped, never touching global/system rows, per
 * CLAUDE.md's tenant-isolation invariant.
 *
 * @return object{
 *   vehicleCategories: array<string, VehicleCategory>,
 *   vehicleBrands: array<string, VehicleBrand>,
 *   vehicleModels: array<string, VehicleModelMaster>,
 *   productCategories: array<string, ProductCategory>,
 *   uoms: array<string, Uom>,
 *   toolType: ToolType,
 *   equipmentType: EquipmentType,
 *   storageRequirement: StorageRequirement,
 *   tireRefs: array<string, mixed>,
 * }
 */
class FunctionalTestMasterDataSeeder
{
    public function run(Tenant $tenant): object
    {
        $vehicleCategories = [
            'CAR' => VehicleCategory::query()->where('code', 'VC-PCAR')->whereNull('tenant_id')->firstOrFail(),
            'TRUCK' => VehicleCategory::query()->where('code', 'VC-TRUCK')->whereNull('tenant_id')->firstOrFail(),
            'BUS' => VehicleCategory::query()->where('code', 'VC-BUS')->whereNull('tenant_id')->firstOrFail(),
        ];

        $toyota = VehicleBrand::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-TOYOTA'],
            ['name' => '[TEST] Toyota', 'is_system' => false, 'status' => 'ACTIVE']
        );
        $hino = VehicleBrand::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-HINO'],
            ['name' => '[TEST] Hino', 'is_system' => false, 'status' => 'ACTIVE']
        );
        // "Brand Of" links to the Vehicle Category master (syncWithoutDetaching: never drops a link added in the UI).
        $toyota->vehicleCategories()->syncWithoutDetaching([$vehicleCategories['CAR']->id]);
        $hino->vehicleCategories()->syncWithoutDetaching([$vehicleCategories['TRUCK']->id, $vehicleCategories['BUS']->id]);

        $vehicleModels = [
            'AVANZA' => VehicleModelMaster::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'vehicle_brand_id' => $toyota->id, 'code' => 'TEST-AVANZA'],
                ['name' => '[TEST] Avanza', 'is_system' => false, 'status' => 'ACTIVE']
            ),
            'RANGER_FG' => VehicleModelMaster::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'vehicle_brand_id' => $hino->id, 'code' => 'TEST-RANGERFG'],
                ['name' => '[TEST] Ranger FG', 'is_system' => false, 'status' => 'ACTIVE']
            ),
        ];

        $productCategories = [];
        foreach ([
            'SPARE_PART' => ['PC-TEST-SPARE', '[TEST] Sparepart'],
            'CONSUMABLE' => ['PC-TEST-CONSUMABLE', '[TEST] Consumable'],
            'RIM' => ['PC-TEST-RIM', '[TEST] Rim'],
            'TIRE' => ['PC-TEST-TIRE', '[TEST] Tire'],
            'TOOL' => ['PC-TEST-TOOL', '[TEST] Tool'],
            'EQUIPMENT' => ['PC-TEST-EQUIPMENT', '[TEST] Equipment'],
        ] as $itemType => [$code, $name]) {
            $productCategories[$itemType] = ProductCategory::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'item_type' => $itemType, 'is_system' => false, 'status' => 'ACTIVE']
            );
        }

        $uoms = [
            'PCS' => Uom::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'TEST-PCS'],
                ['name' => '[TEST] Piece', 'measure_type' => 'PACKAGING', 'is_system' => false, 'status' => 'ACTIVE']
            ),
            'LTR' => Uom::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'TEST-LTR'],
                ['name' => '[TEST] Liter', 'measure_type' => 'CAPACITY', 'is_system' => false, 'status' => 'ACTIVE']
            ),
            'DRM' => Uom::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'TEST-DRM'],
                ['name' => '[TEST] Drum (200L)', 'measure_type' => 'PACKAGING', 'is_system' => false, 'status' => 'ACTIVE']
            ),
        ];

        $toolType = ToolType::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-HANDTOOL'],
            ['name' => '[TEST] Hand Tool', 'is_system' => false, 'status' => 'ACTIVE']
        );
        $equipmentType = EquipmentType::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-DIAGEQP'],
            ['name' => '[TEST] Diagnostic Equipment', 'is_system' => false, 'status' => 'ACTIVE']
        );
        $storageRequirement = StorageRequirement::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-FLAMMABLE'],
            ['name' => '[TEST] Flammable Storage Cabinet', 'is_system' => false, 'status' => 'ACTIVE']
        );

        $tireRefs = $this->seedTireReferences($tenant);

        return (object) [
            'vehicleCategories' => $vehicleCategories,
            'vehicleBrands' => ['TOYOTA' => $toyota, 'HINO' => $hino],
            'vehicleModels' => $vehicleModels,
            'productCategories' => $productCategories,
            'uoms' => $uoms,
            'toolType' => $toolType,
            'equipmentType' => $equipmentType,
            'storageRequirement' => $storageRequirement,
            'tireRefs' => $tireRefs,
        ];
    }

    private function seedTireReferences(Tenant $tenant): array
    {
        $loadIndexCar = TireLoadIndex::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-LI-82'],
            ['max_load_single_kg' => 475, 'max_load_dual_kg' => null, 'is_system' => false, 'status' => 'ACTIVE']
        );
        $loadIndexTruck = TireLoadIndex::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-LI-146'],
            ['max_load_single_kg' => 3000, 'max_load_dual_kg' => 2725, 'is_system' => false, 'status' => 'ACTIVE']
        );

        $speedRatingCar = TireSpeedRating::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-H'],
            ['max_speed_kmh' => 210, 'is_system' => false, 'status' => 'ACTIVE']
        );
        $speedRatingTruck = TireSpeedRating::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-F'],
            ['max_speed_kmh' => 80, 'is_system' => false, 'status' => 'ACTIVE']
        );

        $plyRating = TirePlyRating::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-16PR'],
            ['load_range' => 'G', 'is_system' => false, 'status' => 'ACTIVE']
        );

        $traCode = TireTraCode::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-A1'],
            ['profile' => 'Traction', 'is_system' => false, 'status' => 'ACTIVE']
        );
        $traStarRating = TireTraStarRating::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tra_code_id' => $traCode->id, 'star_rating' => 2],
            ['purpose' => 'On/Off Road']
        );

        return [
            'loadIndexCar' => $loadIndexCar,
            'loadIndexTruck' => $loadIndexTruck,
            'speedRatingCar' => $speedRatingCar,
            'speedRatingTruck' => $speedRatingTruck,
            'plyRating' => $plyRating,
            'traCode' => $traCode,
            'traStarRating' => $traStarRating,
        ];
    }
}
