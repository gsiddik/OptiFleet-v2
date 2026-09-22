<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Tire\Models\Tire;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\Workshop\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly DataScopeService $scope,
    ) {}

    public function index()
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $activeModules = $this->entitlements->activeModuleCodes($tenantId)->values();

        $data = [
            'branches_total' => Branch::query()->count(),
            'workshops_total' => Workshop::query()->count(),
            'warehouses_total' => Warehouse::query()->count(),
            'users_total' => TenantUser::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'active_modules' => $activeModules,
        ];

        if ($activeModules->contains('VEHICLE')) {
            $vehicleQuery = Vehicle::query();
            $this->scope->applyBranchScope($vehicleQuery, $user, $tenantId, 'branch_id');
            $data['vehicles_total'] = (clone $vehicleQuery)->count();
            $data['vehicles_active'] = (clone $vehicleQuery)->where('status', 'ACTIVE')->count();
            $data['vehicles_in_maintenance'] = (clone $vehicleQuery)->where('status', 'IN_MAINTENANCE')->count();
            $data['vehicles_breakdown'] = (clone $vehicleQuery)->where('status', 'BREAKDOWN')->count();
        }

        if ($activeModules->contains('MAINTENANCE')) {
            $scheduleQuery = MaintenanceSchedule::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $scheduleQuery->whereHas('vehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['maintenance_upcoming'] = (clone $scheduleQuery)->where('status', 'UPCOMING')->count();
            $data['maintenance_due_soon'] = (clone $scheduleQuery)->where('status', 'DUE_SOON')->count();
            $data['maintenance_due'] = (clone $scheduleQuery)->where('status', 'DUE')->count();
            $data['maintenance_overdue'] = (clone $scheduleQuery)->where('status', 'OVERDUE')->count();

            $requestQuery = MaintenanceRequest::query();
            $this->scope->applyBranchScope($requestQuery, $user, $tenantId, 'branch_id');
            $data['maintenance_requests_open'] = (clone $requestQuery)
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED'])
                ->count();
        }

        if ($activeModules->contains('WORK_ORDER')) {
            $woQuery = WorkOrder::query();
            $this->scope->applyWorkshopScope($woQuery, $user, $tenantId, 'workshop_id');
            $data['work_orders_active'] = (clone $woQuery)
                ->whereIn('status', ['SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'EXTERNAL', 'REWORK'])
                ->count();
            $data['work_orders_pending_qc'] = (clone $woQuery)->where('status', 'QC_PENDING')->count();
        }

        if ($activeModules->contains('WORKSHOP')) {
            $workspaceQuery = Workspace::query()->where('tenant_id', $tenantId);
            $allowedWorkshopIds = $this->scope->allowedWorkshopIds($user, $tenantId);
            if ($allowedWorkshopIds !== null) {
                $workspaceQuery->whereIn('workshop_id', $allowedWorkshopIds);
            }
            $data['workspaces_available'] = (clone $workspaceQuery)->where('status', 'AVAILABLE')->count();
            $data['workspaces_occupied'] = (clone $workspaceQuery)->where('status', 'OCCUPIED')->count();
        }

        if ($activeModules->contains('INVENTORY')) {
            $stockQuery = WarehouseStock::query()->where('tenant_id', $tenantId);
            $allowedWarehouseIds = $this->scope->allowedWarehouseIds($user, $tenantId);
            if ($allowedWarehouseIds !== null) {
                $stockQuery->whereIn('warehouse_id', $allowedWarehouseIds);
            }
            $data['inventory_total_value'] = (float) (clone $stockQuery)->selectRaw('COALESCE(SUM(quantity_on_hand * average_unit_cost), 0) AS total')->value('total');
            $data['inventory_reserved_stock'] = (float) (clone $stockQuery)->sum('quantity_reserved');
            $data['inventory_out_of_stock'] = (clone $stockQuery)->whereRaw('(quantity_on_hand - quantity_reserved) <= 0')->count();
            $data['inventory_low_stock'] = (clone $stockQuery)
                ->whereRaw('(quantity_on_hand - quantity_reserved) > 0')
                ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')
                ->count();

            $transferQuery = StockTransfer::query()->where('tenant_id', $tenantId);
            if ($allowedWarehouseIds !== null) {
                $transferQuery->where(fn ($q) => $q->whereIn('from_warehouse_id', $allowedWarehouseIds)->orWhereIn('to_warehouse_id', $allowedWarehouseIds));
            }
            $data['transfers_open'] = (clone $transferQuery)->whereIn('status', ['DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED'])->count();
            $data['transfers_in_transit'] = (clone $transferQuery)->whereIn('status', ['DISPATCHED', 'IN_TRANSIT'])->count();
        }

        if ($activeModules->contains('PROCUREMENT')) {
            $prQuery = PurchaseRequest::query()->where('tenant_id', $tenantId);
            $this->scope->applyWarehouseScope($prQuery, $user, $tenantId, 'warehouse_id');
            $data['purchase_requests_open'] = (clone $prQuery)->whereIn('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED'])->count();

            $poQuery = PurchaseOrder::query()->where('tenant_id', $tenantId);
            $this->scope->applyWarehouseScope($poQuery, $user, $tenantId, 'delivery_warehouse_id');
            $data['purchase_orders_open'] = (clone $poQuery)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED'])->count();
            $data['goods_receipts_pending'] = (clone $poQuery)->whereIn('status', ['ISSUED', 'PARTIALLY_RECEIVED'])->count();
            $data['goods_receipts_total'] = GoodsReceipt::query()->where('tenant_id', $tenantId)->count();
        }

        if ($activeModules->contains('TIRE')) {
            $tireQuery = Tire::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $tireQuery->whereHas('currentVehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['tires_in_use'] = (clone $tireQuery)->whereIn('current_status', ['INSTALLED', 'IN_USE'])->count();
            $data['tires_due_replacement'] = (clone $tireQuery)
                ->whereIn('current_status', ['INSTALLED', 'IN_USE'])
                ->whereHas('inspections', fn ($q) => $q->where('recommendation', 'ilike', '%replace%'))
                ->count();
        }

        if ($activeModules->contains('COMPONENT')) {
            $componentQuery = ComponentAsset::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $componentQuery->whereHas('currentVehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['component_assets_installed'] = (clone $componentQuery)->whereIn('current_status', ['INSTALLED', 'ACTIVE'])->count();
        }

        if ($activeModules->contains('WARRANTY')) {
            $claimQuery = WarrantyClaim::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $claimQuery->whereHas('vehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['warranty_claims_active'] = (clone $claimQuery)->whereNotIn('status', ['CLOSED', 'REJECTED'])->count();
        }

        return $this->ok($data);
    }
}
