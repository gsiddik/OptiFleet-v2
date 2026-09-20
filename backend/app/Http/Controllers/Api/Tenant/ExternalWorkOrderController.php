<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Domain\WorkOrder\Models\WorkOrderFinding;
use App\Domain\WorkOrder\Services\ExternalWorkOrderService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Consolidated External Workshop business rules — deliberately a separate
 * controller from WorkOrderExecutionController (internal-workshop
 * execution) and from WorkOrderController's generic transitions, so an
 * External Work Order's Findings-only capability set can never be
 * accidentally combined with internal-execution or generic-transition
 * routes.
 */
class ExternalWorkOrderController extends Controller
{
    public function __construct(
        private readonly ExternalWorkOrderService $external,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function markExternalMode(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->ok($this->external->markExternalMode($workOrder));
    }

    public function addFinding(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'severity' => ['required', 'in:INFO,LOW,MEDIUM,HIGH,CRITICAL'],
            'description' => ['required', 'string'],
        ]);

        return $this->ok($this->external->addFinding($workOrder, $validated, $this->context->user()->id), 201);
    }

    public function updateFinding(Request $request, WorkOrder $workOrder, WorkOrderFinding $finding)
    {
        $this->authorizeScope($workOrder);
        abort_unless($finding->work_order_id === $workOrder->id, 404);
        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'severity' => ['sometimes', 'in:INFO,LOW,MEDIUM,HIGH,CRITICAL'],
            'description' => ['sometimes', 'string'],
        ]);

        return $this->ok($this->external->updateFinding($finding, $validated));
    }

    public function deleteFinding(WorkOrder $workOrder, WorkOrderFinding $finding)
    {
        $this->authorizeScope($workOrder);
        abort_unless($finding->work_order_id === $workOrder->id, 404);
        $this->external->deleteFinding($finding);

        return $this->ok(['deleted' => true]);
    }

    public function finalize(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->ok($this->external->finalize($workOrder, $this->context->user()->id));
    }

    public function revise(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->ok($this->external->revise($workOrder));
    }

    public function cancel(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return $this->ok($this->external->cancel($workOrder, $validated['reason'], $this->context->user()->id));
    }

    /**
     * A bare, read-only reference list — kept for any existing caller of this endpoint. The full
     * External Work Order Invoice list/detail (real persisted status, Work Authorization,
     * Deliver/Acknowledge/Complete/Settlement actions) lives in its own controller — see
     * /api/v1/app/external-work-order-invoices.
     */
    public function referenceIndex(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = WorkOrderExternalInvoice::query()
            ->with(['workOrder.vehicle', 'workOrder.branch'])
            ->whereHas('workOrder', fn ($q) => $q->where('status', 'EXTERNAL'));
        $this->scope->applyBranchScope($query, $this->context->user(), $tenantId, 'branch_id');

        $references = $query->get()->map(fn (WorkOrderExternalInvoice $invoice) => [
            'work_order_id' => $invoice->work_order_id,
            'wo_number' => $invoice->workOrder->wo_number,
            'revision' => $invoice->workOrder->external_finalized_revision,
            'wo_date' => $invoice->workOrder->created_at,
            'vehicle' => $invoice->workOrder->vehicle?->registration_number,
            'tenant_id' => $invoice->tenant_id,
            'branch_id' => $invoice->branch_id,
            'source_status' => $invoice->workOrder->status,
            'display_status' => $invoice->status,
        ]);

        return $this->ok($references);
    }

    private function authorizeScope(WorkOrder $workOrder): void
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Work Order is outside your assigned data scope.'
        );
    }
}
