<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\Inspection\Models\InspectionTemplate;
use App\Domain\Inspection\Services\InspectionService;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreInspectionRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class InspectionController extends Controller
{
    public function __construct(
        private readonly InspectionService $inspections,
        private readonly MaintenanceRequestService $requests,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Inspection::query()->with(['vehicle', 'template', 'branch', 'workshop']);
        $this->scope->applyBranchScope($query, $user, $tenantId, 'branch_id');

        foreach (['inspection_type', 'status', 'vehicle_id', 'branch_id', 'workshop_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreInspectionRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $vehicle = Vehicle::query()->findOrFail($request->input('vehicle_id'));
        abort_unless($vehicle->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessBranch($this->context->user(), $tenantId, $vehicle->branch_id), 403);

        $template = InspectionTemplate::query()->with('items')->findOrFail($request->input('inspection_template_id'));

        // Section 9 (historical snapshot): freeze the checklist composition
        // used by this inspection so later template edits (items added or
        // removed) can never alter an inspection already created from it.
        $snapshot = $template->items->map(fn ($item) => [
            'id' => $item->id,
            'item_text' => $item->item_text,
            'input_type' => $item->input_type,
            'options' => $item->options,
            'required' => $item->required,
            'sequence' => $item->sequence,
            'is_system' => $item->is_system,
        ])->values()->all();

        $inspection = Inspection::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $vehicle->branch_id,
            'workshop_id' => $request->input('workshop_id', $vehicle->default_workshop_id),
            'vehicle_id' => $vehicle->id,
            'inspection_template_id' => $template->id,
            'template_snapshot' => $snapshot,
            'inspection_type' => $request->input('inspection_type') ?? $template->inspection_type,
            'status' => 'CREATED',
            'odometer_at_inspection' => $request->input('odometer_at_inspection', $vehicle->current_odometer),
            'created_by' => $this->context->user()->id,
            'notes' => $request->input('notes'),
        ]);

        return $this->ok($inspection->load(['vehicle', 'template']), 201);
    }

    public function show(Inspection $inspection)
    {
        $this->authorizeScope($inspection);

        return $this->ok($inspection->load(['vehicle', 'template.items', 'results', 'findings']));
    }

    public function assign(Request $request, Inspection $inspection)
    {
        $this->authorizeScope($inspection);
        $request->validate(['worker_id' => ['required', 'uuid']]);

        return $this->ok($this->inspections->assign($inspection, $request->input('worker_id')));
    }

    public function start(Inspection $inspection)
    {
        $this->authorizeScope($inspection);

        return $this->ok($this->inspections->start($inspection));
    }

    public function submit(Request $request, Inspection $inspection)
    {
        $this->authorizeScope($inspection);

        $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.inspection_template_item_id' => ['required', 'uuid'],
            'results.*.passed' => ['nullable', 'boolean'],
            'findings' => ['nullable', 'array'],
            'findings.*.severity' => ['required_with:findings', 'in:INFO,LOW,MEDIUM,HIGH,CRITICAL'],
            'findings.*.description' => ['required_with:findings', 'string'],
        ]);

        $findings = collect($request->input('findings', []))->map(fn ($f) => $f + ['created_by' => $this->context->user()->id])->all();

        return $this->ok($this->inspections->submit($inspection, $request->input('results'), $findings));
    }

    public function review(Request $request, Inspection $inspection)
    {
        $this->authorizeScope($inspection);
        $validated = $request->validate(['review_notes' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->inspections->review($inspection, $this->context->user()->id, $validated['review_notes'] ?? null));
    }

    public function createMaintenanceRequest(Request $request, Inspection $inspection)
    {
        $this->authorizeScope($inspection);

        if (! in_array($inspection->status, ['FAILED', 'WARNING'], true)) {
            return $this->message('Only a failed or warning inspection can raise a maintenance request.', 422);
        }

        $vehicle = Vehicle::query()->findOrFail($inspection->vehicle_id);
        $topFinding = $inspection->findings()->orderByRaw(
            "array_position(ARRAY['CRITICAL','HIGH','MEDIUM','LOW','INFO'], severity)"
        )->first();

        $maintenanceRequest = $this->requests->create($vehicle, [
            'workshop_id' => $inspection->workshop_id,
            'component_group_id' => $topFinding?->component_group_id,
            'source_type' => 'INSPECTION',
            'source_inspection_id' => $inspection->id,
            'priority' => in_array($topFinding?->severity, ['CRITICAL', 'HIGH'], true) ? 'URGENT' : 'MEDIUM',
            'complaint' => $request->input('complaint', $topFinding?->description ?? 'Issues found during inspection '.$inspection->id),
            'status' => 'SUBMITTED',
        ], $this->context->user()->id);

        $this->audit->log('Inspection', $inspection->id, 'maintenance_request_created', null, [
            'maintenance_request_id' => $maintenanceRequest->id,
        ]);

        return $this->ok($maintenanceRequest, 201);
    }

    public function logs(Inspection $inspection)
    {
        $this->authorizeScope($inspection);

        $logs = AuditLog::query()
            ->where('tenant_id', $this->context->tenantId())
            ->where('resource_type', 'Inspection')
            ->where('resource_id', $inspection->id)
            ->with('actor')
            ->oldest('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor_user_id' => $log->actor_user_id,
                'actor_name' => $log->actor?->name,
                'action' => $log->action,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at,
            ]);

        return $this->ok($logs);
    }

    private function authorizeScope(Inspection $inspection): void
    {
        abort_unless($inspection->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $inspection->branch_id),
            403,
            'This inspection is outside your assigned data scope.'
        );
    }
}
