<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Services\VehicleBrandLogoService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleBrandRequest;
use App\Http\Requests\Tenant\UpdateVehicleBrandRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VehicleBrandController extends Controller
{
    public function __construct(
        private readonly VehicleBrandLogoService $logos,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = VehicleBrand::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreVehicleBrandRequest $request)
    {
        $brand = VehicleBrand::query()->create($request->validated() + [
            'tenant_id' => $this->context->tenantId(),
            'is_system' => false,
            'status' => $request->input('status', 'ACTIVE'),
        ]);

        return $this->ok($brand, 201);
    }

    public function update(UpdateVehicleBrandRequest $request, VehicleBrand $vehicleBrand)
    {
        $this->authorizeVisible($vehicleBrand);
        abort_if($vehicleBrand->is_system, 403, 'System master data cannot be modified by a tenant.');

        $vehicleBrand->update($request->validated());

        return $this->ok($vehicleBrand->fresh());
    }

    public function destroy(VehicleBrand $vehicleBrand)
    {
        $this->authorizeVisible($vehicleBrand);
        abort_if($vehicleBrand->is_system, 403, 'System master data cannot be deleted by a tenant.');
        abort_if(Vehicle::query()->where('vehicle_brand_id', $vehicleBrand->id)->exists(), 422, 'This brand is used by one or more vehicles and cannot be deleted.');

        $vehicleBrand->delete();

        return $this->ok(['deleted' => true]);
    }

    public function uploadLogo(Request $request, VehicleBrand $vehicleBrand)
    {
        $this->authorizeVisible($vehicleBrand);
        abort_if($vehicleBrand->is_system, 403, 'System master data cannot be modified by a tenant.');

        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png']]);

        $vehicleBrand = $this->logos->upload($vehicleBrand, $request->file('file'));

        return $this->ok($vehicleBrand);
    }

    public function showLogo(VehicleBrand $vehicleBrand)
    {
        $this->authorizeVisible($vehicleBrand);
        abort_unless($vehicleBrand->logo_path !== null, 404);

        return Storage::disk($vehicleBrand->logo_disk)->response($vehicleBrand->logo_path);
    }

    private function authorizeVisible(VehicleBrand $vehicleBrand): void
    {
        abort_unless($vehicleBrand->tenant_id === null || $vehicleBrand->tenant_id === $this->context->tenantId(), 404);
    }
}
