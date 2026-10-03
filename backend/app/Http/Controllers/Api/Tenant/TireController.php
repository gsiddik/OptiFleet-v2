<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireRepair;
use App\Domain\Tire\Models\TireRetread;
use App\Domain\Tire\Models\TireScoringResult;
use App\Domain\Tire\Services\TireRegistrationService;
use App\Domain\Tire\Services\TireOperationService;
use App\Domain\Tire\Services\TireScoringService;
use App\Domain\Tire\Services\TireService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTireRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class TireController extends Controller
{
    public function __construct(
        private readonly TireService $tires,
        private readonly TireScoringService $scoring,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $query = Tire::query()->where('tenant_id', $tenantId)->with(['product', 'currentVehicle', 'currentWarehouse']);

        $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
        $allowedWarehouseIds = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($allowedBranchIds !== null || $allowedWarehouseIds !== null) {
            $query->where(function ($q) use ($allowedBranchIds, $allowedWarehouseIds) {
                $q->whereHas('currentVehicle', fn ($vq) => $vq->whereIn('branch_id', $allowedBranchIds ?? []))
                    ->orWhereIn('current_warehouse_id', $allowedWarehouseIds ?? []);
            });
        }

        // current_status: one status, or several comma-separated (e.g. IN_STOCK,RESERVED).
        if ($statuses = array_filter(array_map('trim', explode(',', $request->string('current_status')->value())))) {
            $query->whereIn('current_status', $statuses);
        }
        if ($vehicleId = $request->string('current_vehicle_id')->value()) {
            $query->where('current_vehicle_id', $vehicleId);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where('serial_number', 'ilike', "%{$search}%");
        }

        return $this->paginated($query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreTireRequest $request)
    {
        $tire = app(TireRegistrationService::class)->register($this->context->tenantId(), $request->validated());

        return $this->ok($tire, 201);
    }

    public function show(Tire $tire)
    {
        $this->authorizeScope($tire);

        return $this->ok($tire->load([
            'product', 'currentVehicle', 'currentWarehouse',
            'installations' => fn ($q) => $q->orderByDesc('installed_at'),
            'installations.vehicle',
            'rotations' => fn ($q) => $q->orderByDesc('occurred_at'),
            'inspections' => fn ($q) => $q->orderByDesc('inspected_at'),
            'removals' => fn ($q) => $q->orderByDesc('removed_at'),
            'retreads' => fn ($q) => $q->orderByDesc('sent_at'),
            'repairs' => fn ($q) => $q->orderByDesc('sent_at'),
            'scoringResults' => fn ($q) => $q->orderByDesc('computed_at'),
            'sales' => fn ($q) => $q->orderByDesc('sold_at'),
        ])->toArray() + [
            // Serial Detail of an installed tire (position, usage, tread vs reference, preview).
            'installed' => app(TireOperationService::class)->installedSummary($tire),
        ]);
    }

    public function install(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid', 'exists:vehicles,id'],
            'wheel_position' => ['required', 'string', 'max:20'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
            // G-23: legacy onboarding — a normal live install omits all of these.
            'installed_at' => ['nullable', 'date'],
            'installed_at_source' => ['nullable', 'string', 'in:KNOWN,ESTIMATED,UNKNOWN'],
            'baseline_tread_depth_mm' => ['nullable', 'numeric', 'min:0'],
            'baseline_condition' => ['nullable', 'string', 'max:100'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($validated['vehicle_id']);
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);

        $installation = $this->tires->install(
            $tire, $vehicle, $validated['wheel_position'], $validated['odometer'] ?? null,
            $validated['work_order_id'] ?? null, $this->context->user()->id,
            isset($validated['installed_at']) ? new \DateTimeImmutable($validated['installed_at']) : null,
            $validated['installed_at_source'] ?? 'KNOWN',
            $validated['baseline_tread_depth_mm'] ?? null,
            $validated['baseline_condition'] ?? null,
        );

        return $this->ok($installation, 201);
    }

    public function rotate(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'to_position' => ['required', 'string', 'max:20'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        return $this->ok($this->tires->rotate($tire, $validated['to_position'], $validated['odometer'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id), 201);
    }

    /** G-24: atomic two-tire position swap — {tire} is tire A, the request names tire B. */
    public function swapPositions(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'other_tire_id' => ['required', 'uuid', 'exists:tires,id'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        $otherTire = Tire::query()->findOrFail($validated['other_tire_id']);
        abort_unless($otherTire->tenant_id === $this->context->tenantId(), 404);

        return $this->ok($this->tires->swapPositions(
            $tire, $otherTire, $validated['odometer'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id,
        ), 201);
    }

    public function inspect(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'tread_depth_mm' => ['nullable', 'numeric', 'min:0'],
            'pressure_psi' => ['nullable', 'numeric', 'min:0'],
            'condition' => ['nullable', 'string', 'max:100'],
            'damage' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        return $this->ok($this->tires->inspect($tire, $validated, $this->context->user()->id), 201);
    }

    public function remove(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'removal_reason' => ['required', 'string', 'max:255'],
            'disposition' => ['required', 'in:REUSE,RETREAD,REPAIR,SCRAP'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'condition' => ['nullable', 'string', 'max:100'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        $removal = $this->tires->remove($tire, $validated['removal_reason'], $validated['disposition'], $validated['odometer'] ?? null, $validated['condition'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id);

        return $this->ok($removal, 201);
    }

    public function replace(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'new_tire_id' => ['required', 'uuid', 'exists:tires,id'],
            'reason' => ['required', 'string', 'max:255'],
            // G-28: the old tire's disposition is a decision made at replacement time, not an implicit REUSE.
            'disposition' => ['required', 'in:REUSE,RETREAD,REPAIR,SCRAP'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        $newTire = Tire::query()->findOrFail($validated['new_tire_id']);
        abort_unless($newTire->tenant_id === $this->context->tenantId(), 404);

        return $this->ok($this->tires->replace($tire, $newTire, $validated['reason'], $validated['disposition'], $validated['odometer'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id), 201);
    }

    public function sendForRetread(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            // G-30: a retread cycle always names a receiving partner — eligibility is checked in the service.
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->tires->retread($tire, $validated['partner_id'], $validated['cost'] ?? null, $validated['notes'] ?? null, $this->context->user()->id), 201);
    }

    public function receiveRetread(Tire $tire, TireRetread $retread)
    {
        $this->authorizeScope($tire);
        abort_unless($retread->tire_id === $tire->id, 404);

        return $this->ok($this->tires->receiveRetread($retread, $this->context->user()->id));
    }

    public function finalInspectRetread(Request $request, Tire $tire, TireRetread $retread)
    {
        $this->authorizeScope($tire);
        abort_unless($retread->tire_id === $tire->id, 404);
        $validated = $request->validate([
            'result' => ['required', 'in:SAFE,UNSAFE'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->tires->finalInspectRetread($retread, $validated['result'], $validated['notes'] ?? null, $this->context->user()->id));
    }

    public function approveRetread(Request $request, Tire $tire, TireRetread $retread)
    {
        $this->authorizeScope($tire);
        abort_unless($retread->tire_id === $tire->id, 404);
        $validated = $request->validate([
            'disposition' => ['required', 'in:RETURN_TO_SERVICE,SCRAP,QUARANTINE'],
            'reason' => ['required', 'string'],
        ]);

        return $this->ok($this->tires->approveRetread($retread, $validated['disposition'], $validated['reason'], $this->context->user()->id));
    }

    public function sendForRepair(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->tires->repair($tire, $validated['partner_id'], $validated['cost'] ?? null, $validated['notes'] ?? null, $this->context->user()->id), 201);
    }

    public function receiveRepair(Tire $tire, TireRepair $repair)
    {
        $this->authorizeScope($tire);
        abort_unless($repair->tire_id === $tire->id, 404);

        return $this->ok($this->tires->receiveRepair($repair, $this->context->user()->id));
    }

    public function finalInspectRepair(Request $request, Tire $tire, TireRepair $repair)
    {
        $this->authorizeScope($tire);
        abort_unless($repair->tire_id === $tire->id, 404);
        $validated = $request->validate([
            'result' => ['required', 'in:SAFE,UNSAFE'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->tires->finalInspectRepair($repair, $validated['result'], $validated['notes'] ?? null, $this->context->user()->id));
    }

    public function approveRepair(Request $request, Tire $tire, TireRepair $repair)
    {
        $this->authorizeScope($tire);
        abort_unless($repair->tire_id === $tire->id, 404);
        $validated = $request->validate([
            'disposition' => ['required', 'in:RETURN_TO_SERVICE,SCRAP,QUARANTINE'],
            'reason' => ['required', 'string'],
        ]);

        return $this->ok($this->tires->approveRepair($repair, $validated['disposition'], $validated['reason'], $this->context->user()->id));
    }

    public function scrap(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate(['reason' => ['nullable', 'string']]);

        return $this->ok($this->tires->scrap($tire, $validated['reason'] ?? null));
    }

    /** Phase F (G-31): structured scoring — calculates from an existing inspection, never fabricates a measurement of its own. */
    public function calculateScoring(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'tire_inspection_id' => ['required', 'uuid', 'exists:tire_inspections,id'],
            'scoring_type' => ['required', 'in:REPAIR,RETREAD'],
            'ka_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'critical_safety_fail' => ['required', 'boolean'],
            'critical_safety_reasons' => ['nullable', 'string'],
            'tire_retread_id' => ['nullable', 'uuid', 'exists:tire_retreads,id'],
            'tire_repair_id' => ['nullable', 'uuid', 'exists:tire_repairs,id'],
        ]);

        $inspection = TireInspection::query()->findOrFail($validated['tire_inspection_id']);
        abort_unless($inspection->tire_id === $tire->id, 404);
        $retread = isset($validated['tire_retread_id']) ? TireRetread::query()->findOrFail($validated['tire_retread_id']) : null;
        $repair = isset($validated['tire_repair_id']) ? TireRepair::query()->findOrFail($validated['tire_repair_id']) : null;

        $result = $this->scoring->calculate(
            $tire, $inspection, $validated['scoring_type'], $validated['ka_score'] ?? null,
            $validated['critical_safety_fail'], $validated['critical_safety_reasons'] ?? null,
            $this->context->user()->id, $retread, $repair,
        );

        return $this->ok($result, 201);
    }

    public function finalizeScoring(Tire $tire, TireScoringResult $scoringResult)
    {
        $this->authorizeScope($tire);
        abort_unless($scoringResult->tire_id === $tire->id, 404);

        return $this->ok($this->scoring->finalize($scoringResult, $this->context->user()->id));
    }

    /** BD-5: the three-way sell split, with SELL_FOR_OPERATIONAL_REUSE gated on a safe scoring result. */
    public function sell(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate([
            'sell_type' => ['required', 'in:SELL_FOR_OPERATIONAL_REUSE,SELL_AS_RETREADABLE_CASING,SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL'],
            'reason' => ['required', 'string'],
        ]);

        return $this->ok($this->tires->sell($tire, $validated['sell_type'], $validated['reason'], $this->context->user()->id), 201);
    }

    private function authorizeScope(Tire $tire): void
    {
        abort_unless($tire->tenant_id === $this->context->tenantId(), 404);

        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
        $allowedWarehouseIds = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($allowedBranchIds === null && $allowedWarehouseIds === null) {
            return;
        }

        $vehicleBranchId = $tire->current_vehicle_id ? Vehicle::query()->find($tire->current_vehicle_id)?->branch_id : null;
        $inBranch = $vehicleBranchId && in_array($vehicleBranchId, $allowedBranchIds, true);
        $inWarehouse = $tire->current_warehouse_id && in_array($tire->current_warehouse_id, $allowedWarehouseIds, true);

        abort_unless($inBranch || $inWarehouse, 403, 'This tire is outside your assigned data scope.');
    }
}
