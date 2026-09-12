<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkerSkill;
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

        $query = Worker::query()->with(['branch', 'workshop', 'skills.componentGroup']);
        $this->scope->applyWorkshopScope($query, $user, $tenantId, 'workshop_id');

        foreach (['status', 'worker_type', 'branch_id', 'workshop_id'] as $filter) {
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
        $worker = Worker::query()->create($request->validated() + ['status' => 'ACTIVE']);

        return $this->ok($worker, 201);
    }

    public function show(Worker $worker)
    {
        $this->authorizeTenant($worker);

        return $this->ok($worker->load(['branch', 'workshop', 'skills.componentGroup', 'assignments']));
    }

    public function update(Request $request, Worker $worker)
    {
        $this->authorizeTenant($worker);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'worker_type' => ['sometimes', 'in:LEAD_MECHANIC,MECHANIC,TECHNICIAN,INSPECTOR,QC'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $worker->update($validated);

        return $this->ok($worker->fresh());
    }

    public function addSkill(Request $request, Worker $worker)
    {
        $this->authorizeTenant($worker);
        $request->validate([
            'component_group_id' => ['required', 'uuid', 'exists:component_groups,id'],
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
