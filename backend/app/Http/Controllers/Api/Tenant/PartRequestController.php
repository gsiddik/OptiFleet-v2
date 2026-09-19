<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Services\WorkOrderPartRequestService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PartRequestController extends Controller
{
    public function __construct(
        private readonly WorkOrderPartRequestService $partRequests,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = WorkOrderPartRequest::query()->with(['workOrder.vehicle', 'items.product', 'items.plannedPart']);
        $allowedWorkshopIds = $this->scope->allowedWorkshopIds($user, $tenantId);
        if ($allowedWorkshopIds !== null) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('workshop_id', $allowedWorkshopIds));
        }

        if ($workOrderId = $request->string('work_order_id')->value()) {
            $query->where('work_order_id', $workOrderId);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(WorkOrderPartRequest $partRequest)
    {
        $this->authorizeScope($partRequest);
        $partRequest->load(['workOrder.vehicle', 'items.product', 'items.plannedPart']);

        return $this->ok($partRequest);
    }

    public function store(Request $request, WorkOrder $workOrder)
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Work Order is outside your assigned data scope.'
        );

        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'items.*.product_reference' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity_requested' => ['required', 'numeric', 'min:0.01'],
        ]);

        $created = $this->partRequests->request($workOrder, $validated['items'], $validated['notes'] ?? null, $this->context->user()->id);

        return $this->ok($created, 201);
    }

    public function indexForWorkOrder(WorkOrder $workOrder)
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Work Order is outside your assigned data scope.'
        );

        return $this->ok($workOrder->partRequests()->with(['items.product', 'items.plannedPart'])->latest('created_at')->get());
    }

    public function approve(Request $request, WorkOrderPartRequest $partRequest)
    {
        $this->authorizeScope($partRequest);
        $validated = $request->validate([
            'approved_quantities' => ['nullable', 'array'],
            'approved_quantities.*' => ['numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->partRequests->approve(
            $partRequest, $validated['approved_quantities'] ?? null, $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    public function reject(Request $request, WorkOrderPartRequest $partRequest)
    {
        $this->authorizeScope($partRequest);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return $this->ok($this->partRequests->reject($partRequest, $validated['reason'], $this->context->user()->id));
    }

    public function cancel(WorkOrderPartRequest $partRequest)
    {
        $this->authorizeScope($partRequest);

        return $this->ok($this->partRequests->cancel($partRequest, $this->context->user()->id));
    }

    private function authorizeScope(WorkOrderPartRequest $partRequest): void
    {
        abort_unless($partRequest->tenant_id === $this->context->tenantId(), 404);
        $workshopId = $partRequest->workOrder?->workshop_id ?? WorkOrder::query()->find($partRequest->work_order_id)?->workshop_id;
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workshopId),
            403,
            'This part request is outside your assigned data scope.'
        );
    }
}
