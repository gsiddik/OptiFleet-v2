<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Organization\Models\WarehouseRack;
use App\Domain\Organization\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseRackController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = WarehouseRack::query();
        if ($zoneId = $request->string('warehouse_zone_id')->value()) {
            $query->where('warehouse_zone_id', $zoneId);
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
            'warehouse_zone_id' => ['required', 'uuid', Rule::exists('warehouse_zones', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $validated['code'] = strtoupper($validated['code']);
        abort_if(
            WarehouseRack::query()->where('warehouse_zone_id', $validated['warehouse_zone_id'])->where('code', $validated['code'])->exists(),
            422,
            'A rack with this code already exists in this zone.'
        );

        $rack = WarehouseRack::query()->create($validated + ['tenant_id' => $tenantId, 'status' => 'ACTIVE']);

        return $this->ok($rack, 201);
    }

    public function update(Request $request, WarehouseRack $warehouseRack)
    {
        $this->authorizeVisible($warehouseRack);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $warehouseRack->update($validated);

        return $this->ok($warehouseRack->fresh());
    }

    public function destroy(WarehouseRack $warehouseRack)
    {
        $this->authorizeVisible($warehouseRack);
        abort_if($warehouseRack->bins()->exists(), 422, 'This rack still has bins and cannot be deleted.');

        $warehouseRack->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(WarehouseRack $rack): void
    {
        abort_unless($rack->tenant_id === $this->context->tenantId(), 404);
    }
}
