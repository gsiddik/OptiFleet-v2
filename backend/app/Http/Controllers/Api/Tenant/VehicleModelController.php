<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleModelRequest;
use App\Http\Requests\Tenant\UpdateVehicleModelRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class VehicleModelController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = VehicleModel::query()->with('brand');

        if ($brandId = $request->string('vehicle_brand_id')->value()) {
            $query->where('vehicle_brand_id', $brandId);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreVehicleModelRequest $request)
    {
        $brand = VehicleBrand::query()->findOrFail($request->input('vehicle_brand_id'));
        abort_unless($brand->tenant_id === null || $brand->tenant_id === $this->context->tenantId(), 404);

        $model = VehicleModel::query()->create($request->validated() + [
            'tenant_id' => $this->context->tenantId(),
            'is_system' => false,
            'status' => $request->input('status', 'ACTIVE'),
        ]);

        return $this->ok($model->load('brand'), 201);
    }

    public function update(UpdateVehicleModelRequest $request, VehicleModel $vehicleModel)
    {
        $this->authorizeVisible($vehicleModel);
        abort_if($vehicleModel->is_system, 403, 'System master data cannot be modified by a tenant.');

        $vehicleModel->update($request->validated());

        return $this->ok($vehicleModel->fresh());
    }

    public function destroy(VehicleModel $vehicleModel)
    {
        $this->authorizeVisible($vehicleModel);
        abort_if($vehicleModel->is_system, 403, 'System master data cannot be deleted by a tenant.');
        abort_if(Vehicle::query()->where('vehicle_model_id', $vehicleModel->id)->exists(), 422, 'This model is used by one or more vehicles and cannot be deleted.');

        $vehicleModel->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(VehicleModel $vehicleModel): void
    {
        abort_unless($vehicleModel->tenant_id === null || $vehicleModel->tenant_id === $this->context->tenantId(), 404);
    }
}
