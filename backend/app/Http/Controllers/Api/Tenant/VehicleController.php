<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\History\Services\HistoryService;
use App\Domain\Integration\Master\VehicleDrivers;
use App\Domain\Integration\Master\VehicleMaster;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\OdometerRejected;
use App\Domain\Vehicle\Services\OdometerService;
use App\Domain\Vehicle\Services\VehicleAssignmentService;
use App\Domain\Vehicle\Services\VehicleCreationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleRequest;
use App\Http\Requests\Tenant\UpdateVehicleRequest;
use App\Http\Support\OdometerErrors;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
        private readonly VehicleAssignmentService $assignments,
        private readonly HistoryService $historyService,
        private readonly VehicleMaster $master,
        private readonly VehicleDrivers $drivers,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Vehicle::query()->withoutGlobalScope('operational')->with(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']);
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

    public function show(Vehicle $draftVehicle)
    {
        $vehicle = $draftVehicle;
        $this->authorizeScope($vehicle);

        $vehicle->load(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']);
        // What OptiDeli keeps about the vehicle (transport type, capacity, box size), read-only here.
        $vehicle->setAttribute('transport_profile', DB::table('vehicle_transport_profiles')->where('vehicle_id', $vehicle->id)->first());
        // Which tracking device OptiRadar reports for it.
        // The drivers tab exists only for a tenant that has bought OptiDeli (OptiNexus answers, the browser is not trusted).
        $vehicle->setAttribute('driver_history_available', $this->drivers->available($vehicle->tenant_id));
        $vehicle->setAttribute('telematics_profile', DB::table('vehicle_telematics_profiles')->where('vehicle_id', $vehicle->id)->first());

        return $this->ok($vehicle);
    }

    /** Who drove the vehicle, as OptiDeli reports it. 404 when the tenant has not bought OptiDeli. */
    public function drivers(Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);
        abort_unless($this->drivers->available($vehicle->tenant_id), 404);

        return $this->ok($this->drivers->history($vehicle));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle, OdometerService $odometer)
    {
        $this->authorizeScope($vehicle);

        $data = $request->validated();
        $newOdometer = $data['current_odometer'] ?? null;
        $reason = $data['odometer_correction_reason'] ?? null;
        unset($data['current_odometer'], $data['odometer_correction_reason']);

        try {
            DB::transaction(function () use ($vehicle, $data, $newOdometer, $reason, $odometer) {
                $vehicle->update($data);
                $this->master->publish($vehicle->refresh());

                // The odometer is not a plain field: it only goes up by itself, a lower value is a correction with a reason, and
                // every change is recorded with its source (OdometerService). An unchanged value records nothing.
                if ($newOdometer !== null && OdometerService::normalize($newOdometer) !== OdometerService::normalize((string) $vehicle->current_odometer)) {
                    $odometer->recordManual($vehicle, $newOdometer, null, $this->context->user()?->id, null, null, $reason);
                }
            });
        } catch (OdometerRejected $e) {
            return OdometerErrors::fromRejected($e, 'current_odometer');
        }

        return $this->ok($vehicle->fresh(['branch', 'defaultWorkshop', 'vehicleCategory', 'vehicleBrand', 'vehicleModel']));
    }

    /** A vehicle another platform announced is a DRAFT; completing it gives it a branch, category, brand and model and makes it an ordinary vehicle. */
    public function complete(Request $request, Vehicle $draftVehicle)
    {
        $vehicle = $draftVehicle;
        $this->authorizeScope($vehicle);
        abort_unless($vehicle->status === 'DRAFT', 422, 'Only a draft vehicle can be completed.');

        $tenantId = $this->context->tenantId();
        $own = fn ($q) => $q->where(fn ($q2) => $q2->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
        $data = $request->validate([
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'vehicle_category_id' => ['required', 'uuid', Rule::exists('vehicle_categories', 'id')->where($own)],
            'vehicle_brand_id' => ['required', 'uuid', Rule::exists('vehicle_brands', 'id')->where($own)],
            'vehicle_model_id' => ['required', 'uuid', Rule::exists('vehicle_models', 'id')->where(fn ($q) => $own($q)->where('vehicle_brand_id', $request->input('vehicle_brand_id')))],
        ]);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $data['branch_id']), 403, 'This branch is outside your assigned data scope.');
        DB::transaction(function () use ($vehicle, $data, $tenantId) {
            // Two people completing the same draft, or two drafts for the last free slot, are serialised on the draft row.
            $locked = Vehicle::query()->withoutGlobalScope('operational')->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'DRAFT', 422, 'Only a draft vehicle can be completed.');
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $this->capacity->assertCanCreate($tenantId, 'vehicle');
            $vehicle->update($data + [
                'brand' => VehicleBrand::query()->findOrFail($data['vehicle_brand_id'])->name,
                'model' => VehicleModelMaster::query()->findOrFail($data['vehicle_model_id'])->name,
                'status' => 'ACTIVE',
            ]);
            $this->master->publish($vehicle->refresh());
        });

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
        // A draft has no branch yet: only a user whose scope is the whole tenant sees it.
        abort_unless(
            $vehicle->branch_id === null
                ? $this->scope->allowedBranchIds($this->context->user(), $this->context->tenantId()) === null
                : $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id),
            403,
            'This vehicle is outside your assigned data scope.'
        );
    }
}
