<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\TireLoadIndex;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TireLoadIndexController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = TireLoadIndex::query();
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where('code', 'ilike', "%{$search}%");
        }

        return $this->paginated($query->orderBy('code')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('tire_load_indices', 'code')->where('tenant_id', $tenantId)],
            'max_load_single_kg' => ['nullable', 'numeric', 'min:0'],
            'max_load_dual_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        $loadIndex = TireLoadIndex::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($loadIndex, 201);
    }

    public function update(Request $request, TireLoadIndex $tireLoadIndex)
    {
        $this->authorizeVisible($tireLoadIndex);
        abort_if($tireLoadIndex->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'max_load_single_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_load_dual_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $tireLoadIndex->update($validated);

        return $this->ok($tireLoadIndex->fresh());
    }

    public function destroy(TireLoadIndex $tireLoadIndex)
    {
        $this->authorizeVisible($tireLoadIndex);
        abort_if($tireLoadIndex->is_system, 403, 'System master data cannot be modified by a tenant.');

        $tireLoadIndex->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(TireLoadIndex $tireLoadIndex): void
    {
        abort_unless($tireLoadIndex->tenant_id === null || $tireLoadIndex->tenant_id === $this->context->tenantId(), 404);
    }
}
