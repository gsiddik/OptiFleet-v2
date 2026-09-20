<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice": the External Work Order Invoice list/detail — the tenant-
 * facing "Workshop Invoice" tracking page from the source document.
 * Deliberately its own controller/route namespace, separate from
 * WorkshopInvoiceController (the pre-existing, unrelated R1 feature).
 *
 * Cancel is not duplicated here — it stays on ExternalWorkOrderController
 * (POST /work-orders/{workOrder}/external/cancel), which already
 * synchronizes this Invoice's own status in the same transaction (see
 * ExternalWorkOrderService::cancel()). Generate/View Authorization,
 * Deliver, Acknowledge, Complete, and Settlement land in later phases.
 */
class ExternalWorkOrderInvoiceController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = WorkOrderExternalInvoice::query()->with(['workOrder.vehicle', 'workOrder.workshop']);
        $allowedWorkshopIds = $this->scope->allowedWorkshopIds($user, $tenantId);
        if ($allowedWorkshopIds !== null) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('workshop_id', $allowedWorkshopIds));
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($workOrderId = $request->string('work_order_id')->value()) {
            $query->where('work_order_id', $workOrderId);
        }

        $paginator = $query->latest('created_at')->paginate($request->integer('per_page', 20));

        return $this->paginated($paginator, fn (WorkOrderExternalInvoice $invoice) => [
            ...$invoice->toArray(),
            'allowed_actions' => $invoice->allowedActions(),
        ]);
    }

    public function show(WorkOrderExternalInvoice $externalInvoice)
    {
        $this->authorizeScope($externalInvoice);
        $externalInvoice->load(['workOrder.vehicle', 'workOrder.workshop', 'walWorkshopPartner', 'files']);

        $externalInvoice->setAttribute('allowed_actions', $externalInvoice->allowedActions());

        return $this->ok($externalInvoice);
    }

    private function authorizeScope(WorkOrderExternalInvoice $externalInvoice): void
    {
        abort_unless($externalInvoice->tenant_id === $this->context->tenantId(), 404);
        $workshopId = $externalInvoice->workOrder?->workshop_id
            ?? WorkOrder::query()->find($externalInvoice->work_order_id)?->workshop_id;
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workshopId),
            403,
            'This External Work Order Invoice is outside your assigned data scope.'
        );
    }
}
