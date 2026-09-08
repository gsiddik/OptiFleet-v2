<?php

namespace Database\Seeders;

use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Models\ModuleDependency;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['code' => 'CORE', 'name' => 'Core Foundation', 'category' => 'Foundation', 'is_core' => true, 'is_sellable' => false],
            ['code' => 'ORGANIZATION', 'name' => 'Organization Management', 'category' => 'Foundation', 'is_core' => true, 'is_sellable' => false],
            ['code' => 'ACCESS_MANAGEMENT', 'name' => 'Access Management', 'category' => 'Foundation', 'is_core' => true, 'is_sellable' => false],
            ['code' => 'CONFIGURATION', 'name' => 'Configuration', 'category' => 'Foundation', 'is_core' => true, 'is_sellable' => false],
            ['code' => 'VEHICLE', 'name' => 'Vehicle Registry', 'category' => 'Fleet', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'INSPECTION', 'name' => 'Inspection', 'category' => 'Maintenance', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'MAINTENANCE', 'name' => 'Maintenance Planning', 'category' => 'Maintenance', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'WORK_ORDER', 'name' => 'Work Order', 'category' => 'Maintenance', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'WORKSHOP', 'name' => 'Workshop Operations', 'category' => 'Maintenance', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'INVENTORY', 'name' => 'Inventory', 'category' => 'Supply Chain', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'PROCUREMENT', 'name' => 'Procurement', 'category' => 'Supply Chain', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'TIRE', 'name' => 'Tire Management', 'category' => 'Fleet', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'COMPONENT', 'name' => 'Component Tracking', 'category' => 'Fleet', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'PARTNER', 'name' => 'Partner / Vendor', 'category' => 'Supply Chain', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'WARRANTY', 'name' => 'Warranty', 'category' => 'Fleet', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'TELEMATICS', 'name' => 'Telematics', 'category' => 'Intelligence', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'MAINTENANCE_INTELLIGENCE', 'name' => 'Maintenance Intelligence', 'category' => 'Intelligence', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'HISTORY', 'name' => 'History & Records', 'category' => 'Intelligence', 'is_core' => false, 'is_sellable' => true],
            ['code' => 'REPORT', 'name' => 'Reporting', 'category' => 'Reporting', 'is_core' => false, 'is_sellable' => true],

            // Phase 6: Analytics & Data Warehouse (Section 48)
            ['code' => 'ANALYTICS', 'name' => 'Analytics & Data Warehouse', 'category' => 'Intelligence', 'is_core' => false, 'is_sellable' => true],
        ];

        foreach ($modules as $module) {
            Module::query()->updateOrCreate(
                ['code' => $module['code']],
                $module + ['description' => null, 'status' => 'ACTIVE']
            );
        }

        $dependencies = [
            'WORK_ORDER' => ['VEHICLE', 'MAINTENANCE', 'WORKSHOP'],
            'PROCUREMENT' => ['INVENTORY', 'PARTNER'],
            // Phase 7 Section 59: TELEMATICS has no implementation (module
            // row only) and requiring it here would make this module
            // permanently ungrantable to any tenant. ANALYTICS is the real
            // upstream data source (Phase 6 daily_vehicle_health etc.).
            'MAINTENANCE_INTELLIGENCE' => ['VEHICLE', 'MAINTENANCE', 'HISTORY', 'ANALYTICS'],
            'ANALYTICS' => ['HISTORY'],
            'INSPECTION' => ['VEHICLE'],
            'MAINTENANCE' => ['VEHICLE'],
            'TIRE' => ['VEHICLE'],
            'COMPONENT' => ['VEHICLE'],
            'WARRANTY' => ['VEHICLE'],
        ];

        foreach ($dependencies as $code => $dependsOnCodes) {
            $module = Module::query()->where('code', $code)->first();
            foreach ($dependsOnCodes as $dependsOnCode) {
                $dependsOn = Module::query()->where('code', $dependsOnCode)->first();
                ModuleDependency::query()->firstOrCreate([
                    'module_id' => $module->id,
                    'depends_on_module_id' => $dependsOn->id,
                ]);
            }
        }
    }
}
