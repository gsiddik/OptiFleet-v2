<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Domain\ProductCatalog\Models\Module;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deployment-readiness audit: DEV/DEMO ONLY — never part of the
 * production bootstrap path (`DatabaseSeeder`). Creates a hardcoded
 * platform superadmin login (admin@optifleet.test / "password") for
 * local development convenience, plus two fully-populated demo tenants
 * (ALPHA/BETA). Run explicitly via `php artisan db:seed
 * --class=DevDemoSeeder` (which calls this together with CommercialSeeder/
 * OperationsSeeder/SupplyChainSeeder in the correct order) — never by a
 * production deployment. For a real environment's first admin account use
 * `php artisan platform:create-admin` instead (reads credentials from
 * the environment, never hardcodes them). The "Platform Superadmin" ROLE
 * this class also creates is duplicated, safely and idempotently, by
 * PlatformSuperadminRoleSeeder in the production bootstrap chain — that
 * duplication is intentional so this class stays runnable standalone.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::query()->updateOrCreate(
            ['tenant_id' => null, 'name' => 'Platform Superadmin', 'scope' => 'platform'],
            ['is_system' => true, 'description' => 'Full platform access.']
        );
        $superAdminRole->permissions()->sync(Permission::query()->where('scope', 'platform')->pluck('id'));

        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'admin@optifleet.test'],
            ['name' => 'Platform Superadmin', 'password' => Hash::make('password'), 'user_type' => 'platform', 'status' => 'active']
        );
        RoleAssignment::query()->firstOrCreate(['user_id' => $superAdmin->id, 'tenant_id' => null, 'role_id' => $superAdminRole->id]);

        $alpha = $this->buildTenant('ALPHA', 'PT Alpha Fleet', [
            ['code' => 'ALPHA-JKT', 'name' => 'Jakarta Branch', 'city' => 'Jakarta', 'province' => 'DKI Jakarta'],
            ['code' => 'ALPHA-BDG', 'name' => 'Bandung Branch', 'city' => 'Bandung', 'province' => 'West Java'],
        ], ['CORE', 'ORGANIZATION', 'ACCESS_MANAGEMENT', 'CONFIGURATION', 'VEHICLE', 'INSPECTION', 'MAINTENANCE', 'WORK_ORDER', 'WORKSHOP', 'HISTORY', 'INVENTORY', 'PROCUREMENT', 'PARTNER', 'TIRE', 'COMPONENT', 'WARRANTY'], [
            'branch' => 5, 'user' => 20, 'workshop' => 10, 'warehouse' => 10, 'vehicle' => 100,
        ]);

        $beta = $this->buildTenant('BETA', 'PT Beta Logistics', [
            ['code' => 'BETA-SBY', 'name' => 'Surabaya Branch', 'city' => 'Surabaya', 'province' => 'East Java'],
        ], ['CORE', 'ORGANIZATION', 'ACCESS_MANAGEMENT', 'CONFIGURATION', 'VEHICLE', 'WORKSHOP'], [
            'branch' => 2, 'user' => 5, 'workshop' => 3, 'warehouse' => 3, 'vehicle' => 20,
        ]);
    }

    private function buildTenant(string $code, string $name, array $branchDefs, array $moduleCodes, array $capacityLimits): Tenant
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'status' => 'ACTIVE']
        );

        // Roles
        $adminRole = $this->makeTenantRole($tenant, 'Tenant Admin', Permission::query()->where('scope', 'tenant')->pluck('id')->all());
        $fleetManagerRole = $this->makeTenantRole($tenant, 'Fleet Manager', Permission::query()->where('scope', 'tenant')
            ->whereIn('name', array_merge(
                ['branch.view', 'workshop.view', 'workshop.update', 'warehouse.view', 'warehouse.update', 'vehicle_category.view', 'component_group.view'],
                // Phase 3: day-to-day operational access, short of final approvals/QC sign-off.
                ['vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.assign', 'vehicle.transfer', 'vehicle.status.update'],
                ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit'],
                ['maintenance_policy.view', 'maintenance_schedule.view'],
                ['maintenance_request.view', 'maintenance_request.create'],
                ['breakdown.view', 'breakdown.report'],
                ['work_order.view', 'work_order.create', 'work_order.update'],
                ['diagnosis.manage', 'maintenance_job.manage'],
                ['worker.view', 'workspace.view'],
                ['maintenance_history.view'],
            ))
            ->pluck('id')->all());
        // Phase 3: workshop-side approvals/execution oversight, scoped to a
        // single workshop per Section 48's example (assigned below).
        $workshopManagerRole = $this->makeTenantRole($tenant, 'Workshop Manager', Permission::query()->where('scope', 'tenant')
            ->whereIn('name', [
                'vehicle.view', 'vehicle.status.update',
                'inspection.view', 'inspection.review',
                'maintenance_policy.view', 'maintenance_schedule.view', 'maintenance_schedule.manage',
                'maintenance_request.view', 'maintenance_request.review', 'maintenance_request.approve', 'maintenance_request.reject', 'maintenance_request.convert_work_order',
                'breakdown.view', 'breakdown.review', 'breakdown.resolve',
                'work_order.view', 'work_order.submit', 'work_order.approve', 'work_order.reject', 'work_order.assign', 'work_order.schedule', 'work_order.start', 'work_order.pause', 'work_order.complete', 'work_order.cancel', 'work_order.close',
                'diagnosis.manage', 'maintenance_job.manage',
                // Phase E: the Workshop Manager is the final-inspection/approval authority
                // for tire retread/repair cycles — a distinct actor from whoever sent or
                // received the tire (Warehouse Manager, below), by design (G-32).
                'tire_retread.inspect', 'tire_retread.approve', 'tire_repair.inspect', 'tire_repair.approve',
                'worker.view', 'worker.manage', 'worker.assign',
                'workspace.view', 'workspace.manage', 'workspace.reserve', 'workspace.block',
                'qc.view', 'qc.perform', 'qc.approve', 'qc.reject',
                'vehicle_release.perform', 'maintenance_history.view',
            ])
            ->pluck('id')->all());
        // Phase 4: warehouse-scoped supply-chain operator (assigned to a
        // specific warehouse below to exercise Section 47's cross-warehouse
        // scope example).
        $warehouseManagerRole = $this->makeTenantRole($tenant, 'Warehouse Manager', Permission::query()->where('scope', 'tenant')
            ->whereIn('name', [
                'product.view',
                'inventory.view', 'inventory.issue', 'inventory.return', 'inventory.adjust', 'inventory.stock_opname',
                // Part Requests are approved and issued by the warehouse; returned new parts are inspected there too.
                'part_request.view', 'part_request.approve', 'part_request.reject', 'part_request.issue', 'part_return.view', 'part_return.process',
                'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.dispatch', 'stock_transfer.receive',
                'purchase_request.view', 'purchase_request.create',
                'goods_receipt.view', 'goods_receipt.create', 'goods_receipt.post',
                'partner.view',
                'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap',
                // Phase E: send/receive are logistics actions (Warehouse Manager); final
                // inspection/approval sit with the Workshop Manager instead (G-32).
                'tire_retread.send', 'tire_retread.receive', 'tire_repair.send', 'tire_repair.receive',
                'component_asset.view', 'component_asset.manage', 'component_asset.install', 'component_asset.remove', 'component_asset.replace',
                'warranty.view', 'warranty.manage', 'warranty_claim.create',
            ])
            ->pluck('id')->all());
        $this->makeTenantRole($tenant, 'Auditor', Permission::query()->where('scope', 'tenant')
            ->where(function ($q) {
                $q->where('name', 'like', '%.view')->orWhere('name', 'audit.view');
            })
            ->pluck('id')->all());

        // Users
        $adminEmail = strtolower($code).'.admin@optifleet.test';
        $admin = User::query()->updateOrCreate(
            ['email' => $adminEmail],
            ['name' => $name.' Admin', 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']
        );
        TenantUser::query()->firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $admin->id], ['status' => 'active', 'joined_at' => now()]);
        RoleAssignment::query()->firstOrCreate(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'role_id' => $adminRole->id]);
        DataScopeAssignment::query()->firstOrCreate([
            'user_id' => $admin->id, 'tenant_id' => $tenant->id, 'scope_type' => 'TENANT', 'scope_resource_id' => null,
        ]);

        $managerEmail = strtolower($code).'.manager@optifleet.test';
        $manager = User::query()->updateOrCreate(
            ['email' => $managerEmail],
            ['name' => $name.' Fleet Manager', 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']
        );
        TenantUser::query()->firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $manager->id], ['status' => 'active', 'joined_at' => now()]);
        RoleAssignment::query()->firstOrCreate(['user_id' => $manager->id, 'tenant_id' => $tenant->id, 'role_id' => $fleetManagerRole->id]);

        // Branches / workshops / warehouses
        $firstBranch = null;
        foreach ($branchDefs as $branchDef) {
            $branch = Branch::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $branchDef['code']],
                $branchDef + ['tenant_id' => $tenant->id, 'status' => 'ACTIVE']
            );
            $firstBranch ??= $branch;

            $workshop = Workshop::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $branchDef['code'].'-WS1'],
                [
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id,
                    'name' => $branch->name.' Workshop',
                    'workshop_type' => 'INTERNAL',
                    'capacity' => 10,
                    'number_of_service_bays' => 4,
                    'status' => 'ACTIVE',
                ]
            );

            Warehouse::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $branchDef['code'].'-WH1'],
                [
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id,
                    'workshop_id' => $workshop->id,
                    'name' => $branch->name.' Warehouse',
                    'warehouse_type' => 'WORKSHOP',
                    'status' => 'ACTIVE',
                ]
            );
        }

        // Fleet manager scoped to the first branch only, to demonstrate data-scope restriction
        if ($firstBranch) {
            DataScopeAssignment::query()->firstOrCreate([
                'user_id' => $manager->id, 'tenant_id' => $tenant->id, 'scope_type' => 'BRANCH', 'scope_resource_id' => $firstBranch->id,
            ]);
        }

        // Module entitlements
        foreach ($moduleCodes as $moduleCode) {
            $module = Module::query()->where('code', $moduleCode)->first();
            if (! $module) {
                continue;
            }
            TenantModuleEntitlement::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                ['active' => true, 'source' => 'seed']
            );
        }

        // Capacity limits
        foreach ($capacityLimits as $resourceType => $maxCount) {
            TenantCapacityLimit::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'resource_type' => $resourceType],
                ['max_count' => $maxCount]
            );
        }

        return $tenant;
    }

    private function makeTenantRole(Tenant $tenant, string $name, array $permissionIds): Role
    {
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $name, 'scope' => 'tenant'],
            ['is_system' => true, 'description' => $name.' role for '.$tenant->name]
        );
        $role->permissions()->sync($permissionIds);

        return $role;
    }
}
