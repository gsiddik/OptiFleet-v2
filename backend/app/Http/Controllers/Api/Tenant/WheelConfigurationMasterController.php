<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Tire\Services\VehicleTypeClassifier;
use App\Domain\Tire\Services\WheelConfigurationMasterService;
use App\Domain\Tire\Services\WheelConfigurationRules;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Wheel Configuration masters/templates (Vehicle Type + Truck Configuration Type + axle pattern),
 * versioned, each version with its generated position list. No vehicle is involved; assigning a
 * configuration to vehicles is a separate future feature. Configuration fields are validated by
 * WheelConfigurationRules inside the service.
 */
class WheelConfigurationMasterController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly WheelConfigurationMasterService $service,
        private readonly DataScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $request->validate([
            'vehicle_type' => ['nullable', Rule::in(WheelConfigurationRules::VEHICLE_TYPES)],
            'status' => ['nullable', Rule::in([WheelConfigurationMaster::STATUS_ACTIVE, WheelConfigurationMaster::STATUS_INACTIVE])],
            'compatible_vehicle_id' => ['nullable', 'uuid'],
        ]);

        $query = WheelConfigurationMaster::query()
            ->where('tenant_id', $this->context->tenantId())
            ->with('currentVersion');
        if ($type = $request->string('vehicle_type')->value()) {
            $query->where('vehicle_type', $type);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where('config_code', 'ilike', "%{$search}%");
        }
        // Vehicle Detail → "choose a configuration": only configurations the vehicle is compatible
        // with (same rule as Vehicle Mapping eligibility; decided here, not in the browser).
        if ($vehicleId = $request->string('compatible_vehicle_id')->value()) {
            $vehicle = Vehicle::query()->find($vehicleId);
            abort_unless($vehicle && $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id), 404);
            $query->where('vehicle_type', VehicleTypeClassifier::resolve($vehicle->vehicle_type) ?? '-')
                ->whereHas('currentVersion', fn ($v) => $v->where('total_axles', $vehicle->axle_count ?? -1)->where('total_wheels', $vehicle->wheel_count ?? -1));
        }

        return $this->paginated($query->orderBy('vehicle_type')->orderBy('truck_configuration_type')->orderBy('config_code')->paginate($request->integer('per_page', 20)));
    }

    public function show(WheelConfigurationMaster $wheelConfigurationMaster)
    {
        return $this->ok($wheelConfigurationMaster->load(['currentVersion.positions', 'versions.positions'])->toArray()
            + ['mapped_vehicle_count' => $this->service->mappedVehicleCount($wheelConfigurationMaster)]);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'wheel_configuration_master_id' => ['nullable', 'uuid'],
            'config_code' => ['nullable', 'string', 'max:40'],
        ]);
        $master = null;
        if ($id = $request->string('wheel_configuration_master_id')->value()) {
            // Tenant-scoped: another tenant's master is not found.
            $master = WheelConfigurationMaster::query()->findOrFail($id);
        }

        return $this->ok($this->service->preview($this->context->tenantId(), $request->except('wheel_configuration_master_id'), $master));
    }

    public function store(Request $request)
    {
        $request->validate(['config_code' => ['nullable', 'string', 'max:40']]);
        $result = $this->service->create($this->context->tenantId(), $request->all(), $request->user()?->id);

        return $this->ok(['master' => $result['master'], 'version' => $result['version']->load('positions'), 'created' => true], 201);
    }

    public function update(Request $request, WheelConfigurationMaster $wheelConfigurationMaster)
    {
        $request->validate(['config_code' => ['nullable', 'string', 'max:40']]);
        $result = $this->service->update($wheelConfigurationMaster, $request->all(), $request->user()?->id);

        return $this->ok(['master' => $result['master'], 'version' => $result['version']->load('positions'), 'created' => $result['created']], $result['created'] ? 201 : 200);
    }
}
