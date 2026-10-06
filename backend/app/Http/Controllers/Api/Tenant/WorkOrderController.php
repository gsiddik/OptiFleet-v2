<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Domain\History\Services\DowntimeService;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Http\Controllers\Concerns\PrintsDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWorkOrderRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    use PrintsDocuments;

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly DowntimeService $downtime,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = WorkOrder::query()->with(['vehicle', 'branch', 'workshop']);
        $this->scope->applyWorkshopScope($query, $user, $tenantId, 'workshop_id');

        foreach (['status', 'priority', 'maintenance_type', 'vehicle_id', 'branch_id', 'workshop_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($from = $request->string('date_from')->value()) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('date_to')->value()) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreWorkOrderRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);

        $targetWorkshopId = $request->input('workshop_id') ?? $vehicle->default_workshop_id;
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $tenantId, $targetWorkshopId),
            403,
            'This workshop is outside your assigned data scope.'
        );

        $workOrder = $this->workOrders->create($vehicle, $request->validated(), $this->context->user()->id);

        return $this->ok($workOrder, 201);
    }

    public function fromMaintenanceRequest(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($maintenanceRequest->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $maintenanceRequest->branch_id), 403);

        $workOrder = $this->workOrders->fromMaintenanceRequest($maintenanceRequest, $request->only(['maintenance_type', 'priority', 'complaint', 'workshop_id']), $this->context->user()->id);

        return $this->ok($workOrder, 201);
    }

    /** G-01: Schedule -> Work Order conversion. */
    public function fromMaintenanceSchedule(Request $request, MaintenanceSchedule $maintenanceSchedule)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($maintenanceSchedule->tenant_id === $tenantId, 404);
        $vehicle = Vehicle::query()->findOrFail($maintenanceSchedule->vehicle_id);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $vehicle->branch_id), 403);

        $workOrder = $this->workOrders->fromMaintenanceSchedule(
            $maintenanceSchedule, $request->only(['maintenance_type', 'priority', 'complaint', 'workshop_id']), $this->context->user()->id,
        );

        return $this->ok($workOrder, 201);
    }

    public function show(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        $workOrder->load([
            'vehicle', 'branch', 'workshop', 'findings', 'diagnoses', 'correctiveActions',
            'jobs.laborLogs', 'jobs.primaryAssignment', 'plannedParts.product.uom', 'plannedParts.warehouse', 'additionalWorks', 'mechanicAssignments.worker',
            'roadTests', 'vehicleRelease', 'externalServices.partner', 'workspaceReservations.workspace.workshop:id,name', 'workspaceReservations.approver:id,name', 'workspaceReservations.transferrer:id,name',
            'removedComponents.product', 'removedComponents.maintenanceJob', 'removedComponents.return', 'removedComponents.evidence',
            'plannedPartEstimates.product',
        ]);
        // Computed values only, scoped to this single-record response (never appended
        // globally — a list endpoint appending them to every row would N+1 across the page).
        $workOrder->setAttribute('estimated_labor_cost_computed', $workOrder->computedEstimatedLaborCost());
        $workOrder->setAttribute('estimated_total_hours', $workOrder->estimatedTotalHours());
        $workOrder->setAttribute('estimated_number_of_mechanics', $workOrder->estimatedNumberOfMechanics());
        $workOrder->setAttribute('estimated_parts_cost_computed', $workOrder->computedEstimatedPartsCost());

        return $this->ok($workOrder);
    }

    /**
     * Section 13: renders the tenant's effective (Workshop -> Branch ->
     * Tenant -> Platform fallback) published Work Order template into a
     * PDF, preserving which document number, numbering config version, and
     * template version were in effect at generation time.
     */
    public function print(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->printDocument($request, $this->workOrderDocument($workOrder));
    }

    public function printGenerations(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->documentGenerations($this->workOrderDocument($workOrder));
    }

    public function generatePrint(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->generateDocument($request, $this->workOrderDocument($workOrder));
    }

    private function workOrderDocument(WorkOrder $workOrder): DocumentSource
    {
        return new DocumentSource(
            'work_order', 'work_order', $workOrder->id, $workOrder->tenant_id, $workOrder->wo_number.'.pdf',
            fn (string $locale) => DocumentTemplateContextBuilder::forWorkOrder($workOrder, $locale),
            branchId: $workOrder->branch_id, workshopId: $workOrder->workshop_id,
        );
    }

    public function update(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'priority' => ['sometimes', 'nullable', 'in:LOW,MEDIUM,HIGH,URGENT'],
            'complaint' => ['sometimes', 'nullable', 'string'],
        ]);

        return $this->ok($this->workOrders->update($workOrder, $validated));
    }

    public function submit(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'submit');
    }

    public function approve(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'approve');
    }

    public function reject(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'reject');
    }

    public function assign(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'assign');
    }

    public function schedule(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        $updated = $this->workOrders->schedule(
            $workOrder,
            $request->input('workspace_id'),
            $request->input('target_start_at') ? new \DateTimeImmutable($request->input('target_start_at')) : null,
            $request->input('target_completion_at') ? new \DateTimeImmutable($request->input('target_completion_at')) : null,
        );

        return $this->ok($updated);
    }

    public function start(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'start');
    }

    public function hold(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'hold');
    }

    public function resume(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'resume');
    }

    public function waitForPart(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'waitForPart');
    }

    public function submitToQc(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'submitToQc');
    }

    /** G-02: cost estimation, decimal-safe (BigDecimal, never native float) — see WorkOrderService::estimate(). */
    public function estimate(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'estimated_labor_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_parts_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->ok($this->workOrders->estimate(
            $workOrder, $validated['estimated_labor_cost'] ?? null, $validated['estimated_parts_cost'] ?? null, $this->context->user()->id,
        ));
    }

    public function complete(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate(['result_summary' => ['nullable', 'string']]);

        return $this->ok($this->workOrders->complete($workOrder, $validated['result_summary'] ?? null, $this->context->user()->id));
    }

    public function close(WorkOrder $workOrder)
    {
        return $this->act($workOrder, 'close');
    }

    public function cancel(WorkOrder $workOrder)
    {
        // EXTERNAL has its own cancel action (mandatory reason) — see ExternalWorkOrderController.
        abort_if($workOrder->status === 'EXTERNAL', 422, 'Use the External Work Order cancel action, which requires a reason.');

        return $this->act($workOrder, 'cancel');
    }

    public function downtime(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->ok($this->downtime->forWorkOrder($workOrder));
    }

    private function act(WorkOrder $workOrder, string $method)
    {
        $this->authorizeScope($workOrder);

        return $this->ok($this->workOrders->{$method}($workOrder));
    }

    /**
     * Work Order → Documents. For a Work Order executed by an External Workshop: the
     * acknowledged Work Authorization Letter (only once the workshop has acknowledged it), the
     * External Workshop Invoice (with its own invoice date) and the Payment Proof (once paid).
     * Metadata only — each file is opened through its existing, permission- and scope-checked
     * External Work Order Invoice endpoint (`path`), never a storage URL.
     */
    public function documents(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $invoice = $workOrder->execution_mode === 'EXTERNAL'
            ? $workOrder->externalInvoice()->with(['acknowledgementFile', 'vendorInvoiceFile', 'paymentProofFile'])->first()
            : null;
        $file = fn ($f) => $f ? ['name' => $f->original_filename, 'mime_type' => $f->mime_type, 'size' => $f->size, 'uploaded_at' => $f->uploaded_at] : null;
        $base = $invoice ? "/app/external-work-order-invoices/{$invoice->id}" : null;

        $documents = [];
        if ($invoice && $invoice->work_authorization_status === 'ACKNOWLEDGED' && $invoice->acknowledgementFile) {
            $documents[] = [
                'type' => 'WORK_AUTHORIZATION_LETTER', 'title' => 'Work Authorization Letter', 'status' => 'ACKNOWLEDGED',
                'reference' => $invoice->wal_number, 'date' => $invoice->acknowledgementFile->uploaded_at, 'date_kind' => 'UPLOADED_AT',
                'acknowledged_at' => $invoice->acknowledged_at, 'file' => $file($invoice->acknowledgementFile), 'path' => "{$base}/acknowledgement",
            ];
        }
        if ($invoice && $invoice->vendorInvoiceFile) {
            $documents[] = [
                'type' => 'EXTERNAL_WORKSHOP_INVOICE', 'title' => 'External Workshop Invoice', 'status' => $invoice->status,
                'date' => $invoice->vendor_invoice_date?->toDateString(), 'date_kind' => 'INVOICE_DATE',
                'amount' => $invoice->vendor_invoice_amount, 'file' => $file($invoice->vendorInvoiceFile), 'path' => "{$base}/vendor-invoice",
            ];
        }
        if ($invoice && $invoice->payment_date && $invoice->paymentProofFile) {
            $documents[] = [
                'type' => 'PAYMENT_PROOF', 'title' => 'Payment Proof', 'status' => 'PAID',
                'date' => $invoice->payment_date->toDateString(), 'date_kind' => 'PAYMENT_DATE',
                'amount' => $invoice->paid_amount, 'file' => $file($invoice->paymentProofFile), 'path' => "{$base}/payment-proof",
            ];
        }

        return $this->ok([
            'execution_mode' => $workOrder->execution_mode,
            'external_invoice_id' => $invoice?->id,
            'documents' => $documents,
        ]);
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
