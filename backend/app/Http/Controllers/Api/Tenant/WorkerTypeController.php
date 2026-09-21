<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkerType;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkerTypeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = WorkerType::query();
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('worker_types', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $workerType = WorkerType::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($workerType, 201);
    }

    public function update(Request $request, WorkerType $workerType)
    {
        $this->authorizeVisible($workerType);
        abort_if($workerType->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $workerType->update($validated);

        return $this->ok($workerType->fresh());
    }

    public function destroy(WorkerType $workerType)
    {
        $this->authorizeVisible($workerType);
        abort_if($workerType->is_system, 403, 'System master data cannot be modified by a tenant.');
        abort_if(Worker::query()->where('worker_type_id', $workerType->id)->exists(), 422, 'This worker type is assigned to one or more workers and cannot be deleted.');

        $workerType->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(WorkerType $workerType): void
    {
        abort_unless($workerType->tenant_id === null || $workerType->tenant_id === $this->context->tenantId(), 404);
    }
}
