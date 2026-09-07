<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class MaintenanceScheduleController extends Controller
{
    public function __construct(
        private readonly MaintenanceScheduleService $schedules,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = MaintenanceSchedule::query()->with(['vehicle', 'package']);

        $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
        if ($allowedBranchIds !== null) {
            $query->whereHas('vehicle', fn ($q) => $q->whereIn('branch_id', $allowedBranchIds));
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($vehicleId = $request->string('vehicle_id')->value()) {
            $query->where('vehicle_id', $vehicleId);
        }

        return $this->paginated($query->orderBy('next_due_date')->paginate($request->integer('per_page', 20)));
    }

    public function refresh(Request $request, MaintenanceSchedule $maintenanceSchedule)
    {
        abort_unless($maintenanceSchedule->tenant_id === $this->context->tenantId(), 404);

        $vehicle = Vehicle::query()->findOrFail($maintenanceSchedule->vehicle_id);
        $package = MaintenancePackage::query()->with('intervals')->findOrFail($maintenanceSchedule->maintenance_package_id);

        return $this->ok($this->schedules->refresh($vehicle, $package));
    }
}
