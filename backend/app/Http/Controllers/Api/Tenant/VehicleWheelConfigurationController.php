<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Services\VehicleTireRegistrationService;
use App\Domain\Tire\Services\VehicleTypeClassifier;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;

/**
 * Vehicle Detail → Wheels Configuration: the vehicle's mapped configuration VERSION (not the latest
 * master version), its positions, the tire actively installed on each position and the mapping
 * history. Read-only; mapping changes happen only on the configuration's Vehicle Mapping page.
 */
class VehicleWheelConfigurationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DataScopeService $scope,
        private readonly VehicleTireRegistrationService $registrations,
    ) {}

    public function show(Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        $mapping = $vehicle->activeWheelConfigurationMapping()->with(['master', 'version.positions'])->first();
        $history = VehicleWheelConfigurationMapping::query()->where('vehicle_id', $vehicle->id)
            ->with(['version:id,version_number,config_code'])->orderByDesc('mapped_at')->get()
            ->map(fn (VehicleWheelConfigurationMapping $m) => [
                'id' => $m->id,
                'config_code' => $m->version?->config_code,
                'version_number' => $m->version?->version_number,
                'status' => $m->status,
                'mapped_at' => $m->mapped_at,
                'ended_at' => $m->ended_at,
                'end_reason' => $m->end_reason,
            ]);

        return $this->ok([
            'vehicle' => [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'vehicle_type' => VehicleTypeClassifier::resolve($vehicle->vehicle_type),
                'axle_count' => $vehicle->axle_count,
                'wheel_count' => $vehicle->wheel_count,
            ],
            'mapping' => $mapping ? [
                'id' => $mapping->id,
                'mapped_at' => $mapping->mapped_at,
                'master' => $mapping->master->only(['id', 'vehicle_type', 'truck_configuration_type', 'config_code', 'current_version_id']),
                'version' => $mapping->version,
                'is_current_version' => $mapping->wheel_configuration_version_id === $mapping->master->current_version_id,
            ] : null,
            'installations' => $this->registrations->activeInstallations($vehicle),
            'history' => $history,
        ]);
    }

    private function authorizeVehicle(Vehicle $vehicle): void
    {
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id),
            403,
            'This vehicle is outside your assigned data scope.'
        );
    }
}
