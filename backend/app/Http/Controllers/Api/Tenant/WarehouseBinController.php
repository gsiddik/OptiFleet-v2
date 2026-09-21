<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Organization\Models\WarehouseBin;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseBinController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = WarehouseBin::query();
        if ($rackId = $request->string('warehouse_rack_id')->value()) {
            $query->where('warehouse_rack_id', $rackId);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 100)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'warehouse_rack_id' => ['required', 'uuid', Rule::exists('warehouse_racks', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $validated['code'] = strtoupper($validated['code']);
        abort_if(
            WarehouseBin::query()->where('warehouse_rack_id', $validated['warehouse_rack_id'])->where('code', $validated['code'])->exists(),
            422,
            'A bin with this code already exists in this rack.'
        );

        $bin = WarehouseBin::query()->create($validated + ['tenant_id' => $tenantId, 'status' => 'ACTIVE']);

        return $this->ok($bin, 201);
    }

    public function update(Request $request, WarehouseBin $warehouseBin)
    {
        $this->authorizeVisible($warehouseBin);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $warehouseBin->update($validated);

        return $this->ok($warehouseBin->fresh());
    }

    public function destroy(WarehouseBin $warehouseBin)
    {
        $this->authorizeVisible($warehouseBin);
        abort_if(Product::query()->where('default_storage_bin_id', $warehouseBin->id)->exists(), 422, 'This bin is assigned as a product default storage location and cannot be deleted.');

        $warehouseBin->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(WarehouseBin $bin): void
    {
        abort_unless($bin->tenant_id === $this->context->tenantId(), 404);
    }
}
