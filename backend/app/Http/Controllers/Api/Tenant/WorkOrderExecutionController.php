<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\WorkOrder\Models\MaintenanceJob;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderAdditionalWork;
use App\Domain\WorkOrder\Services\WorkOrderExecutionService;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkOrderLaborLog;
use App\Domain\Workshop\Models\WorkOrderMechanicAssignment;
use App\Domain\Workshop\Services\LaborTimerService;
use App\Domain\Workshop\Services\MechanicAssignmentService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkOrderExecutionController extends Controller
{
    public function __construct(
        private readonly WorkOrderExecutionService $execution,
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

    public function addCorrectiveAction(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'work_order_diagnosis_id' => ['nullable', 'uuid', 'exists:work_order_diagnoses,id'],
            'action_description' => ['required', 'string'],
        ]);

        return $this->ok($this->execution->addCorrectiveAction($workOrder, $validated), 201);
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
            'product_reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->execution->addPlannedPart($workOrder, $validated), 201);
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
