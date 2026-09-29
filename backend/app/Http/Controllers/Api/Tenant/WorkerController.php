<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkerSkill;
use App\Domain\Workshop\Models\WorkerType;
use App\Domain\Workshop\Services\WorkerAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWorkerRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    public function __construct(
        private readonly WorkerAssignmentService $assignments,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Worker::query()->with(['branch', 'workshop', 'skills.componentGroup', 'workerTypeMaster']);
        $this->scope->applyWorkshopScope($query, $user, $tenantId, 'workshop_id');

        foreach (['status', 'worker_type', 'worker_type_id', 'branch_id', 'workshop_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('employee_code', 'ilike', "%{$search}%"));
        }

        return $this->paginated($query->paginate($request->integer('per_page', 20)));
    }

    public function workload(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Worker::query()->with(['branch', 'workshop'])->where('status', 'ACTIVE');
        $this->scope->applyWorkshopScope($query, $user, $tenantId, 'workshop_id');

        if ($workshopId = $request->string('workshop_id')->value()) {
            $query->where('workshop_id', $workshopId);
        }

        $workers = $query->withCount([
            'mechanicAssignments as active_job_count' => function ($q) {
                $q->whereNull('unassigned_at')
                    ->whereHas('workOrder', fn ($wq) => $wq->whereIn('status', ['ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK']));
            },
        ])->orderByDesc('active_job_count')->get();

        return $this->ok($workers);
    }

    public function store(StoreWorkerRequest $request)
    {
        $validated = $this->deriveLegacyWorkerType($request->validated());
        $worker = Worker::query()->create($validated + ['status' => 'ACTIVE']);

        // fresh(), not load(): worker_type may have been left for the DB's own column
        // default (see deriveLegacyWorkerType()) rather than sent explicitly, and the
        // in-memory model from create() never reflects a DB-applied default without
        // actually re-reading the row.
        return $this->ok($worker->fresh(['workerTypeMaster']), 201);
    }

    public function show(Worker $worker)
    {
        $this->authorizeTenant($worker);

        return $this->ok($worker->load(['branch', 'workshop', 'skills.componentGroup', 'assignments', 'workerTypeMaster']));
    }

    public function update(Request $request, Worker $worker)
    {
        $this->authorizeTenant($worker);
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'worker_type' => ['sometimes', 'in:LEAD_MECHANIC,MECHANIC,TECHNICIAN,INSPECTOR,QC'],
            'worker_type_id' => ['sometimes', 'nullable', 'uuid', \Illuminate\Validation\Rule::exists('worker_types', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'photo_url' => ['nullable', 'string', 'max:255'],
        ]);
        $worker->update($this->deriveLegacyWorkerType($validated));

        return $this->ok($worker->fresh(['workerTypeMaster']));
    }

    /**
     * When a caller sets worker_type_id to a type whose code still matches one of the
     * 5 legacy enum values, mirror it into the legacy worker_type column too, so any
     * pre-existing code/report still keyed on that string column keeps working. A
     * custom tenant-defined type (not one of the 5) simply leaves worker_type alone —
     * worker_type_id + workerTypeMaster is the authoritative path for those.
     */
    private function deriveLegacyWorkerType(array $validated): array
    {
        if (empty($validated['worker_type_id']) || ! empty($validated['worker_type'])) {
            return $validated;
        }
        $type = WorkerType::query()->find($validated['worker_type_id']);
        if ($type && in_array($type->code, ['LEAD_MECHANIC', 'MECHANIC', 'TECHNICIAN', 'INSPECTOR', 'QC'], true)) {
            $validated['worker_type'] = $type->code;
        }

        return $validated;
    }

    public function addSkill(Request $request, Worker $worker)
    {
        $this->authorizeTenant($worker);
        $request->validate([
            'component_group_id' => ['required', 'uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule()],
            'skill_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string'],
        ]);

        $skill = WorkerSkill::query()->updateOrCreate(
            ['worker_id' => $worker->id, 'component_group_id' => $request->input('component_group_id')],
            ['skill_level' => $request->input('skill_level'), 'notes' => $request->input('notes')]
        );

        return $this->ok($skill, 201);
    }

    /** G-14: workers.user_id existed since Phase 4 but had no endpoint to ever set it. */
    public function linkUser(Request $request, Worker $worker)
    {
        $this->authorizeTenant($worker);
        $validated = $request->validate(['user_id' => ['required', 'uuid']]);

        $isTenantMember = TenantUser::query()
            ->where('tenant_id', $worker->tenant_id)
            ->where('user_id', $validated['user_id'])
            ->where('status', 'active')
            ->exists();
        abort_unless($isTenantMember, 422, 'This user is not an active member of this tenant.');

        $worker->update(['user_id' => $validated['user_id']]);

        return $this->ok($worker->fresh('user'));
    }

    public function unlinkUser(Worker $worker)
    {
        $this->authorizeTenant($worker);
        $worker->update(['user_id' => null]);

        return $this->ok($worker->fresh());
    }

    public function assign(Request $request, Worker $worker)
    {
        $this->authorizeTenant($worker);
        $request->validate([
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'workshop_id' => ['nullable', 'uuid', 'exists:workshops,id'],
        ]);

        $assignment = $this->assignments->assign($worker, $request->only(['branch_id', 'workshop_id']), $this->context->user()->id);

        return $this->ok($assignment, 201);
    }

    private function authorizeTenant(Worker $worker): void
    {
        abort_unless($worker->tenant_id === $this->context->tenantId(), 404);
    }
}
