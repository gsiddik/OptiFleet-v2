<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\ToolType;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToolTypeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = ToolType::query();
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
            'code' => ['required', 'string', 'max:50', Rule::unique('tool_types', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $toolType = ToolType::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($toolType, 201);
    }

    public function update(Request $request, ToolType $toolType)
    {
        $this->authorizeVisible($toolType);
        abort_if($toolType->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $toolType->update($validated);

        return $this->ok($toolType->fresh());
    }

    public function destroy(ToolType $toolType)
    {
        $this->authorizeVisible($toolType);
        abort_if($toolType->is_system, 403, 'System master data cannot be modified by a tenant.');

        $toolType->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(ToolType $toolType): void
    {
        abort_unless($toolType->tenant_id === null || $toolType->tenant_id === $this->context->tenantId(), 404);
    }
}
