<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\WarehouseZone;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseZoneController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = WarehouseZone::query();
        if ($warehouseId = $request->string('warehouse_id')->value()) {
            $query->where('warehouse_id', $warehouseId);
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
            'warehouse_id' => ['required', 'uuid', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $validated['code'] = strtoupper($validated['code']);
        $this->assertUniqueCode($validated['warehouse_id'], $validated['code']);

        $zone = WarehouseZone::query()->create($validated + ['tenant_id' => $tenantId, 'status' => 'ACTIVE']);

        return $this->ok($zone, 201);
    }

    public function update(Request $request, WarehouseZone $warehouseZone)
    {
        $this->authorizeVisible($warehouseZone);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $warehouseZone->update($validated);

        return $this->ok($warehouseZone->fresh());
    }

    public function destroy(WarehouseZone $warehouseZone)
    {
        $this->authorizeVisible($warehouseZone);
        abort_if($warehouseZone->racks()->exists(), 422, 'This zone still has racks and cannot be deleted.');

        $warehouseZone->delete();

        return $this->ok(['deleted' => true]);
    }

    private function assertUniqueCode(string $warehouseId, string $code): void
    {
        abort_if(
            WarehouseZone::query()->where('warehouse_id', $warehouseId)->where('code', $code)->exists(),
            422,
            'A zone with this code already exists in this warehouse.'
        );
    }

    private function authorizeVisible(WarehouseZone $zone): void
    {
        abort_unless($zone->tenant_id === $this->context->tenantId(), 404);
    }
}
