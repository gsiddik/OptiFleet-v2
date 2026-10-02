<?php

namespace App\Domain\Tire\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use App\Domain\Vehicle\Models\Vehicle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle Mapping of a Wheel Configuration master — the only flow that assigns configurations to
 * vehicles. The backend decides everything:
 *
 * - Eligible (left table): vehicles in the user's data scope, not disposed, without an active
 *   mapping to any configuration, whose resolved Vehicle Type equals the configuration's Vehicle
 *   Type and whose axle_count / wheel_count equal the current version's Total Axles / Total Wheels
 *   (wheels include spare tires). Numbers alone never make a vehicle eligible.
 * - Mapped (right table): vehicles actively mapped to any version of this master.
 * - Save: add / remove / update-to-current-version, atomically, in one transaction:
 *   Validate Vehicles → Validate Config → Validate Compatibility → Validate Tire Conflicts
 *   → Apply Mapping → Create History → Commit.
 *   Mapping always targets a specific version; editing the master later never moves vehicles.
 *   A vehicle with an active tire on a position the target version does not have is blocked —
 *   tires are never uninstalled or moved here; positions that stay keep their installations.
 */
class VehicleWheelConfigurationMappingService
{
    public function __construct(private readonly DataScopeService $scope) {}

    /** Header + both tables for the mapping page. */
    public function page(WheelConfigurationMaster $master, User $user): array
    {
        $version = $this->currentVersion($master);

        $mapped = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()
            ->where('tenant_id', $master->tenant_id)
            ->where('wheel_configuration_master_id', $master->id)
            ->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)
            ->with(['version:id,version_number,config_code'])
            ->get()->keyBy('vehicle_id');

        $vehicles = $this->scopedVehicles($master->tenant_id, $user)->get();
        $mappedElsewhere = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()
            ->where('tenant_id', $master->tenant_id)->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)
            ->where('wheel_configuration_master_id', '!=', $master->id)
            ->pluck('vehicle_id')->flip();

        $available = [];
        $mappedRows = [];
        $excluded = ['incomplete_vehicle_data' => 0, 'mapped_to_other_configuration' => 0];
        foreach ($vehicles as $vehicle) {
            if ($mapping = $mapped->get($vehicle->id)) {
                $mappedRows[] = $this->row($vehicle) + [
                    'mapping_id' => $mapping->id,
                    'mapped_at' => $mapping->mapped_at,
                    'version_number' => $mapping->version?->version_number,
                    'mapped_config_code' => $mapping->version?->config_code,
                    'is_current_version' => $mapping->wheel_configuration_version_id === $version->id,
                ];

                continue;
            }
            if ($mappedElsewhere->has($vehicle->id)) {
                $excluded['mapped_to_other_configuration']++;

                continue;
            }
            if ($vehicle->status === 'DISPOSED') {
                continue;
            }
            $type = VehicleTypeClassifier::resolve($vehicle->vehicle_type);
            if ($type === null || $vehicle->axle_count === null || $vehicle->wheel_count === null) {
                $excluded['incomplete_vehicle_data']++;

                continue;
            }
            if ($this->incompatibility($vehicle, $master, $version) === null) {
                $available[] = $this->row($vehicle);
            }
        }

        return [
            'configuration' => [
                'id' => $master->id,
                'vehicle_type' => $master->vehicle_type,
                'truck_configuration_type' => $master->truck_configuration_type,
                'config_code' => $version->config_code,
                'version_id' => $version->id,
                'version_number' => $version->version_number,
                'total_axles' => $version->total_axles,
                'total_wheels' => $version->total_wheels,
                'spare_tires' => $version->spare_tires,
            ],
            'available_vehicles' => $available,
            'mapped_vehicles' => $mappedRows,
            'excluded' => $excluded,
        ];
    }

    /**
     * @param  list<string>  $add  vehicles to map to the current version
     * @param  list<string>  $remove  vehicles to unmap from this configuration
     * @param  list<string>  $update  mapped vehicles to move to the current version
     * @return array{added: int, removed: int, updated: int}
     *
     * @throws ValidationException|WheelConfigurationMappingBlockedException
     */
    public function save(WheelConfigurationMaster $master, array $add, array $remove, array $update, User $user): array
    {
        $all = array_merge($add, $remove, $update);
        if (count($all) !== count(array_unique($all))) {
            throw ValidationException::withMessages(['vehicles' => 'A vehicle can appear only once among added, removed and updated vehicles.']);
        }
        if ($all === []) {
            return ['added' => 0, 'removed' => 0, 'updated' => 0];
        }

        return DB::transaction(function () use ($master, $add, $remove, $update, $all, $user) {
            // Validate Config: the master row is locked so its current version cannot change mid-save.
            $lockedMaster = WheelConfigurationMaster::query()->withoutGlobalScopes()->whereKey($master->id)->lockForUpdate()->firstOrFail();
            $version = $this->currentVersion($lockedMaster);
            $targetCodes = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()
                ->where('wheel_configuration_version_id', $version->id)->pluck('position_code')->all();

            // Validate Vehicles: same tenant, inside the user's data scope; rows locked (stable order).
            $vehicles = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $lockedMaster->tenant_id)
                ->whereIn('id', $all)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $missing = array_values(array_diff($all, $vehicles->keys()->all()));
            if ($missing !== []) {
                throw ValidationException::withMessages(['vehicles' => 'Some vehicles were not found: '.implode(', ', $missing).'.']);
            }
            foreach ($vehicles as $vehicle) {
                if (! $this->scope->canAccessBranch($user, $lockedMaster->tenant_id, $vehicle->branch_id)) {
                    abort(403, "Vehicle {$vehicle->registration_number} is outside your assigned data scope.");
                }
            }
            $active = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()
                ->whereIn('vehicle_id', $all)->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)
                ->with('master:id,config_code')->lockForUpdate()->get()->keyBy('vehicle_id');

            // Validate Compatibility.
            $errors = [];
            foreach ($add as $id) {
                $vehicle = $vehicles[$id];
                if ($current = $active->get($id)) {
                    $errors[] = $current->wheel_configuration_master_id === $lockedMaster->id
                        ? "{$vehicle->registration_number} is already mapped to this configuration."
                        : "{$vehicle->registration_number} is mapped to configuration {$current->master?->config_code}; remove it there first.";
                } elseif ($reason = $this->incompatibility($vehicle, $lockedMaster, $version)) {
                    $errors[] = "{$vehicle->registration_number}: {$reason}";
                }
            }
            foreach ($remove as $id) {
                $current = $active->get($id);
                if (! $current || $current->wheel_configuration_master_id !== $lockedMaster->id) {
                    $errors[] = "{$vehicles[$id]->registration_number} is not mapped to this configuration.";
                }
            }
            foreach ($update as $id) {
                $current = $active->get($id);
                if (! $current || $current->wheel_configuration_master_id !== $lockedMaster->id) {
                    $errors[] = "{$vehicles[$id]->registration_number} is not mapped to this configuration.";
                } elseif ($current->wheel_configuration_version_id === $version->id) {
                    $errors[] = "{$vehicles[$id]->registration_number} already uses version {$version->version_number}.";
                } elseif ($reason = $this->incompatibility($vehicles[$id], $lockedMaster, $version)) {
                    $errors[] = "{$vehicles[$id]->registration_number}: {$reason}";
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['vehicles' => $errors]);
            }

            // Validate Tire Conflicts for every vehicle that gets a (new) position set.
            $blockers = $this->tireConflicts(array_merge($add, $update), $targetCodes);
            if ($blockers !== []) {
                throw new WheelConfigurationMappingBlockedException($blockers);
            }

            // Apply Mapping + Create History.
            $now = now();
            foreach ($remove as $id) {
                $active[$id]->update(['status' => VehicleWheelConfigurationMapping::STATUS_ENDED, 'ended_at' => $now, 'ended_by' => $user->id, 'end_reason' => VehicleWheelConfigurationMapping::END_UNMAPPED]);
            }
            foreach ($update as $id) {
                // End first: one ACTIVE mapping per vehicle (partial unique index).
                $active[$id]->update(['status' => VehicleWheelConfigurationMapping::STATUS_ENDED, 'ended_at' => $now, 'ended_by' => $user->id, 'end_reason' => VehicleWheelConfigurationMapping::END_VERSION_UPDATED]);
                $this->createMapping($lockedMaster, $version, $id, $user, $now, $active[$id]->id);
            }
            foreach ($add as $id) {
                $this->createMapping($lockedMaster, $version, $id, $user, $now, null);
            }

            return ['added' => count($add), 'removed' => count($remove), 'updated' => count($update)];
        });
    }

    /** Why a vehicle cannot use the configuration's current version, or null when compatible. */
    public function incompatibility(Vehicle $vehicle, WheelConfigurationMaster $master, WheelConfigurationVersion $version): ?string
    {
        if ($vehicle->status === 'DISPOSED') {
            return 'the vehicle is disposed.';
        }
        $type = VehicleTypeClassifier::resolve($vehicle->vehicle_type);
        if ($type !== $master->vehicle_type) {
            return 'vehicle type '.($vehicle->vehicle_type ?? '(not set)').' does not match the configuration vehicle type.';
        }
        if ((int) $vehicle->axle_count !== $version->total_axles || $vehicle->axle_count === null) {
            return "total axles ({$this->num($vehicle->axle_count)}) do not match the configuration ({$version->total_axles}).";
        }
        if ((int) $vehicle->wheel_count !== $version->total_wheels || $vehicle->wheel_count === null) {
            return "total wheels ({$this->num($vehicle->wheel_count)}) do not match the configuration ({$version->total_wheels}).";
        }

        return null;
    }

    /**
     * Active tire installations on positions the target position set does not contain.
     *
     * @param  list<string>  $vehicleIds
     * @param  list<string>  $targetCodes
     * @return list<array<string, string>>
     */
    public function tireConflicts(array $vehicleIds, array $targetCodes): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        return DB::table('tire_installations as ti')
            ->join('tires as t', 't.id', '=', 'ti.tire_id')
            ->join('vehicles as v', 'v.id', '=', 'ti.vehicle_id')
            ->whereIn('ti.vehicle_id', $vehicleIds)
            ->whereNull('ti.removed_at')
            ->whereNotIn('ti.wheel_position', $targetCodes)
            ->orderBy('v.registration_number')->orderBy('ti.wheel_position')
            ->get(['v.id as vehicle_id', 'v.registration_number', 'ti.wheel_position', 't.id as tire_id', 't.serial_number'])
            ->map(fn ($r) => [
                'vehicle_id' => $r->vehicle_id,
                'vehicle_registration_number' => $r->registration_number,
                'position_code' => $r->wheel_position,
                'tire_id' => $r->tire_id,
                'tire_serial_number' => $r->serial_number,
            ])->all();
    }

    private function createMapping(WheelConfigurationMaster $master, WheelConfigurationVersion $version, string $vehicleId, User $user, $now, ?string $previousId): void
    {
        VehicleWheelConfigurationMapping::query()->create([
            'tenant_id' => $master->tenant_id,
            'vehicle_id' => $vehicleId,
            'wheel_configuration_master_id' => $master->id,
            'wheel_configuration_version_id' => $version->id,
            'status' => VehicleWheelConfigurationMapping::STATUS_ACTIVE,
            'mapped_at' => $now,
            'mapped_by' => $user->id,
            'previous_mapping_id' => $previousId,
        ]);
    }

    private function currentVersion(WheelConfigurationMaster $master): WheelConfigurationVersion
    {
        return WheelConfigurationVersion::query()->withoutGlobalScopes()->findOrFail($master->current_version_id);
    }

    private function scopedVehicles(string $tenantId, User $user)
    {
        $query = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->with(['vehicleBrand:id,name', 'vehicleModel:id,name', 'branch:id,name'])
            ->orderBy('registration_number');

        return $this->scope->applyBranchScope($query, $user, $tenantId, 'branch_id');
    }

    private function row(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'registration_number' => $vehicle->registration_number,
            'brand' => $vehicle->vehicleBrand?->name ?? $vehicle->brand,
            'model' => $vehicle->vehicleModel?->name ?? $vehicle->model,
            'branch' => $vehicle->branch?->name,
            'vehicle_type' => VehicleTypeClassifier::resolve($vehicle->vehicle_type),
            'axle_count' => $vehicle->axle_count,
            'wheel_count' => $vehicle->wheel_count,
            'status' => $vehicle->status,
        ];
    }

    private function num(?int $value): string
    {
        return $value === null ? 'not set' : (string) $value;
    }
}
