<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Tire\Services\VehicleWheelConfigurationMappingService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** Vehicle Mapping page of a wheel configuration master (tenant-scoped via route model binding). */
class VehicleWheelConfigurationMappingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly VehicleWheelConfigurationMappingService $service,
    ) {}

    public function show(WheelConfigurationMaster $wheelConfigurationMaster)
    {
        return $this->ok($this->service->page($wheelConfigurationMaster, $this->context->user()));
    }

    public function update(Request $request, WheelConfigurationMaster $wheelConfigurationMaster)
    {
        $validated = $request->validate([
            'add_vehicle_ids' => ['present', 'array', 'max:500'],
            'add_vehicle_ids.*' => ['uuid'],
            'remove_vehicle_ids' => ['present', 'array', 'max:500'],
            'remove_vehicle_ids.*' => ['uuid'],
            'update_vehicle_ids' => ['sometimes', 'array', 'max:500'],
            'update_vehicle_ids.*' => ['uuid'],
        ]);

        $summary = $this->service->save(
            $wheelConfigurationMaster,
            array_values($validated['add_vehicle_ids']),
            array_values($validated['remove_vehicle_ids']),
            array_values($validated['update_vehicle_ids'] ?? []),
            $this->context->user(),
        );

        return $this->ok(['summary' => $summary] + $this->service->page($wheelConfigurationMaster->fresh(), $this->context->user()));
    }
}
