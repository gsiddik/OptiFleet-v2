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
        ], ['CORE', 'ORGANIZATION', 'ACCESS_MANAGEMENT', 'CONFIGURATION', 'VEHICLE', 'INSPECTION', 'MAINTENANCE', 'WORKSHOP', 'INVENTORY'], [
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
            ->whereIn('name', ['branch.view', 'workshop.view', 'workshop.update', 'warehouse.view', 'warehouse.update', 'vehicle_category.view', 'component_group.view'])
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
