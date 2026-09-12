<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireRetread;
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

        foreach (['current_status', 'current_vehicle_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where('serial_number', 'ilike', "%{$search}%");
        }

        return $this->paginated($query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreTireRequest $request)
    {
        $tire = Tire::query()->create($request->validated() + ['tenant_id' => $this->context->tenantId(), 'current_status' => 'IN_STOCK']);

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
        ]));
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
            'disposition' => ['required', 'in:REUSE,RETREAD,SCRAP'],
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
            'disposition' => ['required', 'in:REUSE,RETREAD,SCRAP'],
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
            'partner_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->tires->retread($tire, $validated['partner_id'] ?? null, $validated['cost'] ?? null, $validated['notes'] ?? null), 201);
    }

    public function receiveRetread(Tire $tire, TireRetread $retread)
    {
        $this->authorizeScope($tire);
        abort_unless($retread->tire_id === $tire->id, 404);

        return $this->ok($this->tires->receiveRetread($retread));
    }

    public function scrap(Request $request, Tire $tire)
    {
        $this->authorizeScope($tire);
        $validated = $request->validate(['reason' => ['nullable', 'string']]);

        return $this->ok($this->tires->scrap($tire, $validated['reason'] ?? null));
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
