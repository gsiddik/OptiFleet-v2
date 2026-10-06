<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\History\Services\HistoryService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\VehicleAssignmentService;
use App\Domain\Vehicle\Services\VehicleCreationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleRequest;
use App\Http\Requests\Tenant\UpdateVehicleRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
        private readonly VehicleAssignmentService $assignments,
        private readonly HistoryService $historyService,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Vehicle::query()->with(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']);
        $this->scope->applyBranchScope($query, $user, $tenantId, 'branch_id');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('registration_number', 'ilike', "%{$search}%")
                    ->orWhere('brand', 'ilike', "%{$search}%")
                    ->orWhere('model', 'ilike', "%{$search}%")
                    ->orWhere('vin', 'ilike', "%{$search}%");
            });
        }
        foreach (['status', 'operational_status', 'branch_id', 'default_workshop_id', 'vehicle_category_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        $sort = $request->string('sort', 'created_at')->value();
        $direction = $request->string('direction', 'desc')->value() === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['registration_number', 'brand', 'status', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreVehicleRequest $request, VehicleCreationService $creation)
    {
        $tenantId = $this->context->tenantId();
        $this->capacity->assertCanCreate($tenantId, 'vehicle');
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $request->input('branch_id')), 403, 'This branch is outside your assigned data scope.');

        // Section 7: the New Vehicle form only offers Brand/Model dropdowns, so the brand/model text
        // columns (kept for backward compatibility with pre-existing free-text vehicles) are derived
        // server-side from the selected master data (VehicleCreationService — also the import path).
        $vehicle = $creation->create($tenantId, $request->validated(), $this->context->user());

        return $this->ok($vehicle->load(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']), 201);
    }

    public function show(Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        return $this->ok($vehicle->load(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        $vehicle->update($request->validated());

        return $this->ok($vehicle->fresh(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']));
    }

    public function updateStatus(Request $request, Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        $request->validate([
            'status' => ['required', 'in:ACTIVE,IN_MAINTENANCE,BREAKDOWN,OUT_OF_SERVICE,INACTIVE,DISPOSED'],
        ]);

        $vehicle->update(['status' => $request->input('status')]);

        return $this->ok($vehicle->fresh());
    }

    public function assign(Request $request, Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        $tenantId = $this->context->tenantId();
        $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'default_workshop_id' => ['nullable', 'uuid', 'exists:workshops,id'],
            'notes' => ['nullable', 'string'],
        ]);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $request->input('branch_id')), 403, 'Target branch is outside your assigned data scope.');

        $assignment = $this->assignments->assign($vehicle, $request->only(['branch_id', 'default_workshop_id', 'notes']), $this->context->user()->id);

        return $this->ok($assignment, 201);
    }

    public function assignmentHistory(Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        return $this->ok($vehicle->assignments()->with(['fromBranch', 'toBranch'])->get());
    }

    public function history(Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        return $this->ok($this->historyService->forVehicle($this->context->tenantId(), $vehicle->id));
    }

    private function authorizeScope(Vehicle $vehicle): void
    {
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id),
            403,
            'This vehicle is outside your assigned data scope.'
        );
    }
}
