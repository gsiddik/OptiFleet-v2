<?php

namespace Database\Seeders;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Services\ComponentAssetService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\ProductMaster\Services\ProductCreationService;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use App\Domain\Tire\Services\RimRegistrationService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tire Management → Rim demo data for the demo tenant (ALPHA), through the same services as the UI:
 *
 *  - two Rim Products (ProductCreationService, Item Type RIM, Wheel & Tyre System, serial-tracked):
 *    a steel truck / bus rim and an alloy car / van rim;
 *  - Installed rims on every wheel position of every vehicle with a Wheels Configuration (the usual
 *    case — rims are mostly already on vehicles), next to the tires DemoDatasetSeeder registered;
 *  - a small New Stock (2 per product) in the Jakarta warehouse;
 *  - one Used (removed) rim per product.
 *
 * Idempotent: serial numbers are deterministic; existing products, serials and occupied positions are
 * skipped. Runs after DemoDatasetSeeder (vehicles + Wheels Configuration mappings).
 */
class RimDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'ALPHA')->first();
        if (! $tenant) {
            return;
        }
        $context = app(TenantContext::class);
        $previous = $context->tenantId();
        $context->setTenantId($tenant->id);
        try {
            $this->seed($tenant);
        } finally {
            $context->setTenantId($previous);
        }
    }

    private function seed(Tenant $tenant): void
    {
        $warehouse = Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'ALPHA-JKT-WH1')->first();
        if (! $warehouse) {
            return;
        }
        $steel = $this->product($tenant, $warehouse, 'Steel Rim 22.5 x 8.25 (Truck & Bus)', 'Accuride', 'STEEL_RIM',
            ['model' => '29806', 'rim_type' => 'STEEL', 'diameter_inch' => 22.5, 'width_inch' => 8.25, 'bolt_holes' => 10, 'pcd_mm' => 335, 'center_bore_mm' => 281, 'offset_mm' => 168, 'material' => 'Steel', 'max_load_kg' => 3550]);
        $alloy = $this->product($tenant, $warehouse, 'Alloy Rim 15 x 6 (Car & Van)', 'Enkei', 'ALLOY_RIM',
            ['model' => 'PF01', 'rim_type' => 'ALLOY', 'diameter_inch' => 15, 'width_inch' => 6, 'bolt_holes' => 4, 'pcd_mm' => 100, 'center_bore_mm' => 56.1, 'offset_mm' => 45, 'material' => 'Aluminium Alloy']);
        if (! $steel || ! $alloy) {
            return;
        }
        $registration = app(RimRegistrationService::class);

        // New Stock: a few spare rims in the warehouse.
        foreach ([$steel, $alloy] as $product) {
            for ($n = 1; $n <= 2; $n++) {
                $this->register($tenant, $product, ['serial_number' => $this->serial($product, "NEW-{$n}"), 'warehouse_id' => $warehouse->id, 'purchase_date' => now()->subDays(30 + $n)->toDateString()]);
            }
        }

        $mappings = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)->get();
        $usedDone = [];
        foreach ($mappings as $mapping) {
            $vehicle = Vehicle::query()->withoutGlobalScopes()->find($mapping->vehicle_id);
            if (! $vehicle) {
                continue;
            }
            $product = in_array(strtoupper((string) $vehicle->vehicle_type), ['CAR', 'PASSENGER_CAR', 'VAN', 'SEDAN', 'MPV', 'SUV'], true) || $mapping->master?->vehicle_type === 'PASSENGER_CAR' || $mapping->master?->vehicle_type === 'VAN'
                ? $alloy : $steel;
            $positions = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
                ->where('wheel_configuration_version_id', $mapping->wheel_configuration_version_id)->orderBy('position_code')->pluck('position_code');
            foreach ($positions as $position) {
                // Used Stock: once per product, a rim that was on a position and was removed.
                if (! isset($usedDone[$product->id])) {
                    $usedDone[$product->id] = true;
                    $used = $this->register($tenant, $product, ['serial_number' => $this->serial($product, 'USED-1'), 'vehicle_id' => $vehicle->id, 'position_code' => $position]);
                    if ($used && $used->current_status === 'INSTALLED') {
                        app(ComponentAssetService::class)->remove($used, 'Bent flange (demo)', 'REUSE', (float) $vehicle->current_odometer, 'Repairable', null, null, null, $warehouse->id);
                    }
                }
                $this->register($tenant, $product, ['serial_number' => $this->serial($product, "{$vehicle->registration_number}|{$position}"), 'vehicle_id' => $vehicle->id, 'position_code' => $position]);
            }
        }
    }

    /** Registers one rim unless its serial exists or the rule rejects it (e.g. position already has a rim). */
    private function register(Tenant $tenant, Product $product, array $data): ?ComponentAsset
    {
        $existing = ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('serial_number', $data['serial_number'])->first();
        if ($existing) {
            return $existing;
        }
        try {
            return app(RimRegistrationService::class)->register($tenant->id, $product, $data, null);
        } catch (ValidationException) {
            return null; // an occupied position on a re-run — never overwrite
        }
    }

    private function serial(Product $product, string $key): string
    {
        return 'RIM-'.strtoupper(substr(hash('sha256', $product->name.'|'.$key), 0, 10));
    }

    private function product(Tenant $tenant, Warehouse $warehouse, string $name, string $brand, string $categoryCode, array $spec): ?Product
    {
        $existing = Product::query()->where('tenant_id', $tenant->id)->where('product_type', 'RIM')->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }
        $group = ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-TYRE')->first();
        $category = $group ? ComponentCategory::query()->whereNull('tenant_id')->where('component_group_id', $group->id)->where('code', $categoryCode)->first() : null;
        $productCategory = ProductCategory::query()->whereNull('tenant_id')->where('code', 'PC-RIM')->first();
        $uom = Uom::query()->whereNull('tenant_id')->where('code', 'PCS')->first();
        $bin = DB::table('warehouse_bins as b')->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'r.warehouse_zone_id')->where('z.warehouse_id', $warehouse->id)->orderBy('b.code')->value('b.id');
        if (! $group || ! $category || ! $productCategory || ! $uom || ! $bin) {
            return null;
        }

        return app(ProductCreationService::class)->createFromInput($tenant->id, [
            'name' => $name, 'product_type' => 'RIM', 'brand' => $brand, 'track_serial_number' => true,
            'product_category_id' => $productCategory->id, 'uom_id' => $uom->id, 'default_storage_bin_id' => $bin,
            'component_group_id' => $group->id, 'component_category_id' => $category->id,
            'spec' => $spec,
        ]);
    }
}
