<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Integration\Optinexus\TelematicsStatus;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\OdometerRejected;
use App\Domain\Vehicle\Services\OdometerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreOdometerReadingRequest;
use App\Http\Support\OdometerErrors;
use App\Http\Support\OdometerReadingPresenter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The odometer of one vehicle: where the current value came from, its ledger, and the manual entry that works without any
 * telematics. Business rules live in OdometerService; branch data scope applies exactly as for the vehicle.
 */
class VehicleOdometerController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DataScopeService $scope,
        private readonly OdometerService $odometer,
    ) {}

    /** Current odometer with provenance, and the telematics devices linked to the vehicle (each with its own age; none is "zero"). */
    public function show(Vehicle $vehicle): JsonResponse
    {
        $this->authorizeScope($vehicle);
        $now = now();
        $staleHours = (int) config('optinexus.telematics.reading_stale_hours');

        $devices = VehicleTelematicsLink::query()->where('vehicle_id', $vehicle->id)->orderBy('created_at')->get()->map(function (VehicleTelematicsLink $l) use ($now, $staleHours) {
            $age = TelematicsStatus::readingAgeSeconds($l->last_measured_at, $now);

            return [
                'id' => $l->id,
                'device_ref' => $l->device_ref,
                'status' => $l->status,
                'last_measured_at' => $l->last_measured_at?->toIso8601String(),
                'last_received_at' => $l->last_received_at?->toIso8601String(),
                'age_seconds' => $age,
                'stale' => TelematicsStatus::readingIsStale($age, $staleHours),
            ];
        });

        return $this->ok(['current' => $this->odometer->current($vehicle), 'devices' => $devices]);
    }

    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeScope($vehicle);

        $query = VehicleOdometerReading::query()->with(['enteredBy:id,name', 'reviewedBy:id,name'])->where('vehicle_id', $vehicle->id);
        foreach (['source', 'disposition'] as $filter) {
            if ($value = $request->string($filter)->trim()->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated(
            $query->orderByDesc('recorded_at')->orderByDesc('received_at')->paginate(min(100, max(1, $request->integer('per_page', 25)))),
            fn (VehicleOdometerReading $r) => OdometerReadingPresenter::present($r),
        );
    }

    public function store(StoreOdometerReadingRequest $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeScope($vehicle);
        $data = $request->validated();

        try {
            $result = $this->odometer->recordManual(
                $vehicle,
                $data['odometer_km'],
                isset($data['measured_at']) ? Carbon::parse($data['measured_at']) : null,
                $this->context->user()?->id,
                $data['note'] ?? null,
                $data['idempotency_key'] ?? null,
                $data['correction_reason'] ?? null,
            );
        } catch (OdometerRejected $e) {
            return OdometerErrors::fromRejected($e, 'odometer_km');
        }

        return $this->ok([
            'reading' => OdometerReadingPresenter::present($result['reading']->load(['enteredBy:id,name', 'reviewedBy:id,name'])),
            'current' => $this->odometer->current($vehicle->refresh()),
            'duplicate' => $result['duplicate'],
            'outcome' => $result['outcome'],
        ], $result['duplicate'] ? 200 : 201);
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
