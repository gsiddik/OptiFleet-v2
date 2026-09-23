<?php

namespace Database\Seeders;

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
use Illuminate\Database\Seeder;

/**
 * Deployment-readiness audit: the Dynamic Product Form's reference/master
 * data (Product Category per Item Type, base UOM, Tire Load Index/Speed
 * Rating/Ply Rating/TRA Code, Tool Type, Equipment Type, Storage
 * Requirement) had no standalone production-safe seeder anywhere in the
 * repository — it only existed as an incidental side effect of
 * SupplyChainSeeder, which itself hard-requires the demo tenant "ALPHA"
 * (`firstOrFail()`) and is therefore unreachable in a production
 * deployment that skips demo data. Worse, SupplyChainSeeder's own Tire
 * reference rows were tenant-scoped to ALPHA specifically rather than
 * global, meaning even a fresh non-demo tenant created in production would
 * have had zero Tire reference options to select from.
 *
 * This seeder is the global (tenant_id=null, is_system=true) counterpart —
 * every tenant can select these system defaults immediately, and (per the
 * existing architecture already used by VehicleCategory/ComponentGroup/
 * WorkerType) can still layer its own tenant-scoped custom rows on top,
 * since every one of these tables' unique constraint is (tenant_id, code),
 * not code alone.
 *
 * Values are deliberately ordinary, realistic defaults (not placeholders)
 * matching the same worked examples already used in this codebase's own
 * demo data and requirement-document examples, so a fresh tenant's first
 * Product/Tire/Tool/Equipment creation has real, usable options rather
 * than an empty dropdown.
 */
class ProductReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedProductCategories();
        $this->seedUoms();
        $this->seedTireReferences();
        $this->seedToolTypes();
        $this->seedEquipmentTypes();
        $this->seedStorageRequirements();
    }

    /** "Next Improvement Tenant Portal - Products": Item Type dropdown = exactly these 6 values. */
    private function seedProductCategories(): void
    {
        $categories = [
            ['code' => 'PC-SPAREPART', 'name' => 'Spare Parts', 'item_type' => 'SPARE_PART'],
            ['code' => 'PC-CONSUMABLE', 'name' => 'Consumables', 'item_type' => 'CONSUMABLE'],
            ['code' => 'PC-RIM', 'name' => 'Rims', 'item_type' => 'RIM'],
            ['code' => 'PC-TIRE', 'name' => 'Tires', 'item_type' => 'TIRE'],
            ['code' => 'PC-TOOL', 'name' => 'Tools', 'item_type' => 'TOOL'],
            ['code' => 'PC-EQUIPMENT', 'name' => 'Equipment', 'item_type' => 'EQUIPMENT'],
        ];

        foreach ($categories as $category) {
            ProductCategory::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $category['code']],
                $category + ['is_system' => true, 'status' => 'ACTIVE', 'description' => null, 'parent_id' => null, 'requires_specification_grade' => false]
            );
        }
    }

    /** Doc's own example list: "PCS, SET, PAIR, Liter, Kg, dll." */
    private function seedUoms(): void
    {
        $uoms = [
            ['code' => 'PCS', 'name' => 'Piece'],
            ['code' => 'SET', 'name' => 'Set'],
            ['code' => 'PAIR', 'name' => 'Pair'],
            ['code' => 'LTR', 'name' => 'Liter'],
            ['code' => 'KG', 'name' => 'Kilogram'],
        ];

        foreach ($uoms as $uom) {
            Uom::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $uom['code']],
                $uom + ['is_system' => true, 'status' => 'ACTIVE']
            );
        }
    }

    /**
     * A small realistic spread covering both Car and Truck/Bus tire
     * creation out of the box — the same LI-146/L/16PR combination
     * SupplyChainSeeder's demo tire already uses (kept consistent, not
     * reinvented), plus a lighter passenger-car-oriented set matching the
     * doc's own worked example ("92 — 630 kg", "T — 190 km/h").
     */
    private function seedTireReferences(): void
    {
        $loadIndices = [
            ['code' => '92', 'max_load_single_kg' => 630, 'max_load_dual_kg' => null],
            ['code' => '146', 'max_load_single_kg' => 3000, 'max_load_dual_kg' => 2725],
            ['code' => '152', 'max_load_single_kg' => 3550, 'max_load_dual_kg' => 3150],
        ];
        foreach ($loadIndices as $li) {
            TireLoadIndex::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $li['code']],
                $li + ['is_system' => true, 'status' => 'ACTIVE']
            );
        }

        $speedRatings = [
            ['code' => 'T', 'max_speed_kmh' => 190],
            ['code' => 'L', 'max_speed_kmh' => 120],
            ['code' => 'M', 'max_speed_kmh' => 130],
        ];
        foreach ($speedRatings as $sr) {
            TireSpeedRating::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $sr['code']],
                $sr + ['is_system' => true, 'status' => 'ACTIVE']
            );
        }

        $plyRatings = [
            ['code' => '16PR', 'load_range' => 'G'],
            ['code' => '14PR', 'load_range' => 'H'],
        ];
        foreach ($plyRatings as $pr) {
            TirePlyRating::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $pr['code']],
                $pr + ['is_system' => true, 'status' => 'ACTIVE']
            );
        }

        $traCode = TireTraCode::query()->updateOrCreate(
            ['tenant_id' => null, 'code' => 'G2'],
            ['profile' => 'G', 'is_system' => true, 'status' => 'ACTIVE']
        );
        TireTraStarRating::query()->updateOrCreate(
            ['tenant_id' => null, 'tra_code_id' => $traCode->id, 'star_rating' => '2★'],
            ['purpose' => 'Strength']
        );
    }

    /** Doc: "Hand Tool / Power Tool / Measuring Tool / Diagnostic Tool / Lifting Tool / Special Service Tool". */
    private function seedToolTypes(): void
    {
        $types = ['Hand Tool', 'Power Tool', 'Measuring Tool', 'Diagnostic Tool', 'Lifting Tool', 'Special Service Tool'];
        foreach ($types as $name) {
            ToolType::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => str_replace(' ', '_', strtoupper($name))],
                ['name' => $name, 'is_system' => true, 'status' => 'ACTIVE', 'description' => null]
            );
        }
    }

    /** Doc: "Lifting / Tire Service / Wheel Alignment / Compressor / Diagnostic / Welding / Cleaning / Lubrication / Workshop Machinery". */
    private function seedEquipmentTypes(): void
    {
        $types = ['Lifting', 'Tire Service', 'Wheel Alignment', 'Compressor', 'Diagnostic', 'Welding', 'Cleaning', 'Lubrication', 'Workshop Machinery'];
        foreach ($types as $name) {
            EquipmentType::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => str_replace(' ', '_', strtoupper($name))],
                ['name' => $name, 'is_system' => true, 'status' => 'ACTIVE', 'description' => null]
            );
        }
    }

    /** Standard hazmat storage categories for Consumable's Hazardous Material handling. */
    private function seedStorageRequirements(): void
    {
        $requirements = ['Cool Storage', 'Dry Storage', 'Flammable Storage', 'Ventilated Area', 'Away From Food'];
        foreach ($requirements as $name) {
            StorageRequirement::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => str_replace(' ', '_', strtoupper($name))],
                ['name' => $name, 'is_system' => true, 'status' => 'ACTIVE', 'description' => null]
            );
        }
    }
}
