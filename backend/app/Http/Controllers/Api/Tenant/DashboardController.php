<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Tire\Models\Tire;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\Workshop\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Legacy summary endpoint (kept for backward compatibility; the tenant dashboard UI now uses the
 * per-widget endpoints of DashboardWidgetController). Every section is gated on the server by its
 * module AND its view permission, counts follow the data scope, and the inventory value is returned
 * only with dashboard.finance.view. Retired: the orphaned Warranty count, the retired reservation
 * total and the free-text "tires due replacement" count.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly DataScopeService $scope,
        private readonly PermissionService $permissions,
    ) {}

    public function index()
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $activeModules = $this->entitlements->activeModuleCodes($tenantId)->values();

        $can = fn (string $permission) => $this->permissions->userHasPermission($user, $permission, $tenantId);

        // Organization counts follow the user's data scope (a branch-scoped user counts its own branch,
        // workshops and warehouses — not the whole tenant). The user count is tenant-wide, so it is only
        // returned to users who may view users.
        $data = [
            'branches_total' => $this->scope->applyBranchScope(Branch::query(), $user, $tenantId)->count(),
            'workshops_total' => $this->scope->applyWorkshopScope(Workshop::query(), $user, $tenantId)->count(),
            'warehouses_total' => $this->scope->applyWarehouseScope(Warehouse::query(), $user, $tenantId)->count(),
            'active_modules' => $activeModules,
        ];
        if ($can('user.view')) {
            $data['users_total'] = TenantUser::query()->where('tenant_id', $tenantId)->where('status', 'active')->count();
        }

        if ($activeModules->contains('VEHICLE') && $can('vehicle.view')) {
            $vehicleQuery = Vehicle::query();
            $this->scope->applyBranchScope($vehicleQuery, $user, $tenantId, 'branch_id');
            $data['vehicles_total'] = (clone $vehicleQuery)->count();
            $data['vehicles_active'] = (clone $vehicleQuery)->where('status', 'ACTIVE')->count();
            $data['vehicles_in_maintenance'] = (clone $vehicleQuery)->where('status', 'IN_MAINTENANCE')->count();
            $data['vehicles_breakdown'] = (clone $vehicleQuery)->where('status', 'BREAKDOWN')->count();
        }

        if ($activeModules->contains('MAINTENANCE') && $can('maintenance_schedule.view')) {
            $scheduleQuery = MaintenanceSchedule::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $scheduleQuery->whereHas('vehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['maintenance_upcoming'] = (clone $scheduleQuery)->where('status', 'UPCOMING')->count();
            $data['maintenance_due_soon'] = (clone $scheduleQuery)->where('status', 'DUE_SOON')->count();
            $data['maintenance_due'] = (clone $scheduleQuery)->where('status', 'DUE')->count();
            $data['maintenance_overdue'] = (clone $scheduleQuery)->where('status', 'OVERDUE')->count();
        }

        if ($activeModules->contains('MAINTENANCE') && $can('maintenance_request.view')) {
            $requestQuery = MaintenanceRequest::query();
            $this->scope->applyBranchScope($requestQuery, $user, $tenantId, 'branch_id');
            $data['maintenance_requests_open'] = (clone $requestQuery)
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED'])
                ->count();
        }

        if ($activeModules->contains('WORK_ORDER') && $can('work_order.view')) {
            $woQuery = WorkOrder::query();
            $this->scope->applyWorkshopScope($woQuery, $user, $tenantId, 'workshop_id');
            $data['work_orders_active'] = (clone $woQuery)
                ->whereIn('status', ['SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'EXTERNAL', 'REWORK'])
                ->count();
            $data['work_orders_pending_qc'] = (clone $woQuery)->where('status', 'QC_PENDING')->count();
        }

        if ($activeModules->contains('WORKSHOP') && $can('workspace.view')) {
            $workspaceQuery = Workspace::query()->where('tenant_id', $tenantId);
            $allowedWorkshopIds = $this->scope->allowedWorkshopIds($user, $tenantId);
            if ($allowedWorkshopIds !== null) {
                $workspaceQuery->whereIn('workshop_id', $allowedWorkshopIds);
            }
            $data['workspaces_available'] = (clone $workspaceQuery)->where('status', 'AVAILABLE')->count();
            $data['workspaces_occupied'] = (clone $workspaceQuery)->where('status', 'OCCUPIED')->count();
        }

        $allowedWarehouseIds = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($activeModules->contains('INVENTORY')) {
            $stockQuery = WarehouseStock::query()->where('tenant_id', $tenantId);
            if ($allowedWarehouseIds !== null) {
                $stockQuery->whereIn('warehouse_id', $allowedWarehouseIds);
            }
            // Monetary value: only with the dashboard finance permission, never sent and hidden.
            if ($can(DashboardPermissions::FINANCE)) {
                $data['inventory_total_value'] = (string) BigDecimal::of((string) ((clone $stockQuery)->selectRaw('COALESCE(SUM(quantity_on_hand * average_unit_cost), 0) AS total')->value('total') ?? '0'))->toScale(2, RoundingMode::HALF_UP);
                // `inventory_total_value` is the RECORDED value of the ledger; it is not a verified figure. The split says how much of it
                // has a verified valuation source — anything else (unverified, mixed, no unit cost) is flagged, never counted as verified.
                $split = (clone $stockQuery)->selectRaw("COALESCE(SUM(CASE WHEN valuation_status = 'VERIFIED' THEN quantity_on_hand * average_unit_cost ELSE 0 END), 0) AS verified,
                    COALESCE(SUM(CASE WHEN COALESCE(valuation_status, 'UNVERIFIED') IN ('UNVERIFIED', 'MIXED') THEN quantity_on_hand * average_unit_cost ELSE 0 END), 0) AS unverified,
                    COUNT(*) FILTER (WHERE quantity_on_hand > 0 AND COALESCE(valuation_status, 'UNVERIFIED') NOT IN ('VERIFIED', 'VERIFIED_ZERO')) AS unverified_balances")->first();
                $data['inventory_value_basis'] = [
                    'verified_value' => (string) BigDecimal::of((string) $split->verified)->toScale(2, RoundingMode::HALF_UP),
                    'unverified_recorded_value' => (string) BigDecimal::of((string) $split->unverified)->toScale(2, RoundingMode::HALF_UP),
                    'unverified_balances' => (int) $split->unverified_balances,
                    'completeness' => (int) $split->unverified_balances === 0 ? 'COMPLETE' : 'PARTIAL',
                ];
            }
        }

        if ($activeModules->contains('INVENTORY') && $can('inventory.view')) {
            $stockQuery = WarehouseStock::query()->where('tenant_id', $tenantId);
            if ($allowedWarehouseIds !== null) {
                $stockQuery->whereIn('warehouse_id', $allowedWarehouseIds);
            }
            $data['inventory_out_of_stock'] = (clone $stockQuery)->whereRaw('(quantity_on_hand - quantity_reserved) <= 0')->count();
            $data['inventory_low_stock'] = (clone $stockQuery)
                ->whereRaw('(quantity_on_hand - quantity_reserved) > 0')
                ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')
                ->count();
        }

        if ($activeModules->contains('INVENTORY') && $can('stock_transfer.view')) {
            $transferQuery = StockTransfer::query()->where('tenant_id', $tenantId);
            if ($allowedWarehouseIds !== null) {
                $transferQuery->where(fn ($q) => $q->whereIn('from_warehouse_id', $allowedWarehouseIds)->orWhereIn('to_warehouse_id', $allowedWarehouseIds));
            }
            $data['transfers_open'] = (clone $transferQuery)->whereIn('status', ['DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED'])->count();
            $data['transfers_in_transit'] = (clone $transferQuery)->whereIn('status', ['DISPATCHED', 'IN_TRANSIT'])->count();
        }

        if ($activeModules->contains('PROCUREMENT') && $can('purchase_request.view')) {
            $prQuery = PurchaseRequest::query()->where('tenant_id', $tenantId);
            $this->scope->applyWarehouseScope($prQuery, $user, $tenantId, 'warehouse_id');
            $data['purchase_requests_open'] = (clone $prQuery)->whereIn('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED'])->count();
        }

        if ($activeModules->contains('PROCUREMENT') && $can('purchase_order.view')) {
            $poQuery = PurchaseOrder::query()->where('tenant_id', $tenantId);
            $this->scope->applyWarehouseScope($poQuery, $user, $tenantId, 'delivery_warehouse_id');
            $data['purchase_orders_open'] = (clone $poQuery)->whereIn('status', ['DRAFT', 'SUBMITTED', 'PENDING_APPROVAL', 'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED'])->count();
            $data['goods_receipts_pending'] = (clone $poQuery)->whereIn('status', ['ISSUED', 'PARTIALLY_RECEIVED'])->count();
        }

        if ($activeModules->contains('TIRE') && $can('tire.view')) {
            $tireQuery = Tire::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $tireQuery->whereHas('currentVehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['tires_in_use'] = (clone $tireQuery)->whereIn('current_status', ['INSTALLED', 'IN_USE'])->count();
            // "Tires due replacement" now comes from structured data only (dashboard widget TR-02).
        }

        if ($activeModules->contains('COMPONENT') && $can('component_asset.view')) {
            $componentQuery = ComponentAsset::query()->where('tenant_id', $tenantId);
            $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
            if ($allowedBranchIds !== null) {
                $componentQuery->whereHas('currentVehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
            }
            $data['component_assets_installed'] = (clone $componentQuery)->whereIn('current_status', ['INSTALLED', 'ACTIVE'])->count();
        }

        return $this->ok($data);
    }
}
