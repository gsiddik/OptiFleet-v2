<?php

namespace Database\Seeders;

use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\WarehouseBin;
use App\Domain\Organization\Models\WarehouseRack;
use App\Domain\Organization\Models\WarehouseZone;
use App\Domain\Organization\Models\Workshop;
use App\Domain\ProductCatalog\Models\Module;

/**
 * The dedicated Functional Test tenant: org structure (branch, workshop,
 * warehouse), the full Warehouse -> Zone -> Rack -> Bin storage hierarchy
 * (Section 17) products need for Default Storage Location, module
 * entitlements, and capacity limits. Kept entirely separate from the
 * ALPHA/BETA/GAMMA/DELTA demo tenants so functional-test transactions never
 * mix with demo data (Section 14).
 */
class FunctionalTestTenantSeeder
{
    public const TENANT_CODE = 'FTEST';

    /** Modules this tenant needs entitled to exercise every seeded flow. MongoDB-backed Analytics/Intelligence modules are deliberately excluded (Section 47). */
    private const MODULE_CODES = [
        'CORE', 'ORGANIZATION', 'ACCESS_MANAGEMENT', 'CONFIGURATION',
        'VEHICLE', 'INSPECTION', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY',
        'INVENTORY', 'PROCUREMENT', 'PARTNER', 'TIRE', 'COMPONENT', 'WARRANTY', 'REPORT',
    ];

    private const CAPACITY_LIMITS = [
        'branch' => 5, 'workshop' => 5, 'warehouse' => 5, 'user' => 30, 'vehicle' => 50,
    ];

    public function run(): Tenant
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['code' => self::TENANT_CODE],
            [
                'name' => '[TEST] Functional Test Tenant',
                'legal_name' => 'PT Functional Test Tenant',
                'industry' => 'Logistics',
                'status' => 'ACTIVE',
                'timezone' => 'Asia/Jakarta',
                'workshop_working_days' => 6,
            ]
        );

        $branch = Branch::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'FTEST-MAIN'],
            [
                'name' => '[TEST] Main Branch', 'city' => 'Jakarta', 'province' => 'DKI Jakarta', 'status' => 'ACTIVE',
            ]
        );

        $workshop = Workshop::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'FTEST-MAIN-WS1'],
            [
                'branch_id' => $branch->id,
                'name' => '[TEST] Main Workshop',
                'workshop_type' => 'INTERNAL',
                'capacity' => 10,
                'number_of_service_bays' => 3,
                'status' => 'ACTIVE',
            ]
        );

        $warehouse = Warehouse::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'FTEST-MAIN-WH1'],
            [
                'branch_id' => $branch->id,
                'workshop_id' => $workshop->id,
                'name' => '[TEST] Main Warehouse',
                'warehouse_type' => 'WORKSHOP',
                'status' => 'ACTIVE',
            ]
        );

        $this->seedStorageHierarchy($tenant, $warehouse);

        foreach (self::MODULE_CODES as $moduleCode) {
            $module = Module::query()->where('code', $moduleCode)->first();
            if (! $module) {
                continue;
            }
            TenantModuleEntitlement::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                ['active' => true, 'source' => 'seed']
            );
        }

        foreach (self::CAPACITY_LIMITS as $resourceType => $maxCount) {
            TenantCapacityLimit::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'resource_type' => $resourceType],
                ['max_count' => $maxCount]
            );
        }

        return $tenant;
    }

    /**
     * Section 17's example hierarchy exactly:
     *   Zone A -> Rack A1 -> Bin A1-01, Bin A1-02
     *          -> Rack A2 -> Bin A2-01
     *   Zone B -> Rack B1 -> Bin B1-01
     */
    private function seedStorageHierarchy(Tenant $tenant, Warehouse $warehouse): void
    {
        $zoneA = WarehouseZone::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'ZONE-A'],
            ['name' => '[TEST] Zone A', 'status' => 'ACTIVE']
        );
        $zoneB = WarehouseZone::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'ZONE-B'],
            ['name' => '[TEST] Zone B', 'status' => 'ACTIVE']
        );

        $rackA1 = WarehouseRack::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zoneA->id, 'code' => 'RACK-A1'],
            ['name' => '[TEST] Rack A1', 'status' => 'ACTIVE']
        );
        $rackA2 = WarehouseRack::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zoneA->id, 'code' => 'RACK-A2'],
            ['name' => '[TEST] Rack A2', 'status' => 'ACTIVE']
        );
        $rackB1 = WarehouseRack::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'warehouse_zone_id' => $zoneB->id, 'code' => 'RACK-B1'],
            ['name' => '[TEST] Rack B1', 'status' => 'ACTIVE']
        );

        foreach ([
            [$rackA1, 'BIN-A1-01'], [$rackA1, 'BIN-A1-02'],
            [$rackA2, 'BIN-A2-01'],
            [$rackB1, 'BIN-B1-01'],
        ] as [$rack, $code]) {
            WarehouseBin::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'warehouse_rack_id' => $rack->id, 'code' => $code],
                ['name' => '[TEST] '.$code, 'status' => 'ACTIVE']
            );
        }
    }
}
