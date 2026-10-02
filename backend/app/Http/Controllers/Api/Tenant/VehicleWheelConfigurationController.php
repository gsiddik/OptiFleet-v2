<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Services\VehicleTireRegistrationService;
use App\Domain\Tire\Services\VehicleTypeClassifier;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

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

    /**
     * Initial / last-known registration of a tire that is already on the vehicle. Never a warehouse
     * issue: no stock, stock movement, reservation or receipt is created.
     */
    public function registerTire(Request $request, Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);
        $request->merge(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $request->only(['serial_number', 'installed_time', 'installation_km', 'tread_depth_mm', 'position_code'])));
        $validated = $request->validate([
            'position_code' => ['required', 'string', 'max:20'],
            'installed_date' => ['required', 'date_format:Y-m-d'],
            'installed_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // Decimal text (no float rounding): up to 2 decimals, never negative.
            'installation_km' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'product_id' => ['required', 'uuid'],
            'serial_number' => ['required', 'string', 'max:100'],
            'tread_depth_mm' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ], [
            'installed_time.regex' => 'Use the 24-hour format HH:mm, for example 07:30 or 14:05.',
            'installation_km.regex' => 'Enter a number of kilometres (0 or more, up to 2 decimals), for example 12500.75.',
            'tread_depth_mm.regex' => 'Enter a tread depth in mm (0 or more, up to 2 decimals), for example 8.5.',
        ]);

        $installation = $this->registrations->register($vehicle, $validated, $this->context->user()->id);

        return $this->ok([
            'installation_id' => $installation->id,
            'installations' => $this->registrations->activeInstallations($vehicle),
        ], 201);
    }

    /** Products of Item Type Tire for the registration form (server-side search). */
    public function tireProducts(Request $request, Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        return $this->ok($this->registrations->tireProducts($this->context->tenantId(), $request->string('search')->trim()->value() ?: null));
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
