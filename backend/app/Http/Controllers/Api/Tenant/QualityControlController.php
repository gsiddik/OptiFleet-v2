<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\QualityControl\Models\QcInspection;
use App\Domain\QualityControl\Services\QualityControlService;
use App\Domain\QualityControl\Services\RoadTestService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class QualityControlController extends Controller
{
    public function __construct(
        private readonly QualityControlService $qc,
        private readonly RoadTestService $roadTests,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = QcInspection::query()->where('tenant_id', $tenantId)->with(['workOrder.vehicle', 'findings']);

        $allowedWorkshopIds = $this->scope->allowedWorkshopIds($this->context->user(), $tenantId);
        if ($allowedWorkshopIds !== null) {
            $query->whereHas('workOrder', fn ($q) => $q->whereIn('workshop_id', $allowedWorkshopIds));
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($workOrderId = $request->string('work_order_id')->value()) {
            $query->where('work_order_id', $workOrderId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function start(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $request->validate(['inspector_worker_id' => ['nullable', 'uuid', 'exists:workers,id']]);

        return $this->ok($this->qc->start($workOrder, $request->input('inspector_worker_id'), $this->context->user()->id), 201);
    }

    public function addFinding(Request $request, WorkOrder $workOrder, QcInspection $inspection)
    {
        $this->authorizeScope($workOrder);
        abort_unless($inspection->work_order_id === $workOrder->id, 404);
        $validated = $request->validate(['description' => ['required', 'string'], 'severity' => ['required', 'in:INFO,LOW,MEDIUM,HIGH,CRITICAL']]);

        return $this->ok($this->qc->addFinding($inspection, $validated), 201);
    }

    public function pass(WorkOrder $workOrder, QcInspection $inspection)
    {
        $this->authorizeScope($workOrder);
        abort_unless($inspection->work_order_id === $workOrder->id, 404);

        return $this->ok($this->qc->pass($inspection));
    }

    public function fail(Request $request, WorkOrder $workOrder, QcInspection $inspection)
    {
        $this->authorizeScope($workOrder);
        abort_unless($inspection->work_order_id === $workOrder->id, 404);

        return $this->ok($this->qc->fail($inspection, $request->input('note')));
    }

    public function complete(WorkOrder $workOrder, QcInspection $inspection)
    {
        $this->authorizeScope($workOrder);
        abort_unless($inspection->work_order_id === $workOrder->id, 404);

        return $this->ok($this->qc->complete($inspection));
    }

    public function recordRoadTest(Request $request, WorkOrder $workOrder)
    {
        $this->authorizeScope($workOrder);
        $validated = $request->validate([
            'tester_worker_id' => ['nullable', 'uuid', 'exists:workers,id'],
            'start_odometer' => ['nullable', 'numeric', 'min:0'],
            'end_odometer' => ['nullable', 'numeric', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'result' => ['required', 'in:PASS,FAIL,NOT_REQUIRED'],
            'notes' => ['nullable', 'string'],
            'evidence' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->ok($this->roadTests->record($workOrder, $validated), 201);
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
