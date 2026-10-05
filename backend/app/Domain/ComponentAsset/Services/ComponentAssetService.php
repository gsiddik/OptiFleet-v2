<?php

namespace App\Domain\ComponentAsset\Services;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\ComponentAsset\Models\ComponentRemoval;
use App\Domain\ComponentAsset\Models\ComponentRepair;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\SparePartSale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Section 34-38: serialized vehicle components (battery, alternator, ECU,
 * turbo, ...) built on the existing Component Group / Product relationship
 * rather than a hardcoded component-type list. Section 37's "never
 * installed on two vehicles at once" is enforced by the partial unique
 * index on component_installations (component_asset_id where removed_at is
 * null) — this service only turns its violation into a friendly exception.
 */
class ComponentAssetService
{
    public function install(ComponentAsset $asset, Vehicle $vehicle, ?string $positionLocation, ?float $odometer, ?string $workOrderId, ?string $userId): ComponentInstallation
    {
        if (in_array($asset->current_status, ['INSTALLED', 'ACTIVE'], true)) {
            throw new ComponentAssetException('This component asset is already installed on a vehicle.');
        }
        if (in_array($asset->current_status, ['SCRAPPED', ...ComponentAsset::NO_LOCATION], true)) {
            throw new ComponentAssetException("A {$asset->current_status} component asset cannot be installed.");
        }
        if (SparePartSale::query()->withoutGlobalScopes()->where('component_asset_id', $asset->id)->whereIn('status', SparePartSale::ACTIVE_STATUSES)->exists()) {
            throw new ComponentAssetException('This component asset is in an open sale (Sell Sparepart) and cannot be installed.');
        }

        return DB::transaction(function () use ($asset, $vehicle, $positionLocation, $odometer, $workOrderId, $userId) {
            try {
                $installation = ComponentInstallation::query()->create([
                    'tenant_id' => $asset->tenant_id,
                    'component_asset_id' => $asset->id,
                    'vehicle_id' => $vehicle->id,
                    'position_location' => $positionLocation,
                    'installation_odometer' => $odometer,
                    'installed_at' => now(),
                    'work_order_id' => $workOrderId,
                    'performed_by' => $userId,
                ]);
            } catch (QueryException $e) {
                throw new ComponentAssetException('This component asset is already actively installed on a vehicle.');
            }

            $asset->update(['current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id, 'current_warehouse_id' => null]);

            return $installation;
        });
    }

    /** $warehouseId: where the removed component is stored (its location after removal; optional). */
    public function remove(ComponentAsset $asset, string $reason, string $disposition, ?float $odometer, ?string $condition, ?string $diagnosisNote, ?string $workOrderId, ?string $userId, ?string $warehouseId = null): ComponentRemoval
    {
        return DB::transaction(function () use ($asset, $reason, $disposition, $odometer, $condition, $diagnosisNote, $workOrderId, $userId, $warehouseId) {
            $locked = ComponentAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if (! in_array($locked->current_status, ['INSTALLED', 'ACTIVE', 'FAILED'], true)) {
                throw new ComponentAssetException("Component is {$locked->current_status} and cannot be removed from a vehicle.");
            }

            $active = ComponentInstallation::query()->where('component_asset_id', $locked->id)->whereNull('removed_at')->lockForUpdate()->first();
            if (! $active) {
                throw new ComponentAssetException('No active installation found for this component asset.');
            }
            $active->update(['removed_at' => now()]);

            $removal = ComponentRemoval::query()->create([
                'tenant_id' => $locked->tenant_id,
                'component_asset_id' => $locked->id,
                'component_installation_id' => $active->id,
                'removal_odometer' => $odometer,
                'removal_reason' => $reason,
                'condition' => $condition,
                'disposition' => $disposition,
                'diagnosis_note' => $diagnosisNote,
                'work_order_id' => $workOrderId,
                'removed_by' => $userId,
                'removed_at' => now(),
            ]);

            $newStatus = match ($disposition) {
                'REPAIR' => 'UNDER_REPAIR',
                'SCRAP' => 'SCRAPPED',
                default => 'REMOVED',
            };

            $locked->update(['current_status' => $newStatus, 'current_vehicle_id' => null, 'current_warehouse_id' => $warehouseId]);

            return $removal->fresh('componentAsset');
        });
    }

    public function replace(ComponentAsset $oldAsset, ComponentAsset $newAsset, string $reason, ?string $diagnosisNote, ?float $odometer, ?string $workOrderId, ?string $userId): array
    {
        return DB::transaction(function () use ($oldAsset, $newAsset, $reason, $diagnosisNote, $odometer, $workOrderId, $userId) {
            $locked = ComponentAsset::query()->lockForUpdate()->findOrFail($oldAsset->id);
            $vehicleId = $locked->current_vehicle_id;
            if (! $vehicleId) {
                throw new ComponentAssetException('This component asset is not currently installed on a vehicle.');
            }
            $activeInstallation = ComponentInstallation::query()->where('component_asset_id', $locked->id)->whereNull('removed_at')->first();
            $position = $activeInstallation?->position_location;
            $vehicle = Vehicle::query()->findOrFail($vehicleId);

            $removal = $this->remove($locked, $reason, 'REUSE', $odometer, null, $diagnosisNote, $workOrderId, $userId);
            $removal->update(['replaced_by_asset_id' => $newAsset->id]);

            $installation = $this->install($newAsset->fresh(), $vehicle, $position, $odometer, $workOrderId, $userId);

            return ['removal' => $removal->fresh(), 'installation' => $installation];
        });
    }

    public function startRepair(ComponentAsset $asset, string $description, ?string $workOrderId, ?string $userId): ComponentRepair
    {
        if ($asset->current_status !== 'UNDER_REPAIR') {
            throw new ComponentAssetException('Component must be removed with a repair disposition before starting a repair.');
        }

        return ComponentRepair::query()->create([
            'tenant_id' => $asset->tenant_id,
            'component_asset_id' => $asset->id,
            'description' => $description,
            'work_order_id' => $workOrderId,
            'performed_by' => $userId,
            'started_at' => now(),
        ]);
    }

    /** Back in stock needs a warehouse: the asset's own, or $warehouseId when it has none. */
    public function completeRepair(ComponentRepair $repair, string $outcome, ?float $cost, ?string $warehouseId = null): ComponentRepair
    {
        return DB::transaction(function () use ($repair, $outcome, $cost, $warehouseId) {
            $locked = ComponentRepair::query()->lockForUpdate()->findOrFail($repair->id);
            $locked->update(['completed_at' => now(), 'outcome' => $outcome, 'cost' => $cost]);

            $asset = ComponentAsset::query()->lockForUpdate()->findOrFail($locked->component_asset_id);
            $newStatus = match ($outcome) {
                'RECONDITIONED' => 'RECONDITIONED',
                'SCRAPPED' => 'SCRAPPED',
                default => 'IN_STOCK',
            };
            $warehouse = $warehouseId ?? $asset->current_warehouse_id;
            if ($newStatus === 'IN_STOCK' && $warehouse === null) {
                throw new ComponentAssetException('Choose the warehouse the repaired component is stored in.');
            }
            $asset->update(['current_status' => $newStatus, 'current_warehouse_id' => $warehouse]);

            return $locked->fresh();
        });
    }
}
