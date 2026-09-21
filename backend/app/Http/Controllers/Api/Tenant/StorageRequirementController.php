<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\StorageRequirement;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorageRequirementController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = StorageRequirement::query();
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
            'code' => ['required', 'string', 'max:50', Rule::unique('storage_requirements', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $storageRequirement = StorageRequirement::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($storageRequirement, 201);
    }

    public function update(Request $request, StorageRequirement $storageRequirement)
    {
        $this->authorizeVisible($storageRequirement);
        abort_if($storageRequirement->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $storageRequirement->update($validated);

        return $this->ok($storageRequirement->fresh());
    }

    public function destroy(StorageRequirement $storageRequirement)
    {
        $this->authorizeVisible($storageRequirement);
        abort_if($storageRequirement->is_system, 403, 'System master data cannot be modified by a tenant.');

        $storageRequirement->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(StorageRequirement $storageRequirement): void
    {
        abort_unless($storageRequirement->tenant_id === null || $storageRequirement->tenant_id === $this->context->tenantId(), 404);
    }
}
