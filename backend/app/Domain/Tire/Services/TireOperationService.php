<?php

namespace App\Domain\Tire\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Models\TireOperationItem;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use App\Domain\Tire\Support\TireOperationStatus;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderException;
use App\Domain\WorkOrder\Services\WorkOrderPartRequestService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\WorkOrder\Services\WorkOrderTransitionService;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tire Operations: plan a Replacement, Rotation or Inspection on a vehicle's mapped wheels
 * configuration; saving creates the operation and its Work Order (next number from the central
 * numbering service) in one transaction, plus — for a Replacement — the REQUESTED Part Request for
 * the replacement tires (one line per tire product, quantity = number of serials). The operation
 * then follows its Work Order (TireOperationStatus); TireOperationExecutionService applies it.
 *
 * Every rule here is the backend's: positions belong to the vehicle's mapped configuration and
 * carry a registered tire, a position is in at most one open operation, a replacement serial is of
 * the same tire product and held by one open operation only, rotation pairs never overlap.
 */
class TireOperationService
{
    private const WO_MAINTENANCE_TYPE = [
        TireOperation::REPLACEMENT => 'CORRECTIVE',
        TireOperation::ROTATION => 'PREVENTIVE',
        TireOperation::INSPECTION => 'INSPECTION',
    ];

    /** Work Order states the default workflow cannot cancel from. */
    private const WO_NOT_CANCELLABLE = ['QC_PENDING', 'REWORK'];

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly WorkOrderTransitionService $transitions,
        private readonly WorkOrderPartRequestService $partRequests,
        private readonly TireFactsService $facts,
        private readonly DataScopeService $scope,
        private readonly VehicleTireRegistrationService $registrations,
    ) {}

    // ---------------------------------------------------------------- reads

    /** The vehicle's mapped configuration, its positions and the tire on each (for the form). */
    public function context(Vehicle $vehicle, ?string $operationId = null): array
    {
        $timezone = $this->registrations->timezone($vehicle->tenant_id);
        $mapping = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicle->id)->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)
            ->with(['master', 'version.positions'])->first();

        $base = [
            'vehicle' => ['id' => $vehicle->id, 'registration_number' => $vehicle->registration_number, 'branch_id' => $vehicle->branch_id, 'default_workshop_id' => $vehicle->default_workshop_id],
            'mapping' => null,
            'positions' => [],
        ];
        if (! $mapping) {
            return $base;
        }

        $installations = $this->activeInstallations($vehicle->id);
        $facts = $this->facts->facts($installations->pluck('tire_id')->all(), $timezone);
        $busy = $this->openOperationPositions($vehicle->id, $operationId);

        $base['mapping'] = [
            'config_code' => $mapping->version->config_code,
            'wheel_configuration_version_id' => $mapping->version->id,
            'version_number' => $mapping->version->version_number,
            'vehicle_type' => $mapping->master->vehicle_type,
            'truck_configuration_type' => $mapping->master->truck_configuration_type,
            'front_axles' => $mapping->version->front_axles,
            'rear_axles' => $mapping->version->rear_axles,
            'spare_tires' => $mapping->version->spare_tires,
        ];
        $base['positions'] = $mapping->version->positions->map(function ($p) use ($installations, $facts, $busy) {
            $installation = $installations->get($p->position_code);

            return [
                'position_code' => $p->position_code,
                'position_group' => $p->position_group,
                'label' => $p->label,
                'open_operation' => $busy[$p->position_code] ?? null,
                'tire' => $installation ? $this->card($installation->tire, $p->position_code, $facts[$installation->tire_id] ?? []) : null,
            ];
        })->values()->all();

        return $base;
    }

    /**
     * Serials that may replace a tire of the given product: same Tire Product, either New Stock
     * (IN_STOCK) or Reuse (removed with disposition REUSE), not deleted, and not already held as
     * "Replacing With" by another open operation.
     */
    public function replacementCandidates(string $tenantId, string $productId, ?string $operationId = null, ?string $search = null): array
    {
        $reuseIds = $this->reuseTireIds($tenantId, $productId);

        return Tire::query()->where('tenant_id', $tenantId)->where('product_id', $productId)
            ->where(fn ($q) => $q->where('current_status', 'IN_STOCK')->orWhereIn('id', $reuseIds))
            ->whereNotIn('id', $this->heldReplacementIds($operationId))
            ->when($search, fn ($q) => $q->where('serial_number', 'ilike', "%{$search}%"))
            ->orderBy('serial_number')->limit(200)
            ->get(['id', 'serial_number', 'current_status', 'product_id', 'manufacture_date_code', 'purchase_date'])
            ->map(fn (Tire $t) => [
                'id' => $t->id,
                'serial_number' => $t->serial_number,
                'current_status' => $t->current_status,
                'source' => $t->current_status === 'IN_STOCK' && ! in_array($t->id, $reuseIds, true) ? 'NEW_STOCK' : 'REUSE',
                'manufacture_date_code' => $t->manufacture_date_code,
            ])->values()->all();
    }

    public function list(string $tenantId, User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $status = TireOperationStatus::sql('o', 'wo');
        $branches = $this->scope->allowedBranchIds($user, $tenantId);
        $page = DB::table('tire_operations as o')
            ->join('vehicles as v', 'v.id', '=', 'o.vehicle_id')
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'o.work_order_id')
            ->leftJoin('work_order_part_requests as pr', fn ($j) => $j->on('pr.tire_operation_id', '=', 'o.id')->whereNotIn('pr.status', ['CANCELLED', 'REJECTED']))
            ->where('o.tenant_id', $tenantId)
            ->when($branches !== null, fn ($q) => $q->whereIn('v.branch_id', $branches ?? []))
            ->when($filters['operation_type'] ?? null, fn ($q, $t) => $q->where('o.operation_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereRaw("({$status}) = ?", [$s]))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $v) => $q->where('o.vehicle_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('v.registration_number', 'ilike', "%{$s}%")->orWhere('wo.wo_number', 'ilike', "%{$s}%")))
            ->orderByDesc('o.operated_at')->orderByDesc('o.created_at')
            ->select(['o.id', 'o.operation_type', 'o.operated_at', 'o.odometer', 'o.config_code', 'o.cancelled_at', 'o.applied_at', 'o.vehicle_id',
                'v.registration_number', 'o.work_order_id', 'wo.wo_number', 'wo.status as work_order_status', 'pr.status as part_request_status'])
            ->selectRaw("{$status} as status")
            ->paginate($perPage);

        $ids = collect($page->items())->pluck('id')->all();
        $items = TireOperationItem::query()->withoutGlobalScopes()->whereIn('tire_operation_id', $ids)
            ->with(['tire' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'serial_number', 'product_id'), 'replacementTire' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'serial_number')])
            ->orderBy('pair_number')->orderBy('position_code')->get()->groupBy('tire_operation_id');
        $timezone = $this->registrations->timezone($tenantId);
        $facts = $this->facts->facts($items->flatten()->pluck('tire_id')->all(), $timezone);

        $page->getCollection()->transform(function ($row) use ($items, $facts, $timezone) {
            $rowItems = $items->get($row->id, collect());
            $anyApplied = $rowItems->contains(fn ($i) => $i->applied_at !== null);
            $at = CarbonImmutable::parse($row->operated_at)->setTimezone($timezone);

            return [
                'id' => $row->id,
                'operation_type' => $row->operation_type,
                'status' => $row->status,
                'operated_at' => $at->toIso8601String(),
                'operated_date' => $at->format('Y-m-d'),
                'operated_time' => $at->format('H:i'),
                'odometer' => $row->odometer,
                'config_code' => $row->config_code,
                'vehicle' => ['id' => $row->vehicle_id, 'registration_number' => $row->registration_number],
                'work_order' => $row->work_order_id ? ['id' => $row->work_order_id, 'wo_number' => $row->wo_number, 'status' => $row->work_order_status] : null,
                'items' => $rowItems->map(fn (TireOperationItem $i) => [
                    'position_code' => $i->position_code,
                    'pair_number' => $i->pair_number,
                    'serial_number' => $i->tire?->serial_number,
                    'replacement_serial_number' => $i->replacementTire?->serial_number,
                    'usage_km' => $facts[$i->tire_id]['usage_km'] ?? null,
                    'usage_hours' => null,
                    'last_tread_depth_mm' => $facts[$i->tire_id]['last_tread_depth_mm'] ?? null,
                ])->values()->all(),
                'can_edit' => TireOperationStatus::isOpen($row->status) && ! $anyApplied,
                'can_cancel' => TireOperationStatus::isOpen($row->status) && ! $anyApplied
                    && ! in_array($row->part_request_status, ['APPROVED', 'ISSUED'], true)
                    && ! in_array($row->work_order_status, self::WO_NOT_CANCELLABLE, true),
            ];
        });

        return $page;
    }

    /** Full payload of one operation (edit form, Work Order → Tire Operations tab). */
    public function present(TireOperation $operation): array
    {
        $operation->loadMissing(['vehicle' => fn ($q) => $q->withoutGlobalScopes(), 'workOrder' => fn ($q) => $q->withoutGlobalScopes()]);
        $timezone = $this->registrations->timezone($operation->tenant_id);
        $items = TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)
            ->with(['tire' => fn ($q) => $q->withoutGlobalScopes()->with('product:id,name,sku'), 'replacementTire' => fn ($q) => $q->withoutGlobalScopes()->with('product:id,name,sku')])
            ->orderBy('pair_number')->orderBy('position_code')->get();
        $facts = $this->facts->facts($items->pluck('tire_id')->all(), $timezone);
        $request = $this->activeRequest($operation);
        $status = TireOperationStatus::derive($operation->cancelled_at, $operation->workOrder?->status);
        $anyApplied = $items->contains(fn ($i) => $i->applied_at !== null);
        $at = CarbonImmutable::parse($operation->operated_at)->setTimezone($timezone);

        return [
            'id' => $operation->id,
            'operation_type' => $operation->operation_type,
            'status' => $status,
            'operated_at' => $at->toIso8601String(),
            'operated_date' => $at->format('Y-m-d'),
            'operated_time' => $at->format('H:i'),
            'odometer' => $operation->odometer,
            'config_code' => $operation->config_code,
            'wheel_configuration_version_id' => $operation->wheel_configuration_version_id,
            // The configuration version the positions were chosen on (read-only preview).
            'configuration' => $this->configuration($operation->wheel_configuration_version_id),
            'vehicle' => ['id' => $operation->vehicle_id, 'registration_number' => $operation->vehicle?->registration_number],
            'work_order' => $operation->workOrder ? ['id' => $operation->workOrder->id, 'wo_number' => $operation->workOrder->wo_number, 'status' => $operation->workOrder->status] : null,
            'part_request' => $request ? ['id' => $request->id, 'status' => $request->status] : null,
            'cancelled_at' => $operation->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $operation->cancellation_reason,
            'applied_at' => $operation->applied_at?->toIso8601String(),
            'items' => $items->map(fn (TireOperationItem $i) => [
                'id' => $i->id,
                'position_code' => $i->position_code,
                'pair_number' => $i->pair_number,
                'tread_depth_mm' => $i->tread_depth_mm,
                'applied_at' => $i->applied_at?->toIso8601String(),
                'tire' => $i->tire ? $this->card($i->tire, $i->position_code, $facts[$i->tire_id] ?? []) : null,
                'replacement_tire' => $i->replacementTire ? [
                    'id' => $i->replacementTire->id,
                    'serial_number' => $i->replacementTire->serial_number,
                    'current_status' => $i->replacementTire->current_status,
                    'product' => $i->replacementTire->product?->only(['id', 'name', 'sku']),
                ] : null,
            ])->values()->all(),
            'can_edit' => TireOperationStatus::isOpen($status) && ! $anyApplied,
            'can_cancel' => TireOperationStatus::isOpen($status) && ! $anyApplied && ! in_array($request?->status, ['APPROVED', 'ISSUED'], true)
                && ! in_array($operation->workOrder?->status, self::WO_NOT_CANCELLABLE, true),
        ];
    }

    // --------------------------------------------------------------- writes

    public function create(Vehicle $vehicle, array $input, string $userId): TireOperation
    {
        return DB::transaction(function () use ($vehicle, $input, $userId) {
            $locked = Vehicle::query()->withoutGlobalScopes()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $plan = $this->plan($locked, $input, null);

            $workshopId = $input['workshop_id'] ?? $locked->default_workshop_id;
            if (! $workshopId) {
                throw ValidationException::withMessages(['workshop_id' => 'This vehicle has no default workshop: choose the workshop that carries out the Work Order.']);
            }

            $workOrder = $this->workOrders->create($locked, [
                'workshop_id' => $workshopId,
                'maintenance_type' => self::WO_MAINTENANCE_TYPE[$plan['type']],
                'priority' => 'MEDIUM',
                'complaint' => $this->summary($plan),
                'current_odometer' => $plan['odometer'],
            ], $userId);

            $operation = TireOperation::query()->create([
                'tenant_id' => $locked->tenant_id,
                'vehicle_id' => $locked->id,
                'work_order_id' => $workOrder->id,
                'wheel_configuration_version_id' => $plan['mapping']->wheel_configuration_version_id,
                'config_code' => $plan['mapping']->version->config_code,
                'operation_type' => $plan['type'],
                'operated_at' => $plan['operated_at'],
                'odometer' => $plan['odometer'],
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->writeItems($operation, $plan['items']);

            if ($plan['lines'] !== []) {
                $this->partRequests->requestForTireOperation($workOrder, $operation->id, $plan['lines'], $userId);
            }

            return $operation->fresh();
        });
    }

    public function update(TireOperation $operation, array $input, string $userId): TireOperation
    {
        return DB::transaction(function () use ($operation, $input, $userId) {
            $locked = TireOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $workOrder = $locked->work_order_id ? WorkOrder::query()->withoutGlobalScopes()->lockForUpdate()->find($locked->work_order_id) : null;
            $this->assertChangeable($locked, $workOrder, 'edited');
            if (isset($input['vehicle_id']) && $input['vehicle_id'] !== $locked->vehicle_id) {
                throw ValidationException::withMessages(['vehicle_id' => 'The vehicle of a Tire Operation cannot be changed.']);
            }

            $vehicle = Vehicle::query()->withoutGlobalScopes()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();
            $plan = $this->plan($vehicle, $input, $locked);

            $this->syncPartRequest($locked, $workOrder, $plan['lines'], $userId);

            TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $locked->id)->delete();
            $this->writeItems($locked, $plan['items']);
            $locked->update([
                'operation_type' => $plan['type'],
                'operated_at' => $plan['operated_at'],
                'odometer' => $plan['odometer'],
                'wheel_configuration_version_id' => $plan['mapping']->wheel_configuration_version_id,
                'config_code' => $plan['mapping']->version->config_code,
                'updated_by' => $userId,
            ]);

            // The Work Order mirrors the operation while it is still a draft.
            if ($workOrder && $workOrder->status === 'DRAFT') {
                $workOrder->update([
                    'maintenance_type' => self::WO_MAINTENANCE_TYPE[$plan['type']],
                    'complaint' => $this->summary($plan),
                    'current_odometer' => $plan['odometer'],
                ]);
                if ((float) $plan['odometer'] > (float) $vehicle->current_odometer) {
                    $vehicle->update(['current_odometer' => $plan['odometer']]);
                }
            }

            return $locked->fresh();
        });
    }

    /** Cancels the operation and its Work Order together (one transaction, both or neither). */
    public function cancel(TireOperation $operation, ?string $reason, string $userId): TireOperation
    {
        return DB::transaction(function () use ($operation, $reason, $userId) {
            $locked = TireOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $workOrder = $locked->work_order_id ? WorkOrder::query()->withoutGlobalScopes()->lockForUpdate()->find($locked->work_order_id) : null;
            $this->assertChangeable($locked, $workOrder, 'cancelled');

            $request = $this->activeRequest($locked);
            if ($request?->status === 'APPROVED') {
                throw new WorkOrderException('The replacement tires are already approved in Part Requests; issue and return them before cancelling this Tire Operation.');
            }
            if ($request?->status === 'ISSUED') {
                $open = WorkOrderPlannedPart::query()->withoutGlobalScopes()->whereIn('id', $request->items()->pluck('planned_part_id')->filter())
                    ->whereNotIn('status', ['RETURNED', 'CANCELLED'])->exists();
                if ($open) {
                    throw new WorkOrderException('The replacement tires were issued to the Work Order; return them before cancelling this Tire Operation.');
                }
            }

            if ($workOrder) {
                if (! $this->transitions->canTransition($workOrder, 'CANCELLED')) {
                    throw new WorkOrderException("The Work Order is {$workOrder->status} and can no longer be cancelled.");
                }
                // Releases the replacement serials and cancels the requested part request (execution hook).
                $this->transitions->transition($workOrder, 'CANCELLED', ['cancellation_reason' => $reason ?: 'Tire Operation cancelled']);
            }

            TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $locked->id)
                ->whereNotNull('replacement_tire_id')->whereNull('replacement_released_at')->update(['replacement_released_at' => now()]);
            $locked->update(['cancelled_at' => now(), 'cancelled_by' => $userId, 'cancellation_reason' => $reason, 'updated_by' => $userId]);

            return $locked->fresh();
        });
    }

    // -------------------------------------------------------------- planning

    /**
     * Validates the input against the vehicle (locked by the caller) and returns the normalised plan.
     *
     * @return array{type: string, mapping: VehicleWheelConfigurationMapping, operated_at: Carbon, odometer: string, items: list<array<string, mixed>>, lines: list<array{product_id: string, quantity_requested: int}>}
     */
    private function plan(Vehicle $vehicle, array $input, ?TireOperation $existing): array
    {
        $type = $input['operation_type'];
        $mapping = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicle->id)->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)->with('version')->first();
        if (! $mapping) {
            throw ValidationException::withMessages(['vehicle_id' => 'This vehicle does not have a Wheels Configuration. Please map a Wheels Configuration first.']);
        }

        $timezone = $this->registrations->timezone($vehicle->tenant_id);
        $operatedAt = Carbon::createFromFormat('Y-m-d H:i', "{$input['operated_date']} {$input['operated_time']}", $timezone)->utc();
        if ($operatedAt->isFuture()) {
            throw ValidationException::withMessages(['operated_date' => 'The Tire Operations date and time cannot be in the future.']);
        }
        $odometer = $input['odometer'];

        // Positions chosen for this type (rotation: pairs).
        $selected = $type === TireOperation::ROTATION ? $this->rotationPositions($input['rotation_pairs'] ?? []) : $this->itemPositions($input['items'] ?? []);
        if ($selected === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one tire position.']);
        }

        $valid = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
            ->where('wheel_configuration_version_id', $mapping->wheel_configuration_version_id)->pluck('position_code')->all();
        $installations = $this->activeInstallations($vehicle->id);
        $busy = $this->openOperationPositions($vehicle->id, $existing?->id);
        foreach (array_keys($selected) as $code) {
            if (! in_array($code, $valid, true)) {
                throw ValidationException::withMessages(['items' => "{$code} is not a position of this vehicle's Wheels Configuration ({$mapping->version->config_code})."]);
            }
            if (! $installations->has($code)) {
                throw ValidationException::withMessages(['items' => "Position {$code} has no tire data yet. Complete it in Vehicle Details → Wheels Configuration first."]);
            }
            if (isset($busy[$code])) {
                throw ValidationException::withMessages(['items' => "Position {$code} is already in an open Tire Operation ({$busy[$code]['operation_type']}, Work Order {$busy[$code]['wo_number']})."]);
            }
        }

        // The reading cannot be lower than one already recorded for these tires (usage would be negative).
        $floor = $this->lastReading($installations->toBase()->only(array_keys($selected))->values());
        if ($floor !== null && (float) $odometer < (float) $floor) {
            throw ValidationException::withMessages(['odometer' => "KM at Tire Operations cannot be lower than the last recorded KM of the selected tires ({$floor})."]);
        }

        $items = [];
        $lines = [];
        foreach ($selected as $code => $detail) {
            $tire = $installations->get($code)->tire;
            $item = ['position_code' => $code, 'tire_id' => $tire->id, 'replacement_tire_id' => null, 'pair_number' => $detail['pair_number'] ?? null, 'tread_depth_mm' => null];
            if ($type === TireOperation::REPLACEMENT) {
                $item['replacement_tire_id'] = $this->assertReplacement($vehicle->tenant_id, $tire, $detail['replacement_tire_id'] ?? null, $existing?->id, $code);
                $lines[$tire->product_id] = ($lines[$tire->product_id] ?? 0) + 1;
            }
            if ($type === TireOperation::INSPECTION) {
                $item['tread_depth_mm'] = $detail['tread_depth_mm'] ?? null;
            }
            $items[] = $item;
        }
        if ($type === TireOperation::REPLACEMENT) {
            $chosen = array_column($items, 'replacement_tire_id');
            if (count($chosen) !== count(array_unique($chosen))) {
                throw ValidationException::withMessages(['items' => 'The same serial number cannot replace two positions.']);
            }
        }

        return [
            'type' => $type,
            'mapping' => $mapping,
            'operated_at' => $operatedAt,
            'odometer' => (string) $odometer,
            'items' => $items,
            'lines' => collect($lines)->map(fn ($qty, $productId) => ['product_id' => $productId, 'quantity_requested' => $qty])->values()->all(),
        ];
    }

    /** @return array<string, array<string, mixed>> position => item input */
    private function itemPositions(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $code = $item['position_code'];
            if (isset($out[$code])) {
                throw ValidationException::withMessages(['items' => "Position {$code} is selected twice."]);
            }
            $out[$code] = $item;
        }

        return $out;
    }

    /** @return array<string, array{pair_number: int}> */
    private function rotationPositions(array $pairs): array
    {
        $out = [];
        $seenPairs = [];
        foreach (array_values($pairs) as $i => $pair) {
            [$a, $b] = [$pair['from'], $pair['to']];
            if ($a === $b) {
                throw ValidationException::withMessages(['rotation_pairs' => "Position {$a} cannot be rotated with itself."]);
            }
            $key = collect([$a, $b])->sort()->implode('|');
            if (isset($seenPairs[$key])) {
                throw ValidationException::withMessages(['rotation_pairs' => "The pair {$a} ↔ {$b} is selected twice."]);
            }
            $seenPairs[$key] = true;
            foreach ([$a, $b] as $code) {
                if (isset($out[$code])) {
                    throw ValidationException::withMessages(['rotation_pairs' => "Position {$code} is already in another rotation pair."]);
                }
                $out[$code] = ['pair_number' => $i + 1];
            }
        }

        return $out;
    }

    private function assertReplacement(string $tenantId, Tire $installed, ?string $replacementId, ?string $operationId, string $code): string
    {
        if (! $replacementId) {
            throw ValidationException::withMessages(['items' => "Choose the serial number replacing the tire on {$code}."]);
        }
        $candidate = Tire::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($replacementId);
        $reuse = $candidate ? in_array($candidate->id, $this->reuseTireIds($tenantId, $candidate->product_id), true) : false;
        if (! $candidate || ($candidate->current_status !== 'IN_STOCK' && ! $reuse)) {
            throw ValidationException::withMessages(['items' => "The replacement for {$code} must be a New Stock or Reuse serial number."]);
        }
        if ($candidate->product_id !== $installed->product_id) {
            throw ValidationException::withMessages(['items' => "The replacement for {$code} must be the same Tire Product as the installed tire."]);
        }
        if (in_array($candidate->id, $this->heldReplacementIds($operationId), true)) {
            throw ValidationException::withMessages(['items' => "Serial {$candidate->serial_number} is already chosen in another open Tire Operation."]);
        }

        return $candidate->id;
    }

    // ------------------------------------------------------------- helpers

    /** @return Collection<string, TireInstallation> position => active installation (with tire) */
    private function activeInstallations(string $vehicleId): Collection
    {
        return TireInstallation::query()->withoutGlobalScopes()->where('vehicle_id', $vehicleId)->whereNull('removed_at')
            ->with(['tire' => fn ($q) => $q->withoutGlobalScopes()->with('product:id,name,sku')])
            ->get()->keyBy('wheel_position');
    }

    /** @return array<string, array{id: string, operation_type: string, wo_number: ?string}> positions of the vehicle's other open operations */
    private function openOperationPositions(string $vehicleId, ?string $exceptOperationId): array
    {
        $status = TireOperationStatus::sql('o', 'wo');

        return DB::table('tire_operation_items as oi')
            ->join('tire_operations as o', 'o.id', '=', 'oi.tire_operation_id')
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'o.work_order_id')
            ->where('o.vehicle_id', $vehicleId)
            ->when($exceptOperationId, fn ($q) => $q->where('o.id', '!=', $exceptOperationId))
            ->whereRaw("({$status}) IN ('NEW', 'IN_PROGRESS')")
            ->get(['oi.position_code', 'o.id', 'o.operation_type', 'wo.wo_number'])
            ->mapWithKeys(fn ($r) => [$r->position_code => ['id' => $r->id, 'operation_type' => $r->operation_type, 'wo_number' => $r->wo_number]])
            ->all();
    }

    /** @return list<string> serials held as "Replacing With" by open operations (other than the given one) */
    private function heldReplacementIds(?string $exceptOperationId): array
    {
        return TireOperationItem::query()->withoutGlobalScopes()->whereNotNull('replacement_tire_id')->whereNull('replacement_released_at')
            ->when($exceptOperationId, fn ($q) => $q->where('tire_operation_id', '!=', $exceptOperationId))
            ->pluck('replacement_tire_id')->all();
    }

    /** @return list<string> REMOVED tires of the product whose latest removal disposition is REUSE */
    private function reuseTireIds(string $tenantId, string $productId): array
    {
        return DB::table('tires as t')
            ->where('t.tenant_id', $tenantId)->where('t.product_id', $productId)->where('t.current_status', 'REMOVED')->whereNull('t.deleted_at')
            ->whereRaw("(SELECT r.disposition FROM tire_removals r WHERE r.tire_id = t.id ORDER BY r.removed_at DESC LIMIT 1) = 'REUSE'")
            ->pluck('t.id')->all();
    }

    private function lastReading(Collection $installations): ?string
    {
        $tireIds = $installations->pluck('tire_id')->all();
        $readings = $installations->pluck('installation_odometer')->filter(fn ($v) => $v !== null);
        $ops = DB::table('tire_operation_items as oi')->join('tire_operations as o', 'o.id', '=', 'oi.tire_operation_id')
            ->whereIn('oi.tire_id', $tireIds)->whereNotNull('oi.applied_at')->whereNull('o.cancelled_at')->max('o.odometer');
        if ($ops !== null) {
            $readings->push($ops);
        }

        return $readings->isEmpty() ? null : (string) $readings->max(fn ($v) => (float) $v);
    }

    private function writeItems(TireOperation $operation, array $items): void
    {
        foreach ($items as $item) {
            TireOperationItem::query()->create($item + ['tenant_id' => $operation->tenant_id, 'tire_operation_id' => $operation->id]);
        }
    }

    private function activeRequest(TireOperation $operation): ?WorkOrderPartRequest
    {
        return WorkOrderPartRequest::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])->latest('created_at')->first();
    }

    /** Keeps the replacement part request equal to the edited operation (products × serial count). */
    private function syncPartRequest(TireOperation $operation, ?WorkOrder $workOrder, array $lines, string $userId): void
    {
        $request = $this->activeRequest($operation);
        $wanted = collect($lines)->mapWithKeys(fn ($l) => [$l['product_id'] => (float) $l['quantity_requested']])->sortKeys()->all();
        $current = $request ? $request->items()->get()->mapWithKeys(fn ($i) => [$i->product_id => (float) $i->quantity_requested])->sortKeys()->all() : [];
        if ($wanted == $current) {
            return;
        }
        if ($request && $request->status !== 'REQUESTED') {
            throw new WorkOrderException("The replacement tires' part request is already {$request->status}: the tire products and number of serials can no longer change.");
        }
        if ($request && $wanted === []) {
            $request->update(['status' => 'CANCELLED', 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => 'Tire Operation edited']);

            return;
        }
        if ($request) {
            $this->partRequests->syncTireOperationLines($request, $lines);

            return;
        }
        if ($workOrder) {
            $this->partRequests->requestForTireOperation($workOrder, $operation->id, $lines, $userId);
        }
    }

    private function assertChangeable(TireOperation $operation, ?WorkOrder $workOrder, string $verb): void
    {
        $status = TireOperationStatus::derive($operation->cancelled_at, $workOrder?->status);
        if (! TireOperationStatus::isOpen($status)) {
            throw new WorkOrderException("A {$status} Tire Operation cannot be {$verb}.");
        }
        if (TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)->whereNotNull('applied_at')->exists()) {
            throw new WorkOrderException("Part of this Tire Operation has already been carried out; it cannot be {$verb}.");
        }
    }

    private function summary(array $plan): string
    {
        $label = ucfirst(strtolower($plan['type']));
        $positions = collect($plan['items'])->pluck('position_code')->implode(', ');

        return "Tire Operation — {$label}: {$positions}";
    }

    private function configuration(string $versionId): ?array
    {
        $version = WheelConfigurationVersion::query()->withoutGlobalScopes()->with(['master' => fn ($q) => $q->withoutGlobalScopes()])->find($versionId);

        return $version ? [
            'config_code' => $version->config_code,
            'version_number' => $version->version_number,
            'vehicle_type' => $version->master?->vehicle_type,
            'truck_configuration_type' => $version->master?->truck_configuration_type,
            'front_axles' => $version->front_axles,
            'rear_axles' => $version->rear_axles,
            'spare_tires' => $version->spare_tires,
        ] : null;
    }

    private function card(Tire $tire, string $position, array $facts): array
    {
        return [
            'id' => $tire->id,
            'serial_number' => $tire->serial_number,
            'current_status' => $tire->current_status,
            'position_code' => $position,
            'product' => $tire->product?->only(['id', 'name', 'sku']),
        ] + $facts;
    }
}
