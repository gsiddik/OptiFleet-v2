<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\MaintenanceJob;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderAdditionalWork;
use App\Domain\WorkOrder\Models\WorkOrderCorrectiveAction;
use App\Domain\WorkOrder\Models\WorkOrderDiagnosis;
use App\Domain\WorkOrder\Models\WorkOrderFinding;
use App\Domain\WorkOrder\Models\WorkOrderPartReturnEvidence;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPartEstimate;
use App\Domain\WorkOrder\Models\WorkOrderRemovedComponent;
use App\Domain\WorkOrder\Models\WorkOrderRemovedComponentEvidence;
use App\Domain\WorkOrder\Services\WorkOrderExecutionService;
use App\Domain\WorkOrder\Services\WorkOrderPartReturnEvidenceService;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderPlannedPartEstimateService;
use App\Domain\WorkOrder\Services\WorkOrderRemovedComponentService;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkOrderLaborLog;
use App\Domain\Workshop\Models\WorkOrderMechanicAssignment;
use App\Domain\Workshop\Services\LaborTimerService;
use App\Domain\Workshop\Services\MechanicAssignmentService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WorkOrderExecutionController extends Controller
{
    public function __construct(
        private readonly WorkOrderExecutionService $execution,
        private readonly WorkOrderPartService $parts,
        private readonly WorkOrderPartReturnEvidenceService $returnEvidence,
        private readonly WorkOrderRemovedComponentService $removedComponents,
        private readonly WorkOrderPlannedPartEstimateService $plannedPartEstimates,
        private readonly MechanicAssignmentService $mechanics,
        private readonly LaborTimerService $laborTimer,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function addFinding(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'severity' => ['required', 'in:INFO,LOW,MEDIUM,HIGH,CRITICAL'],
            'description' => ['required', 'string'],
        ]);

        return $this->ok($this->execution->addFinding($workOrder, $validated, $this->context->user()->id), 201);
    }

    public function deleteFinding(WorkOrder $workOrder, WorkOrderFinding $finding)
    {
        $this->authorizeScope($workOrder);
        abort_unless($finding->work_order_id === $workOrder->id, 404);
        $this->execution->deleteFinding($finding);

        return $this->message('Finding deleted.');
    }

    /** G-04: resolving a finding is what lets WorkOrderClosureGuardService allow COMPLETED/CLOSED. */
    public function resolveFinding(Request $request, WorkOrder $workOrder, WorkOrderFinding $finding)
    {
        $this->authorizeScope($workOrder);
        abort_unless($finding->work_order_id === $workOrder->id, 404);
        $validated = $request->validate(['resolution_notes' => ['nullable', 'string']]);

        return $this->ok($this->execution->resolveFinding($finding, $validated['resolution_notes'] ?? null, $this->context->user()->id));
    }

    public function addDiagnosis(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'work_order_finding_id' => ['nullable', 'uuid', 'exists:work_order_findings,id'],
            'root_cause' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->execution->addDiagnosis($workOrder, $validated, $this->context->user()->id), 201);
    }

    public function deleteDiagnosis(WorkOrder $workOrder, WorkOrderDiagnosis $diagnosis)
    {
        $this->authorizeScope($workOrder);
        abort_unless($diagnosis->work_order_id === $workOrder->id, 404);
        $this->execution->deleteDiagnosis($diagnosis);

        return $this->message('Diagnosis deleted.');
    }

    public function addCorrectiveAction(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'work_order_diagnosis_id' => ['nullable', 'uuid', 'exists:work_order_diagnoses,id'],
            'action_description' => ['required', 'string'],
        ]);

        return $this->ok($this->execution->addCorrectiveAction($workOrder, $validated), 201);
    }

    public function deleteCorrectiveAction(WorkOrder $workOrder, WorkOrderCorrectiveAction $correctiveAction)
    {
        $this->authorizeScope($workOrder);
        abort_unless($correctiveAction->work_order_id === $workOrder->id, 404);
        $this->execution->deleteCorrectiveAction($correctiveAction);

        return $this->message('Corrective action deleted.');
    }

    public function addJob(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'component_group_id' => ['nullable', 'uuid', 'exists:component_groups,id'],
            'service_item' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->ok($this->execution->addJob($workOrder, $validated), 201);
    }

    public function updateJobStatus(Request $request, WorkOrder $workOrder, MaintenanceJob $job)
    {
        $this->authorizeScope($workOrder);
        abort_unless($job->work_order_id === $workOrder->id, 404);

        $request->validate(['status' => ['required', 'in:ASSIGNED,IN_PROGRESS,ON_HOLD,COMPLETED,CANCELLED']]);

        return $this->ok($this->execution->updateJobStatus($job, $request->input('status')));
    }

    public function addPlannedPart(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'maintenance_job_id' => ['nullable', 'uuid', 'exists:maintenance_jobs,id'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'product_reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->execution->addPlannedPart($workOrder, $validated), 201);
    }

    public function reservePlannedPart(Request $request, WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        return $this->ok($this->parts->reserve($plannedPart, $validated['warehouse_id'] ?? null, isset($validated['quantity']) ? (float) $validated['quantity'] : null, $this->context->user()->id));
    }

    public function issuePlannedPart(Request $request, WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        $validated = $request->validate(['quantity' => ['nullable', 'numeric', 'gt:0']]);

        return $this->ok($this->parts->issue($plannedPart, isset($validated['quantity']) ? (float) $validated['quantity'] : null, $this->context->user()->id));
    }

    public function returnPlannedPart(Request $request, WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'condition' => ['required', 'string', 'in:'.implode(',', WorkOrderPartService::CONDITIONS)],
            'reason' => ['nullable', 'string'],
            'evidence' => ['nullable', 'string', 'max:255'],
            'evidence_ids' => ['nullable', 'array'],
            'evidence_ids.*' => ['uuid'],
        ]);

        return $this->ok($this->parts->returnPart(
            $plannedPart, (float) $validated['quantity'], $validated['condition'], $this->context->user()->id,
            $validated['reason'] ?? null, $validated['evidence'] ?? null, $validated['evidence_ids'] ?? [],
        ));
    }

    /** "Image Placeholder ... JPG/PNG Upload ... Preview" for the Return Parts evidence field. */
    public function uploadReturnEvidence(Request $request, WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);

        $evidence = $this->returnEvidence->upload($plannedPart, $request->file('file'), $this->context->user()->id);

        return $this->ok($evidence, 201);
    }

    public function listReturnEvidence(WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);

        return $this->ok($plannedPart->returnEvidence()->latest('created_at')->get());
    }

    public function showReturnEvidence(WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart, WorkOrderPartReturnEvidence $evidence)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        abort_unless($evidence->work_order_planned_part_id === $plannedPart->id, 404);

        return Storage::disk($evidence->disk)->response($evidence->path, $evidence->original_filename);
    }

    public function destroyReturnEvidence(WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart, WorkOrderPartReturnEvidence $evidence)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        abort_unless($evidence->work_order_planned_part_id === $plannedPart->id, 404);

        $this->returnEvidence->delete($evidence);

        return $this->message('Evidence removed.');
    }

    // --- Removed Components (owner decision: old/removed-component domain, distinct from Planned Part returns) ---

    public function listRemovedComponents(WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);

        return $this->ok($workOrder->removedComponents()->with(['product', 'maintenanceJob', 'return', 'evidence'])->latest('removed_at')->get());
    }

    public function removeComponent(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'maintenance_job_id' => ['nullable', 'uuid', Rule::exists('maintenance_jobs', 'id')->where('work_order_id', $workOrder->id)],
            'replaced_by_planned_part_id' => ['nullable', 'uuid', Rule::exists('work_order_planned_parts', 'id')->where('work_order_id', $workOrder->id)],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $this->context->tenantId())->orWhereNull('tenant_id'))],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'condition' => ['required', 'string', 'in:'.implode(',', WorkOrderRemovedComponent::CONDITIONS)],
            'notes' => ['nullable', 'string'],
        ]);

        $removed = $this->removedComponents->remove($workOrder, $validated, $this->context->user()->id);

        return $this->ok($removed->load('product'), 201);
    }

    public function destroyRemovedComponent(WorkOrder $workOrder, WorkOrderRemovedComponent $removedComponent)
    {
        $this->authorizeScope($workOrder);
        abort_unless($removedComponent->work_order_id === $workOrder->id, 404);

        $this->removedComponents->delete($removedComponent);

        return $this->message('Removed-component record deleted.');
    }

    public function returnRemovedComponent(Request $request, WorkOrder $workOrder, WorkOrderRemovedComponent $removedComponent)
    {
        $this->authorizeScope($workOrder);
        abort_unless($removedComponent->work_order_id === $workOrder->id, 404);
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $this->context->tenantId())],
            'reason' => ['nullable', 'string'],
        ]);

        return $this->ok($this->removedComponents->returnToWarehouse(
            $removedComponent, $validated['warehouse_id'], $validated['reason'] ?? null, $this->context->user()->id,
        ));
    }

    public function uploadRemovedComponentEvidence(Request $request, WorkOrder $workOrder, WorkOrderRemovedComponent $removedComponent)
    {
        $this->authorizeScope($workOrder);
        abort_unless($removedComponent->work_order_id === $workOrder->id, 404);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);

        $evidence = $this->removedComponents->uploadEvidence($removedComponent, $request->file('file'), $this->context->user()->id);

        return $this->ok($evidence, 201);
    }

    public function listRemovedComponentEvidence(WorkOrder $workOrder, WorkOrderRemovedComponent $removedComponent)
    {
        $this->authorizeScope($workOrder);
        abort_unless($removedComponent->work_order_id === $workOrder->id, 404);

        return $this->ok($removedComponent->evidence()->latest('created_at')->get());
    }

    public function showRemovedComponentEvidence(WorkOrder $workOrder, WorkOrderRemovedComponent $removedComponent, WorkOrderRemovedComponentEvidence $evidence)
    {
        $this->authorizeScope($workOrder);
        abort_unless($removedComponent->work_order_id === $workOrder->id, 404);
        abort_unless($evidence->work_order_removed_component_id === $removedComponent->id, 404);

        return Storage::disk($evidence->disk)->response($evidence->path, $evidence->original_filename);
    }

    public function destroyRemovedComponentEvidence(WorkOrder $workOrder, WorkOrderRemovedComponent $removedComponent, WorkOrderRemovedComponentEvidence $evidence)
    {
        $this->authorizeScope($workOrder);
        abort_unless($removedComponent->work_order_id === $workOrder->id, 404);
        abort_unless($evidence->work_order_removed_component_id === $removedComponent->id, 404);

        $this->removedComponents->deleteEvidence($evidence);

        return $this->message('Evidence removed.');
    }

    // --- Planned Part Estimates ("Planned Parts" tab — pure budgeting, never touches stock) ---

    public function addPlannedPartEstimate(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $this->context->tenantId())->orWhereNull('tenant_id'))],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $estimate = $this->plannedPartEstimates->add($workOrder, $validated, $this->context->user()->id);

        return $this->ok($estimate->load('product'), 201);
    }

    public function destroyPlannedPartEstimate(WorkOrder $workOrder, WorkOrderPlannedPartEstimate $plannedPartEstimate)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPartEstimate->work_order_id === $workOrder->id, 404);

        $this->plannedPartEstimates->delete($plannedPartEstimate);

        return $this->message('Planned part estimate removed.');
    }

    public function consumePlannedPart(Request $request, WorkOrder $workOrder, WorkOrderPlannedPart $plannedPart)
    {
        $this->authorizeScope($workOrder);
        abort_unless($plannedPart->work_order_id === $workOrder->id, 404);
        $validated = $request->validate(['quantity' => ['nullable', 'numeric', 'gt:0']]);

        return $this->ok($this->parts->consume($plannedPart, isset($validated['quantity']) ? (float) $validated['quantity'] : null, $this->context->user()->id));
    }

    public function requestAdditionalWork(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $request->validate(['description' => ['required', 'string']]);

        return $this->ok($this->execution->requestAdditionalWork($workOrder, $request->input('description'), $this->context->user()->id), 201);
    }

    public function decideAdditionalWork(Request $request, WorkOrder $workOrder, WorkOrderAdditionalWork $additionalWork)
    {
        $this->authorizeScope($workOrder);
        abort_unless($additionalWork->work_order_id === $workOrder->id, 404);
        $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string']]);

        return $this->ok($this->execution->decideAdditionalWork($additionalWork, $request->boolean('approve'), $this->context->user()->id, $request->input('note')));
    }

    public function assignMechanic(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $request->validate([
            'worker_id' => ['required', 'uuid', 'exists:workers,id'],
            'role' => ['nullable', 'in:PRIMARY,ASSISTANT'],
            'maintenance_job_id' => ['nullable', 'uuid', 'exists:maintenance_jobs,id'],
        ]);

        $worker = Worker::query()->findOrFail($request->input('worker_id'));
        $assignment = $this->mechanics->assign(
            $workOrder,
            $worker,
            $request->input('role', 'PRIMARY'),
            $request->input('maintenance_job_id'),
            $this->context->user()->id,
        );

        return $this->ok($assignment, 201);
    }

    public function unassignMechanic(WorkOrder $workOrder, WorkOrderMechanicAssignment $assignment)
    {
        $this->authorizeScope($workOrder);
        abort_unless($assignment->work_order_id === $workOrder->id, 404);

        return $this->ok($this->mechanics->unassign($assignment));
    }

    public function startLabor(Request $request, WorkOrder $workOrder, MaintenanceJob $job)
    {
        $this->authorizeScope($workOrder);
        abort_unless($job->work_order_id === $workOrder->id, 404);
        $request->validate(['worker_id' => ['required', 'uuid', 'exists:workers,id']]);

        return $this->ok($this->laborTimer->start($job, $request->input('worker_id')), 201);
    }

    public function pauseLabor(WorkOrder $workOrder, MaintenanceJob $job, WorkOrderLaborLog $laborLog)
    {
        $this->authorizeScope($workOrder);
        abort_unless($laborLog->maintenance_job_id === $job->id && $job->work_order_id === $workOrder->id, 404);

        return $this->ok($this->laborTimer->pause($laborLog));
    }

    public function resumeLabor(WorkOrder $workOrder, MaintenanceJob $job, WorkOrderLaborLog $laborLog)
    {
        $this->authorizeScope($workOrder);
        abort_unless($laborLog->maintenance_job_id === $job->id && $job->work_order_id === $workOrder->id, 404);

        return $this->ok($this->laborTimer->resume($laborLog));
    }

    public function finishLabor(WorkOrder $workOrder, MaintenanceJob $job, WorkOrderLaborLog $laborLog)
    {
        $this->authorizeScope($workOrder);
        abort_unless($laborLog->maintenance_job_id === $job->id && $job->work_order_id === $workOrder->id, 404);

        return $this->ok($this->laborTimer->finish($laborLog));
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
