<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\ReturnProcessingService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Return: new parts returned unused from a Work Order's Issuance & Return (NEW_PART only),
 * and Returned Parts Processing for them. Removed components are Used Sparepart Processing.
 */
class PartReturnController extends Controller
{
    private const RELATIONS = [
        'product', 'warehouse:id,code,name', 'workOrder:id,wo_number,vehicle_id,workshop_id', 'workOrder.vehicle:id,registration_number',
        'returner:id,name', 'inspector:id,name',
    ];

    public function __construct(
        private readonly ReturnProcessingService $processing,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = WorkOrderPartReturn::query()->where('return_source', WorkOrderPartReturn::SOURCE_NEW_PART)->with(self::RELATIONS);
        $allowed = $this->scope->allowedWorkshopIds($this->context->user(), $this->context->tenantId());
        if ($allowed !== null) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('workshop_id', $allowed));
        }
        if ($status = $request->string('status')->value()) {
            $query->where('disposition_status', $status);
        }
        if ($workOrderId = $request->string('work_order_id')->value()) {
            $query->where('work_order_id', $workOrderId);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('return_number', 'ilike', "%{$search}%")
                ->orWhereHas('workOrder', fn ($w) => $w->where('wo_number', 'ilike', "%{$search}%"))
                ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', "%{$search}%")));
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(WorkOrderPartReturn $partReturn)
    {
        $this->authorizeScope($partReturn);

        return $this->ok($partReturn->load(self::RELATIONS));
    }

    public function process(Request $request, WorkOrderPartReturn $partReturn)
    {
        $this->authorizeScope($partReturn);
        $validated = $request->validate([
            'actual_condition' => ['required', 'string', 'in:'.implode(',', ReturnProcessingService::ACTUAL_CONDITIONS)],
            'received_quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $processed = $this->processing->process(
            $partReturn, $validated['actual_condition'], (float) $validated['received_quantity'], $validated['notes'] ?? null, $this->context->user()->id,
        );

        return $this->ok($processed->load(self::RELATIONS));
    }

    private function authorizeScope(WorkOrderPartReturn $return): void
    {
        abort_unless($return->tenant_id === $this->context->tenantId(), 404);
        abort_unless($return->return_source === WorkOrderPartReturn::SOURCE_NEW_PART, 404);
        $allowed = $this->scope->allowedWorkshopIds($this->context->user(), $this->context->tenantId());
        abort_unless($allowed === null || in_array($return->workOrder?->workshop_id, $allowed, true), 403, 'This return is outside your assigned data scope.');
    }
}
