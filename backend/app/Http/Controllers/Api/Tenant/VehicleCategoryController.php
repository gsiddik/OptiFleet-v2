<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleCategoryRequest;
use App\Http\Requests\Tenant\UpdateVehicleCategoryRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class VehicleCategoryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = VehicleCategory::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreVehicleCategoryRequest $request)
    {
        $category = VehicleCategory::query()->create($request->validated() + [
            'tenant_id' => $this->context->tenantId(),
            'is_system' => false,
            'status' => $request->input('status', 'ACTIVE'),
        ]);

        return $this->ok($category, 201);
    }

    public function show(VehicleCategory $vehicleCategory)
    {
        $this->authorizeVisible($vehicleCategory);

        return $this->ok($vehicleCategory->load('componentGroups'));
    }

    public function update(UpdateVehicleCategoryRequest $request, VehicleCategory $vehicleCategory)
    {
        $this->authorizeVisible($vehicleCategory);
        abort_if($vehicleCategory->is_system, 403, 'System master data cannot be modified by a tenant.');

        $vehicleCategory->update($request->validated());

        return $this->ok($vehicleCategory);
    }

    public function destroy(VehicleCategory $vehicleCategory)
    {
        $this->authorizeVisible($vehicleCategory);
        abort_if($vehicleCategory->is_system, 403, 'System master data cannot be deleted by a tenant.');

        $vehicleCategory->update(['status' => 'INACTIVE']);
        $vehicleCategory->delete();

        return $this->message('Vehicle category deactivated.');
    }

    private function authorizeVisible(VehicleCategory $vehicleCategory): void
    {
        // Explicit ownership check — a tenant may see platform/system rows
        // (tenant_id null) and its own rows, never another tenant's.
        abort_unless(
            $vehicleCategory->tenant_id === null || $vehicleCategory->tenant_id === $this->context->tenantId(),
            404
        );
    }
}
