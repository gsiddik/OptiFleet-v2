<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Audit\Services\AuditService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\Shared\Support\Messages;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceScheduleController extends Controller
{
    public function __construct(
        private readonly MaintenanceScheduleService $schedules,
        private readonly MaintenanceRequestService $requests,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
        private readonly AuditService $audit,
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

    /**
     * Section 12: manual "Add New Schedule". Vehicle scope follows the
     * existing DataScopeService (branch-scoped users only see their
     * vehicles; a tenant-scoped user, e.g. Admin, sees all) rather than a
     * hardcoded role check. The package dropdown must already be limited
     * to PERIODIC + ACTIVE on the frontend, but the backend re-validates
     * both here rather than trusting that filter.
     */
    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();

        $request->validate([
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')->where('tenant_id', $tenantId)],
            'maintenance_package_id' => ['required', 'uuid', Rule::exists('maintenance_packages', 'id')->where(
                fn ($q) => $q->where('tenant_id', $tenantId)->where('maintenance_type', 'PERIODIC')->where('status', 'ACTIVE')
            )],
            'schedule_start_date' => ['required', 'date'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($this->scope->canAccessBranch($user, $tenantId, $vehicle->branch_id), 403, 'This vehicle is outside your assigned data scope.');

        $package = MaintenancePackage::query()->findOrFail($request->input('maintenance_package_id'));

        $schedule = $this->schedules->createManualPeriodicSchedule(
            $vehicle,
            $package,
            CarbonImmutable::parse($request->input('schedule_start_date'))->startOfDay(),
        );

        return $this->ok($schedule->load(['vehicle', 'package']), 201);
    }

    /**
     * Section 12: idempotent against double-click/retry — if this schedule
     * already has a linked (non-rejected/non-cancelled) Maintenance
     * Request, that same request is returned rather than creating a
     * second one. Mirrors the existing schedule -> Work Order conversion's
     * duplicate-prevention pattern (check for an existing open link,
     * rather than mutating the schedule's own status).
     */
    public function convertToMaintenanceRequest(Request $request, MaintenanceSchedule $maintenanceSchedule)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($maintenanceSchedule->tenant_id === $tenantId, 404);

        $vehicle = Vehicle::query()->findOrFail($maintenanceSchedule->vehicle_id);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $vehicle->branch_id), 403, 'This schedule is outside your assigned data scope.');

        abort_unless(in_array($maintenanceSchedule->status, ['DUE', 'OVERDUE', 'SCHEDULED'], true), 422, 'Only a Due, Overdue, or Scheduled schedule can be converted to a Maintenance Request.');

        $existing = MaintenanceRequest::query()
            ->where('source_schedule_id', $maintenanceSchedule->id)
            ->whereNotIn('status', ['REJECTED', 'CANCELLED'])
            ->first();
        if ($existing) {
            return $this->ok($existing);
        }

        $package = MaintenancePackage::query()->find($maintenanceSchedule->maintenance_package_id);

        $maintenanceRequest = $this->requests->create($vehicle, [
            'source_type' => 'SCHEDULE',
            'source_schedule_id' => $maintenanceSchedule->id,
            'priority' => $maintenanceSchedule->status === 'OVERDUE' ? 'URGENT' : 'MEDIUM',
            'complaint' => $request->input('complaint', Messages::text('maintenance.defaults.scheduledMaintenanceDue', ['packageName' => $package?->name ?? $maintenanceSchedule->source_policy])),
            'status' => 'SUBMITTED',
        ], $this->context->user()->id);

        $this->audit->log('MaintenanceSchedule', $maintenanceSchedule->id, 'maintenance_request_created', null, [
            'maintenance_request_id' => $maintenanceRequest->id,
        ]);

        return $this->ok($maintenanceRequest, 201);
    }
}
