<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\WheelConfiguration;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production Save of "New Wheels Configuration" — versioning + position-set diffing, never
 * delete-all-and-recreate. One transaction under an exclusive (tenant, category) lock:
 *
 *   Validate → Generate Positions → Diff → Check Active Tire Installations → Create Version
 *   → Apply Position Changes → Activate → Commit
 *
 * - The Config Code and positions are regenerated here (WheelConfigurationRules); a client-sent
 *   config_code is only compared, never trusted. "22.222", "+22.222" and "-22.222" are different
 *   configurations (the Truck Configuration Type is part of the identity and stored explicitly).
 * - Diff of position codes against the positions that currently apply: UNCHANGED / ADDED / REMOVED.
 * - A tire still installed on a position that would no longer exist blocks the save; tires are
 *   never uninstalled or relocated automatically.
 * - REMOVED positions are RETIRED (kept for installation/rotation history), never hard-deleted;
 *   a later version that brings a code back reactivates the same row.
 */
class WheelConfigurationVersionService
{
    public function __construct(
        private readonly WheelConfigurationRules $rules,
        private readonly WheelPositionCatalog $catalog,
    ) {}

    /** Dry run: what saving would do, without changing anything. */
    public function preview(string $tenantId, string $vehicleCategoryId, array $input): array
    {
        $plan = $this->plan($tenantId, $vehicleCategoryId, $input);

        return $this->present($plan);
    }

    /**
     * @return array{version: WheelConfigurationVersion, created: bool, diff: array}
     *
     * @throws ValidationException|WheelConfigurationBlockedException
     */
    public function save(string $tenantId, string $vehicleCategoryId, array $input, ?string $userId): array
    {
        // Validate before taking the lock; validation is repeated inside (pure, cheap).
        $this->rules->evaluate($input);

        return DB::transaction(function () use ($tenantId, $vehicleCategoryId, $input, $userId) {
            $this->catalog->lock($tenantId, $vehicleCategoryId, exclusive: true);

            $plan = $this->plan($tenantId, $vehicleCategoryId, $input);
            if ($plan['identical'] && $plan['active']) {
                return ['version' => $plan['active'], 'created' => false, 'diff' => $plan['diff']];
            }
            if ($plan['blockers']) {
                throw new WheelConfigurationBlockedException($plan['blockers']);
            }

            $config = $plan['config'];
            $now = now();
            $nextNumber = (int) WheelConfigurationVersion::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('vehicle_category_id', $vehicleCategoryId)
                ->max('version_number') + 1;

            // Supersede first: at most one ACTIVE version per (tenant, category) — unique index.
            if ($plan['active']) {
                $plan['active']->update(['status' => WheelConfigurationVersion::STATUS_SUPERSEDED, 'superseded_at' => $now]);
            }

            $version = WheelConfigurationVersion::query()->create([
                'tenant_id' => $tenantId,
                'vehicle_category_id' => $vehicleCategoryId,
                'version_number' => $nextNumber,
                'vehicle_type' => $config['vehicle_type'],
                'truck_configuration_type' => $config['truck_configuration_type'],
                'config_code' => $config['config_code'],
                'front_axles' => $config['front_axles'],
                'rear_axles' => $config['rear_axles'],
                'spare_tires' => $config['spare_tires'],
                'total_axles' => $config['total_axles'],
                'total_wheels' => $config['total_wheels'],
                'status' => WheelConfigurationVersion::STATUS_ACTIVE,
                'position_diff' => $plan['diff'],
                'created_by' => $userId,
                'activated_at' => $now,
            ]);

            $this->applyPositions($tenantId, $vehicleCategoryId, $config['positions'], $plan['diff'], $version, $now);

            return ['version' => $version->fresh(), 'created' => true, 'diff' => $plan['diff']];
        });
    }

    /**
     * Validate → Generate Positions → Diff → Check Active Tire Installations.
     *
     * @throws ValidationException
     */
    private function plan(string $tenantId, string $vehicleCategoryId, array $input): array
    {
        $config = $this->rules->evaluate($input);

        $clientCode = $input['config_code'] ?? null;
        if ($clientCode !== null && $clientCode !== $config['config_code']) {
            throw ValidationException::withMessages([
                'config_code' => "Config Code '{$clientCode}' does not match the configuration; the server generated '{$config['config_code']}'.",
            ]);
        }

        $active = $this->catalog->activeVersion($tenantId, $vehicleCategoryId);
        $identical = $active !== null
            && $active->vehicle_type === $config['vehicle_type']
            && $active->truck_configuration_type === $config['truck_configuration_type']
            && array_map('intval', $active->front_axles) === $config['front_axles']
            && array_map('intval', $active->rear_axles) === $config['rear_axles']
            && $active->spare_tires === $config['spare_tires'];

        $generated = array_column($config['positions'], 'position_code');
        $current = $this->catalog->currentPositions($tenantId, $vehicleCategoryId)->pluck('position_code')->unique()->values()->all();

        $diff = [
            'unchanged' => array_values(array_intersect($generated, $current)),
            'added' => array_values(array_diff($generated, $current)),
            'removed' => array_values(array_diff($current, $generated)),
        ];

        return [
            'config' => $config,
            'active' => $active,
            'identical' => $identical,
            'diff' => $diff,
            'blockers' => $this->activeInstallationsOutside($tenantId, $vehicleCategoryId, $generated, $diff['removed']),
        ];
    }

    /**
     * Tires actively installed on a vehicle of this category at a position the new configuration
     * does not define (a REMOVED position, or a legacy position that was never configured).
     * Soft-deleted vehicles are included: their installations are still active records.
     *
     * @param  list<string>  $generated
     * @param  list<string>  $removed
     * @return list<array<string, mixed>>
     */
    private function activeInstallationsOutside(string $tenantId, string $vehicleCategoryId, array $generated, array $removed): array
    {
        return DB::table('tire_installations as ti')
            ->join('vehicles as v', 'v.id', '=', 'ti.vehicle_id')
            ->join('tires as t', 't.id', '=', 'ti.tire_id')
            ->where('ti.tenant_id', $tenantId)
            ->where('v.tenant_id', $tenantId)
            ->where('v.vehicle_category_id', $vehicleCategoryId)
            ->whereNull('ti.removed_at')
            ->whereNotIn('ti.wheel_position', $generated)
            ->orderBy('v.registration_number')->orderBy('ti.wheel_position')
            ->get(['ti.wheel_position', 'ti.tire_id', 't.serial_number', 'ti.vehicle_id', 'v.registration_number'])
            ->map(fn ($row) => [
                'position_code' => $row->wheel_position,
                'reason' => in_array($row->wheel_position, $removed, true) ? 'REMOVED' : 'NOT_IN_CONFIGURATION',
                'tire_id' => $row->tire_id,
                'tire_serial_number' => $row->serial_number,
                'vehicle_id' => $row->vehicle_id,
                'vehicle_registration_number' => $row->registration_number,
            ])->all();
    }

    /** Apply Position Changes: upsert UNCHANGED/ADDED (reactivating retired codes), retire REMOVED. */
    private function applyPositions(string $tenantId, string $vehicleCategoryId, array $positions, array $diff, WheelConfigurationVersion $version, $now): void
    {
        $existing = WheelConfiguration::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('vehicle_category_id', $vehicleCategoryId)
            ->lockForUpdate()->get()->keyBy('position_code');

        foreach ($positions as $p) {
            $attributes = [
                'label' => $p['label'],
                'axle_number' => $p['axle_number'],
                'sequence' => $p['sequence'],
                'position_group' => $p['group'],
                'axle_in_group' => $p['axle_in_group'],
                'side' => $p['side'],
                'wheel_index' => $p['wheel_index'],
                'status' => WheelConfiguration::STATUS_ACTIVE,
                'retired_at' => null,
                'retired_in_version_id' => null,
            ];
            $isAdded = in_array($p['position_code'], $diff['added'], true);
            $row = $existing->get($p['position_code']);

            if ($row) {
                // UNCHANGED keeps its row and origin; an ADDED code that existed before (retired, or
                // only as a platform default) is reactivated in this version.
                $row->fill($attributes + ($isAdded || ! $row->introduced_in_version_id ? ['introduced_in_version_id' => $version->id] : []))->save();
            } else {
                WheelConfiguration::query()->create($attributes + [
                    'tenant_id' => $tenantId,
                    'vehicle_category_id' => $vehicleCategoryId,
                    'position_code' => $p['position_code'],
                    'introduced_in_version_id' => $version->id,
                ]);
            }
        }

        // Platform default rows in REMOVED are shared and left untouched; they simply stop applying
        // to this tenant now that it has an active version.
        foreach ($diff['removed'] as $code) {
            $row = $existing->get($code);
            if ($row && $row->status === WheelConfiguration::STATUS_ACTIVE) {
                $row->update(['status' => WheelConfiguration::STATUS_RETIRED, 'retired_at' => $now, 'retired_in_version_id' => $version->id]);
            }
        }
    }

    private function present(array $plan): array
    {
        return [
            'configuration' => $plan['config'],
            'active_version' => $plan['active'],
            'changed' => ! ($plan['identical'] && $plan['active']),
            'diff' => $plan['diff'],
            'blockers' => $plan['blockers'],
            'can_save' => $plan['blockers'] === [] || ($plan['identical'] && $plan['active'] !== null),
        ];
    }
}
