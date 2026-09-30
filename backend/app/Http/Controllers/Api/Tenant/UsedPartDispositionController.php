<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\UsedPartDispositionService;
use App\Domain\WorkOrder\Services\WorkOrderRemovedComponentService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * G-15: Used Sparepart Processing queue — old components removed from a vehicle
 * (REMOVED_COMPONENT: PENDING_RETURN -> receive -> PENDING_INSPECTION) and legacy
 * used-condition returns (USED_PART) go through inspect -> propose -> decide here.
 * New parts returned unused are a separate lifecycle (PartReturnController) and are
 * never listed or processed here.
 */
class UsedPartDispositionController extends Controller
{
    /** Traceability: Work Order + vehicle, removed component (removed by / at), product, warehouse. */
    private const RELATIONS = [
        'product', 'warehouse:id,code,name', 'plannedPart.workOrder', 'workOrder:id,wo_number,vehicle_id,workshop_id',
        'workOrder.vehicle:id,registration_number', 'removedComponent', 'returner:id,name', 'inspector:id,name',
    ];

    public function __construct(
        private readonly UsedPartDispositionService $dispositions,
        private readonly WorkOrderRemovedComponentService $removedComponents,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = WorkOrderPartReturn::query()
            ->whereIn('return_source', WorkOrderPartReturn::USED_SOURCES)
            ->with(self::RELATIONS);
        $allowedWorkshopIds = $this->scope->allowedWorkshopIds($this->context->user(), $this->context->tenantId());
        if ($allowedWorkshopIds !== null) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('workshop_id', $allowedWorkshopIds));
        }
        if ($source = $request->string('return_source')->value()) {
            $query->where('return_source', $source);
        }

        if ($status = $request->string('disposition_status')->value()) {
            $query->where('disposition_status', $status);
        }
        if ($disposition = $request->string('disposition')->value()) {
            $query->where('disposition', $disposition);
        }

        return $this->paginated(
            $query->latest('created_at')->paginate($request->integer('per_page', 20)),
            fn (WorkOrderPartReturn $r) => array_merge($r->toArray(), $this->eligibilityFields($r)),
        );
    }

    public function show(WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);

        return $this->ok(array_merge(
            $usedPartReturn->load(self::RELATIONS)->toArray(),
            $this->eligibilityFields($usedPartReturn),
        ));
    }

    /** G-16: surfaces how much of a SELL_ELIGIBLE return is still unsold, for the Sell Sparepart UI. */
    private function eligibilityFields(WorkOrderPartReturn $return): array
    {
        if ($return->disposition_status !== 'FINALIZED' || $return->disposition !== 'SELL_ELIGIBLE') {
            return [];
        }

        return ['remaining_eligible_quantity' => $return->remainingEligibleQuantity()];
    }

    public function inspect(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate([
            'accepted_quantity' => ['required', 'numeric', 'gt:0'],
            'condition' => ['required', 'string', 'in:USED_GOOD,USED_FAULTY'],
            'notes' => ['nullable', 'string'],
            'evidence' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ok($this->dispositions->inspect(
            $usedPartReturn, (float) $validated['accepted_quantity'], $validated['condition'],
            $validated['notes'] ?? null, $this->context->user()->id, $validated['evidence'] ?? null,
        ));
    }

    public function proposeDisposition(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate([
            'disposition' => ['required', 'string', 'in:'.implode(',', WorkOrderPartReturn::DISPOSITIONS)],
            'reason' => ['nullable', 'string'],
        ]);

        return $this->ok($this->dispositions->proposeDisposition(
            $usedPartReturn, $validated['disposition'], $validated['reason'] ?? null, $this->context->user()->id,
        ));
    }

    public function decide(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->dispositions->decide(
            $usedPartReturn, $validated['decision'], $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    /** The removed component reaches a warehouse: its processing record moves to PENDING_INSPECTION. */
    public function receive(Request $request, WorkOrderPartReturn $usedPartReturn)
    {
        $this->authorizeScope($usedPartReturn);
        $validated = $request->validate(['warehouse_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string']]);
        abort_unless($usedPartReturn->return_source === WorkOrderPartReturn::SOURCE_REMOVED_COMPONENT, 422, 'Only a removed component is received here.');
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $validated['warehouse_id']),
            403,
            'This warehouse is outside your assigned data scope.'
        );

        $this->removedComponents->returnToWarehouse(
            $usedPartReturn->removedComponent, $validated['warehouse_id'], $validated['reason'] ?? null, $this->context->user()->id, fromWorkOrder: false,
        );

        return $this->ok($usedPartReturn->fresh(self::RELATIONS));
    }

    /** Tenant, source (new-part returns are processed elsewhere) and workshop data scope. */
    private function authorizeScope(WorkOrderPartReturn $return): void
    {
        abort_unless($return->tenant_id === $this->context->tenantId(), 404);
        abort_unless(in_array($return->return_source, WorkOrderPartReturn::USED_SOURCES, true), 404);
        $workshopId = $return->workOrder?->workshop_id ?? $return->plannedPart?->workOrder?->workshop_id;
        $allowed = $this->scope->allowedWorkshopIds($this->context->user(), $this->context->tenantId());
        abort_unless($allowed === null || in_array($workshopId, $allowed, true), 403, 'This record is outside your assigned data scope.');
    }
}
