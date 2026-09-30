<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Vehicle Brand / Vehicle Model options for the Product form's Vehicle Compatibility
 * (gated by product.view, like the classification lookups — a product editor does not
 * need access to the Vehicle Brand master screens). Active masters visible to the
 * tenant only; models are always filtered by their brand.
 */
class ProductVehicleLookupController extends Controller
{
    public function brands()
    {
        return $this->ok(VehicleBrand::query()->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'tenant_id']));
    }

    public function models(Request $request)
    {
        $request->validate(['vehicle_brand_id' => ['required', 'uuid']]);

        $brand = VehicleBrand::query()->whereKey($request->string('vehicle_brand_id')->value())->first();
        abort_unless($brand !== null, 404);

        return $this->ok(VehicleModel::query()->where('vehicle_brand_id', $brand->id)->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'vehicle_brand_id', 'name']));
    }
}
