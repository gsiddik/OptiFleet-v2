<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentPdfService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\History\Services\DowntimeService;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWorkOrderRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
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
            'roadTests', 'vehicleRelease', 'externalServices.partner', 'workspaceReservations.workspace',
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
    public function print(WorkOrder $workOrder, DocumentTemplateRenderService $templates, DocumentPdfService $pdf)
    {
        $this->authorizeScope($workOrder);

        $context = DocumentTemplateContextBuilder::forWorkOrder($workOrder);
        $rendered = $templates->render('work_order', $context, $workOrder->tenant_id, $workOrder->branch_id, $workOrder->workshop_id);

        return response($pdf->fromHtml($rendered['html']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$workOrder->wo_number.'.pdf"',
        ]);
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
