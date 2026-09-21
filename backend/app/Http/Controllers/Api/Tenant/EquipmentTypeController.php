<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\EquipmentType;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentTypeController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = EquipmentType::query();
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
            'code' => ['required', 'string', 'max:50', Rule::unique('equipment_types', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $equipmentType = EquipmentType::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($equipmentType, 201);
    }

    public function update(Request $request, EquipmentType $equipmentType)
    {
        $this->authorizeVisible($equipmentType);
        abort_if($equipmentType->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $equipmentType->update($validated);

        return $this->ok($equipmentType->fresh());
    }

    public function destroy(EquipmentType $equipmentType)
    {
        $this->authorizeVisible($equipmentType);
        abort_if($equipmentType->is_system, 403, 'System master data cannot be modified by a tenant.');

        $equipmentType->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(EquipmentType $equipmentType): void
    {
        abort_unless($equipmentType->tenant_id === null || $equipmentType->tenant_id === $this->context->tenantId(), 404);
    }
}
