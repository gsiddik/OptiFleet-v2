<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\TireRemoval;
use App\Domain\Tire\Models\TireRetread;
use App\Domain\Tire\Models\TireRotation;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Section 26-33: a tire is a serialized lifecycle asset, not a fungible
 * stock quantity. Section 29 requires the DB — not just app code — to
 * reject a tire installed on two vehicles or two tires active on the same
 * wheel position at once; the two partial unique indexes on
 * tire_installations (see migration) are the actual guard, this service
 * only turns their violation into a friendly TireException.
 */
class TireService
{
    public function install(Tire $tire, Vehicle $vehicle, string $wheelPosition, ?float $odometer, ?string $workOrderId, ?string $userId): TireInstallation
    {
        if (! in_array($tire->current_status, ['IN_STOCK', 'RESERVED'], true)) {
            throw new TireException("Tire is {$tire->current_status} and cannot be installed.");
        }

        return DB::transaction(function () use ($tire, $vehicle, $wheelPosition, $odometer, $workOrderId, $userId) {
            try {
                $installation = TireInstallation::query()->create([
                    'tenant_id' => $tire->tenant_id,
                    'tire_id' => $tire->id,
                    'vehicle_id' => $vehicle->id,
                    'wheel_position' => $wheelPosition,
                    'installed_at' => now(),
                    'installation_odometer' => $odometer,
                    'work_order_id' => $workOrderId,
                    'performed_by' => $userId,
                ]);
            } catch (QueryException $e) {
                throw new TireException('This tire is already actively installed, or this wheel position already has an active tire.');
            }

            $tire->update([
                'current_status' => 'INSTALLED',
                'current_vehicle_id' => $vehicle->id,
                'current_position' => $wheelPosition,
                'current_warehouse_id' => null,
            ]);

            return $installation;
        });
    }

    public function rotate(Tire $tire, string $toPosition, ?float $odometer, ?string $workOrderId, ?string $userId): TireRotation
    {
        return DB::transaction(function () use ($tire, $toPosition, $odometer, $workOrderId, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if ($locked->current_status !== 'INSTALLED' || ! $locked->current_vehicle_id) {
                throw new TireException('Only a currently installed tire can be rotated.');
            }

            $active = TireInstallation::query()->where('tire_id', $locked->id)->whereNull('removed_at')->lockForUpdate()->first();
            $fromPosition = $locked->current_position;

            if ($active) {
                $active->update(['removed_at' => now()]);
            }

            try {
                TireInstallation::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'tire_id' => $locked->id,
                    'vehicle_id' => $locked->current_vehicle_id,
                    'wheel_position' => $toPosition,
                    'installed_at' => now(),
                    'installation_odometer' => $odometer,
                    'work_order_id' => $workOrderId,
                    'performed_by' => $userId,
                ]);
            } catch (QueryException $e) {
                throw new TireException('The target wheel position already has an active tire.');
            }

            $rotation = TireRotation::query()->create([
                'tenant_id' => $locked->tenant_id,
                'tire_id' => $locked->id,
                'vehicle_id' => $locked->current_vehicle_id,
                'from_position' => $fromPosition,
                'to_position' => $toPosition,
                'odometer' => $odometer,
                'work_order_id' => $workOrderId,
                'performed_by' => $userId,
                'occurred_at' => now(),
            ]);

            $locked->update(['current_position' => $toPosition]);

            return $rotation;
        });
    }

    public function inspect(Tire $tire, array $attributes, ?string $userId): TireInspection
    {
        return TireInspection::query()->create(array_merge($attributes, [
            'tenant_id' => $tire->tenant_id,
            'tire_id' => $tire->id,
            'inspected_by' => $userId,
            'inspected_at' => now(),
        ]));
    }

    public function remove(Tire $tire, string $reason, string $disposition, ?float $odometer, ?string $condition, ?string $workOrderId, ?string $userId): TireRemoval
    {
        return DB::transaction(function () use ($tire, $reason, $disposition, $odometer, $condition, $workOrderId, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if (! in_array($locked->current_status, ['INSTALLED', 'IN_USE', 'UNDER_INSPECTION'], true)) {
                throw new TireException("Tire is {$locked->current_status} and cannot be removed from a vehicle.");
            }

            $active = TireInstallation::query()->where('tire_id', $locked->id)->whereNull('removed_at')->lockForUpdate()->first();
            if (! $active) {
                throw new TireException('No active installation found for this tire.');
            }
            $active->update(['removed_at' => now()]);

            $removal = TireRemoval::query()->create([
                'tenant_id' => $locked->tenant_id,
                'tire_id' => $locked->id,
                'tire_installation_id' => $active->id,
                'removal_odometer' => $odometer,
                'removal_reason' => $reason,
                'condition' => $condition,
                'disposition' => $disposition,
                'work_order_id' => $workOrderId,
                'removed_by' => $userId,
                'removed_at' => now(),
            ]);

            $newStatus = match ($disposition) {
                'RETREAD' => 'RETREAD',
                'SCRAP' => 'SCRAPPED',
                default => 'REMOVED',
            };

            $locked->update([
                'current_status' => $newStatus,
                'current_vehicle_id' => null,
                'current_position' => null,
            ]);

            return $removal->load('tire');
        });
    }

    /** Section 32: removal of the old tire + installation of the new tire, atomically, on the same wheel position. */
    public function replace(Tire $oldTire, Tire $newTire, string $reason, ?float $odometer, ?string $workOrderId, ?string $userId): array
    {
        return DB::transaction(function () use ($oldTire, $newTire, $reason, $odometer, $workOrderId, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($oldTire->id);
            if (! in_array($locked->current_status, ['INSTALLED', 'IN_USE', 'UNDER_INSPECTION'], true)) {
                throw new TireException("Tire is {$locked->current_status} and cannot be replaced.");
            }

            $vehicleId = $locked->current_vehicle_id;
            $position = $locked->current_position;
            $vehicle = Vehicle::query()->findOrFail($vehicleId);

            $removal = $this->remove($locked, $reason, 'REUSE', $odometer, null, $workOrderId, $userId);
            $removal->update(['replaced_by_tire_id' => $newTire->id]);

            $installation = $this->install($newTire->fresh(), $vehicle, $position, $odometer, $workOrderId, $userId);

            return ['removal' => $removal->fresh(), 'installation' => $installation];
        });
    }

    public function retread(Tire $tire, ?string $partnerId, ?float $cost, ?string $notes): TireRetread
    {
        if ($tire->current_status !== 'RETREAD') {
            throw new TireException('Tire must be in RETREAD status (removed with a retread disposition) before sending it for retreading.');
        }

        $cycle = (int) TireRetread::query()->where('tire_id', $tire->id)->max('cycle_number') + 1;

        return TireRetread::query()->create([
            'tenant_id' => $tire->tenant_id,
            'tire_id' => $tire->id,
            'cycle_number' => $cycle,
            'sent_at' => now()->toDateString(),
            'partner_id' => $partnerId,
            'cost' => $cost,
            'notes' => $notes,
        ]);
    }

    public function receiveRetread(TireRetread $retread): TireRetread
    {
        return DB::transaction(function () use ($retread) {
            $locked = TireRetread::query()->lockForUpdate()->findOrFail($retread->id);
            $locked->update(['received_at' => now()->toDateString()]);

            $tire = Tire::query()->lockForUpdate()->findOrFail($locked->tire_id);
            $tire->update(['current_status' => 'IN_STOCK']);

            return $locked->fresh();
        });
    }

    public function scrap(Tire $tire, ?string $reason): Tire
    {
        if (in_array($tire->current_status, ['INSTALLED', 'IN_USE'], true)) {
            throw new TireException('Remove the tire from its vehicle before scrapping it.');
        }

        $tire->update(['current_status' => 'SCRAPPED', 'current_warehouse_id' => null]);

        return $tire->fresh();
    }
}
