<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Vehicle\Models\Vehicle;
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
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'NEED_INFORMATION'])
                ->count();
        }

        if ($activeModules->contains('WORK_ORDER')) {
            $woQuery = WorkOrder::query();
            $this->scope->applyWorkshopScope($woQuery, $user, $tenantId, 'workshop_id');
            $data['work_orders_active'] = (clone $woQuery)
                ->whereIn('status', ['SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'])
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

        return $this->ok($data);
    }
}
