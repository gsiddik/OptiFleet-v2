<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * RBAC users for the Functional Test tenant, using the ACTUAL canonical
 * Role names this codebase defines (Tenant Admin / Fleet Manager /
 * Workshop Manager / Warehouse Manager / Auditor) — confirmed via
 * DemoDataSeeder and PermissionSeeder. "Admin/Operational/Warehouse" from
 * the task brief map onto these; "Lead Mechanic"/"Mechanic" are Worker
 * Types (see FunctionalTestVehicleWorkshopSeeder), not RBAC roles, since
 * mechanics in this app do not authenticate (Section 15's own instruction:
 * do not invent role names the implementation doesn't use).
 */
class FunctionalTestUserSeeder
{
    public const ACCOUNTS = [
        'Tenant Admin' => 'ft.admin@optifleet.test',
        'Fleet Manager (Operational)' => 'ft.fleetmanager@optifleet.test',
        'Workshop Manager' => 'ft.workshopmanager@optifleet.test',
        'Warehouse Manager' => 'ft.warehousemanager@optifleet.test',
        'Auditor' => 'ft.auditor@optifleet.test',
    ];

    public function run(Tenant $tenant): void
    {
        $branch = Branch::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN')->firstOrFail();
        $workshop = Workshop::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN-WS1')->firstOrFail();
        $warehouse = Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN-WH1')->firstOrFail();

        $adminRole = $this->makeRole($tenant, 'Tenant Admin', Permission::query()->where('scope', 'tenant')->pluck('id')->all());

        $fleetManagerRole = $this->makeRole($tenant, 'Fleet Manager', Permission::query()->where('scope', 'tenant')
            ->whereIn('name', [
                'branch.view', 'workshop.view', 'warehouse.view', 'vehicle_category.view', 'component_group.view',
                'vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.assign', 'vehicle.transfer', 'vehicle.status.update',
                'inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit',
                'maintenance_policy.view', 'maintenance_schedule.view',
                'maintenance_request.view', 'maintenance_request.create',
                'breakdown.view', 'breakdown.report',
                'work_order.view', 'work_order.create', 'work_order.update',
                'diagnosis.manage', 'maintenance_job.manage',
                'worker.view', 'workspace.view', 'maintenance_history.view',
                'product.view',
            ])->pluck('id')->all());

        $workshopManagerRole = $this->makeRole($tenant, 'Workshop Manager', Permission::query()->where('scope', 'tenant')
            ->whereIn('name', [
                'vehicle.view', 'vehicle.status.update',
                'inspection.view', 'inspection.review',
                'maintenance_policy.view', 'maintenance_schedule.view', 'maintenance_schedule.manage',
                'maintenance_request.view', 'maintenance_request.review', 'maintenance_request.approve', 'maintenance_request.reject', 'maintenance_request.convert_work_order',
                'breakdown.view', 'breakdown.review', 'breakdown.resolve',
                'work_order.view', 'work_order.submit', 'work_order.approve', 'work_order.reject', 'work_order.assign',
                'work_order.schedule', 'work_order.start', 'work_order.pause', 'work_order.complete', 'work_order.cancel', 'work_order.close',
                'work_order.estimate', 'work_order.prepare_external', 'work_order.finalize_external', 'work_order.revise_external', 'work_order.cancel_external',
                'work_order.view_workshop_invoice_reference',
                'external_work_order_invoice.view', 'external_work_order_invoice.generate_authorization', 'external_work_order_invoice.deliver',
                'external_work_order_invoice.acknowledge', 'external_work_order_invoice.complete', 'external_work_order_invoice.settle', 'external_work_order_invoice.cancel',
                'work_order_external_service.create', 'work_order_external_service.complete', 'work_order_external_service.cancel',
                'workshop_invoice.record', 'workshop_invoice.view', 'workshop_invoice.upload_payment',
                'workshop_invoice.request_correction', 'workshop_invoice.request_cancellation',
                'workshop_invoice.verify_correction', 'workshop_invoice.verify_cancellation', 'workshop_invoice.view_settlement_history',
                'diagnosis.manage', 'maintenance_job.manage', 'part_request.view', 'part_request.approve', 'part_request.reject',
                'worker.view', 'worker.manage', 'worker.assign',
                'workspace.view', 'workspace.manage', 'workspace.reserve', 'workspace.block',
                'qc.view', 'qc.perform', 'qc.approve', 'qc.reject',
                'vehicle_release.perform', 'maintenance_history.view', 'partner.view',
            ])->pluck('id')->all());

        $warehouseManagerRole = $this->makeRole($tenant, 'Warehouse Manager', Permission::query()->where('scope', 'tenant')
            ->whereIn('name', [
                'product.view', 'product.create', 'product.update',
                'inventory.view', 'inventory.issue', 'inventory.return', 'inventory.adjust', 'inventory.stock_opname', 'inventory.scrap',
                // Part Requests are approved and issued by the warehouse; returned new parts are inspected there too.
                'part_request.view', 'part_request.approve', 'part_request.reject', 'part_request.issue', 'part_return.view', 'part_return.process',
                'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.dispatch', 'stock_transfer.receive',
                'purchase_request.view', 'purchase_request.create', 'purchase_request.submit', 'purchase_request.approve',
                'rfq.view', 'rfq.manage', 'quotation.view', 'quotation.manage', 'quotation.select',
                'purchase_order.view', 'purchase_order.create', 'purchase_order.approve', 'purchase_order.issue', 'purchase_return.decide',
                'goods_receipt.view', 'goods_receipt.post', 'purchase_return.create', 'purchase_return.receive_redelivery', 'vendor_invoice.view', 'vendor_invoice.pay',
                'partner.view', 'partner.manage',
                'part_request.view', 'part_request.create',
                'tire.view', 'tire.manage', 'tire.install', 'tire.rotate', 'tire.inspect', 'tire.remove', 'tire.scrap', 'wheel_configuration.map_vehicle',
                'component_asset.view', 'component_asset.manage', 'component_asset.install', 'component_asset.remove', 'component_asset.replace',
                'warranty.view', 'warranty.manage', 'warranty_claim.create',
            ])->pluck('id')->all());

        $auditorRole = $this->makeRole($tenant, 'Auditor', Permission::query()->where('scope', 'tenant')
            ->where(fn ($q) => $q->where('name', 'like', '%.view')->orWhere('name', 'audit.view'))
            ->pluck('id')->all());

        $this->makeUser($tenant, self::ACCOUNTS['Tenant Admin'], '[TEST] Tenant Admin', $adminRole, 'TENANT', null);
        $this->makeUser($tenant, self::ACCOUNTS['Fleet Manager (Operational)'], '[TEST] Fleet Manager', $fleetManagerRole, 'BRANCH', $branch->id);
        $this->makeUser($tenant, self::ACCOUNTS['Workshop Manager'], '[TEST] Workshop Manager', $workshopManagerRole, 'WORKSHOP', $workshop->id);
        $this->makeUser($tenant, self::ACCOUNTS['Warehouse Manager'], '[TEST] Warehouse Manager', $warehouseManagerRole, 'WAREHOUSE', $warehouse->id);
        $this->makeUser($tenant, self::ACCOUNTS['Auditor'], '[TEST] Auditor', $auditorRole, 'TENANT', null);
    }

    private function makeRole(Tenant $tenant, string $name, array $permissionIds): Role
    {
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $name, 'scope' => 'tenant'],
            ['is_system' => true, 'description' => $name.' role for '.$tenant->name]
        );
        $role->permissions()->sync($permissionIds);

        return $role;
    }

    private function makeUser(Tenant $tenant, string $email, string $name, Role $role, string $scopeType, ?string $scopeResourceId): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'user_type' => 'tenant', 'status' => 'active']
        );
        TenantUser::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['status' => 'active', 'joined_at' => now()]
        );
        RoleAssignment::query()->firstOrCreate(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);
        DataScopeAssignment::query()->firstOrCreate([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'scope_type' => $scopeType, 'scope_resource_id' => $scopeResourceId,
        ]);

        return $user;
    }
}
