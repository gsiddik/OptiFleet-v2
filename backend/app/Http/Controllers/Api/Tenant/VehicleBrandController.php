<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Services\VehicleBrandLogoService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreVehicleBrandRequest;
use App\Http\Requests\Tenant\UpdateVehicleBrandRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;

class VehicleBrandController extends Controller
{
    public function __construct(
        private readonly VehicleBrandLogoService $logos,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = VehicleBrand::query()->with('vehicleCategories:id,code,name,status');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    /** "Brand Of" choices: every active Vehicle Category visible to the tenant (the master, never a fixed list). */
    public function categoryOptions()
    {
        return $this->ok(VehicleCategory::query()->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'code', 'name']));
    }

    public function store(StoreVehicleBrandRequest $request)
    {
        $brand = DB::transaction(function () use ($request) {
            $brand = VehicleBrand::query()->create(collect($request->validated())->except('vehicle_category_ids')->all() + [
                'tenant_id' => $this->context->tenantId(),
                'is_system' => false,
                'status' => $request->input('status', 'ACTIVE'),
            ]);
            $this->syncCategories($brand, $request->validated('vehicle_category_ids'));

            return $brand;
        });

        return $this->ok($brand->load('vehicleCategories:id,code,name,status'), 201);
    }

    public function update(UpdateVehicleBrandRequest $request, VehicleBrand $vehicleBrand)
    {
        $this->authorizeVisible($vehicleBrand);
        abort_if($vehicleBrand->is_system, 403, 'System master data cannot be modified by a tenant.');

        DB::transaction(function () use ($request, $vehicleBrand) {
            $vehicleBrand->update(collect($request->validated())->except('vehicle_category_ids')->all());
            if ($request->has('vehicle_category_ids')) {
                $this->syncCategories($vehicleBrand, $request->validated('vehicle_category_ids'));
            }
        });

        return $this->ok($vehicleBrand->fresh()->load('vehicleCategories:id,code,name,status'));
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

    /**
     * "Brand Of" = active Vehicle Categories visible to the tenant. A category the brand is
     * already linked to stays accepted even if it was deactivated since (no silent loss).
     */
    private function syncCategories(VehicleBrand $brand, ?array $categoryIds): void
    {
        $categoryIds = array_values(array_unique($categoryIds ?? []));
        $linked = $brand->vehicleCategories()->pluck('vehicle_categories.id')->all();
        $valid = VehicleCategory::query()->whereIn('id', $categoryIds)
            ->where(fn ($q) => $q->where('status', 'ACTIVE')->orWhereIn('id', $linked))
            ->pluck('id')->all();

        if (count($valid) !== count($categoryIds)) {
            throw ValidationException::withMessages(['vehicle_category_ids' => 'Select only active Vehicle Categories.']);
        }

        $brand->vehicleCategories()->sync($valid);
    }

    private function authorizeVisible(VehicleBrand $vehicleBrand): void
    {
        abort_unless($vehicleBrand->tenant_id === null || $vehicleBrand->tenant_id === $this->context->tenantId(), 404);
    }
}
