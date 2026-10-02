<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Wheel Configuration master/template Save:
 *
 *   Validate → Generate Config Code → Generate Position List → Save Configuration Version
 *
 * A master is a reusable template, not linked to any vehicle (vehicle assignment — and the
 * installed-tire checks that belong to it — is a separate future feature). Editing a master
 * creates a new version; the previous version becomes INACTIVE and keeps its own position list,
 * and the new version stores the position diff (unchanged / added / removed) against it.
 *
 * The Config Code and positions always come from WheelConfigurationRules; a client-sent
 * config_code is only compared. Identity = vehicle_type + truck_configuration_type + config_code,
 * so "22.222", "+22.222" and "-22.222" are different configurations.
 */
class WheelConfigurationMasterService
{
    public function __construct(private readonly WheelConfigurationRules $rules) {}

    /** Dry run for the Save confirmation: generated configuration, diff vs the current version, duplicate. */
    public function preview(string $tenantId, array $input, ?WheelConfigurationMaster $master = null): array
    {
        $config = $this->evaluate($input, $master);
        $current = $master?->currentVersion()->with('positions')->first();
        $duplicate = $this->duplicateOf($tenantId, $config, $master?->id);

        return [
            'configuration' => $config,
            'current_version' => $current ? collect($current->toArray())->except('positions')->all() : null,
            'changed' => ! $current || ! $this->sameAsVersion($config, $current),
            'diff' => $current ? $this->diff($current->positions->pluck('position_code')->all(), $config['positions']) : null,
            'duplicate' => $duplicate?->only(['id', 'vehicle_type', 'truck_configuration_type', 'config_code']),
            // Edit impact: mapped vehicles stay on the version they were mapped to.
            'mapped_vehicle_count' => $master ? $this->mappedVehicleCount($master) : 0,
        ];
    }

    public function mappedVehicleCount(WheelConfigurationMaster $master): int
    {
        return VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()
            ->where('wheel_configuration_master_id', $master->id)
            ->where('status', VehicleWheelConfigurationMapping::STATUS_ACTIVE)->count();
    }

    /** @return array{master: WheelConfigurationMaster, version: WheelConfigurationVersion} */
    public function create(string $tenantId, array $input, ?string $userId): array
    {
        $config = $this->evaluate($input, null);

        return $this->guardIdentity(fn () => DB::transaction(function () use ($tenantId, $config, $userId) {
            $this->assertNoDuplicate($tenantId, $config, null);

            $master = WheelConfigurationMaster::query()->create([
                'tenant_id' => $tenantId,
                'vehicle_type' => $config['vehicle_type'],
                'truck_configuration_type' => $config['truck_configuration_type'],
                'config_code' => $config['config_code'],
                'status' => WheelConfigurationMaster::STATUS_ACTIVE,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $version = $this->createVersion($master, 1, $config, $this->diff([], $config['positions']), $userId);
            $master->update(['current_version_id' => $version->id]);

            return ['master' => $master->fresh(), 'version' => $version];
        }));
    }

    /**
     * New version of an existing master. Vehicle Type / Truck Configuration Type are part of the
     * master's identity and cannot change; an unchanged configuration creates no version.
     *
     * @return array{master: WheelConfigurationMaster, version: WheelConfigurationVersion, created: bool}
     */
    public function update(WheelConfigurationMaster $master, array $input, ?string $userId): array
    {
        $config = $this->evaluate($input, $master);

        return $this->guardIdentity(fn () => DB::transaction(function () use ($master, $config, $userId) {
            $locked = WheelConfigurationMaster::query()->whereKey($master->id)->lockForUpdate()->firstOrFail();
            $current = WheelConfigurationVersion::query()->with('positions')->findOrFail($locked->current_version_id);
            if ($this->sameAsVersion($config, $current)) {
                return ['master' => $locked, 'version' => $current, 'created' => false];
            }
            $this->assertNoDuplicate($locked->tenant_id, $config, $locked->id);

            $now = now();
            // Deactivate first: one ACTIVE version per master (partial unique index).
            $current->update(['status' => WheelConfigurationVersion::STATUS_INACTIVE, 'deactivated_at' => $now]);
            $diff = $this->diff($current->positions->pluck('position_code')->all(), $config['positions']);
            $version = $this->createVersion($locked, $current->version_number + 1, $config, $diff, $userId);
            $locked->update(['config_code' => $config['config_code'], 'current_version_id' => $version->id, 'updated_by' => $userId]);

            return ['master' => $locked->fresh(), 'version' => $version, 'created' => true];
        }));
    }

    /** @throws ValidationException */
    private function evaluate(array $input, ?WheelConfigurationMaster $master): array
    {
        if ($master) {
            foreach (['vehicle_type', 'truck_configuration_type'] as $field) {
                if (array_key_exists($field, $input) && $input[$field] !== $master->{$field}) {
                    throw ValidationException::withMessages([
                        $field => 'The Vehicle Type and Truck Configuration Type of a saved configuration cannot be changed. Create a new configuration instead.',
                    ]);
                }
            }
            $input = ['vehicle_type' => $master->vehicle_type, 'truck_configuration_type' => $master->truck_configuration_type] + $input;
        }

        $config = $this->rules->evaluate($input);

        $clientCode = $input['config_code'] ?? null;
        if ($clientCode !== null && $clientCode !== $config['config_code']) {
            throw ValidationException::withMessages([
                'config_code' => "Config Code '{$clientCode}' does not match the configuration; the server generated '{$config['config_code']}'.",
            ]);
        }

        return $config;
    }

    private function sameAsVersion(array $config, WheelConfigurationVersion $version): bool
    {
        return array_map('intval', $version->front_axles) === $config['front_axles']
            && array_map('intval', $version->rear_axles) === $config['rear_axles']
            && $version->spare_tires === $config['spare_tires'];
    }

    /** @param list<string> $previousCodes */
    private function diff(array $previousCodes, array $positions): array
    {
        $codes = array_column($positions, 'position_code');

        return [
            'unchanged' => array_values(array_intersect($codes, $previousCodes)),
            'added' => array_values(array_diff($codes, $previousCodes)),
            'removed' => array_values(array_diff($previousCodes, $codes)),
        ];
    }

    private function createVersion(WheelConfigurationMaster $master, int $number, array $config, array $diff, ?string $userId): WheelConfigurationVersion
    {
        $version = WheelConfigurationVersion::query()->create([
            'tenant_id' => $master->tenant_id,
            'wheel_configuration_master_id' => $master->id,
            'version_number' => $number,
            'config_code' => $config['config_code'],
            'front_axles' => $config['front_axles'],
            'rear_axles' => $config['rear_axles'],
            'spare_tires' => $config['spare_tires'],
            'total_axles' => $config['total_axles'],
            'total_wheels' => $config['total_wheels'],
            'status' => WheelConfigurationVersion::STATUS_ACTIVE,
            'position_diff' => $diff,
            'created_by' => $userId,
            'activated_at' => now(),
        ]);

        foreach ($config['positions'] as $p) {
            WheelConfigurationVersionPosition::query()->create([
                'tenant_id' => $master->tenant_id,
                'wheel_configuration_version_id' => $version->id,
                'position_code' => $p['position_code'],
                'position_group' => $p['group'],
                'axle_in_group' => $p['axle_in_group'],
                'axle_number' => $p['axle_number'],
                'side' => $p['side'],
                'wheel_index' => $p['wheel_index'],
                'label' => $p['label'],
                'sequence' => $p['sequence'],
            ]);
        }

        return $version;
    }

    private function duplicateOf(string $tenantId, array $config, ?string $exceptId): ?WheelConfigurationMaster
    {
        return WheelConfigurationMaster::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', WheelConfigurationMaster::STATUS_ACTIVE)
            ->where('vehicle_type', $config['vehicle_type'])
            ->where(fn ($q) => $config['truck_configuration_type'] === null
                ? $q->whereNull('truck_configuration_type')
                : $q->where('truck_configuration_type', $config['truck_configuration_type']))
            ->where('config_code', $config['config_code'])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();
    }

    private function assertNoDuplicate(string $tenantId, array $config, ?string $exceptId): void
    {
        if ($this->duplicateOf($tenantId, $config, $exceptId)) {
            $this->throwDuplicate($config);
        }
    }

    private function throwDuplicate(array $config): never
    {
        throw ValidationException::withMessages([
            'config_code' => "A wheel configuration {$config['config_code']} already exists for this vehicle type".($config['truck_configuration_type'] ? ' and truck configuration type' : '').'. Edit that configuration instead.',
        ]);
    }

    /** The identity unique index is the final guard against concurrent saves of the same configuration. */
    private function guardIdentity(callable $save): array
    {
        try {
            return $save();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'wheel_configuration_masters_identity')) {
                throw ValidationException::withMessages(['config_code' => 'This wheel configuration already exists. Edit that configuration instead.']);
            }
            throw $e;
        }
    }
}
