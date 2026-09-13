<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\Uom;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UomController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = Uom::query();
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }

        return $this->paginated($query->orderBy('code')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('uoms', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $uom = Uom::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($uom, 201);
    }

    public function update(Request $request, Uom $uom)
    {
        $this->authorizeVisible($uom);
        abort_if($uom->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $uom->update($validated);

        return $this->ok($uom->fresh());
    }

    public function destroy(Uom $uom)
    {
        $this->authorizeVisible($uom);
        abort_if($uom->is_system, 403, 'System master data cannot be modified by a tenant.');
        abort_if(Product::query()->where('uom_id', $uom->id)->exists(), 422, 'This unit of measure is used by one or more products and cannot be deleted.');

        $uom->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(Uom $uom): void
    {
        abort_unless($uom->tenant_id === null || $uom->tenant_id === $this->context->tenantId(), 404);
    }
}
