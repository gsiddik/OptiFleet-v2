<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\TireRemoval;
use App\Domain\Tire\Models\TireRetread;
use App\Domain\Tire\Models\TireRotation;
use App\Domain\Tire\Models\WheelConfiguration;
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
    /**
     * G-23: installedAt/installedAtSource/baseline* parameters exist so a tire
     * already mounted before OptiFleet was adopted can be onboarded with its
     * real (or explicitly ESTIMATED/UNKNOWN) history, instead of every tire
     * being stamped "installed right now." A normal, live install simply
     * omits them — $installedAt defaults to now(), $installedAtSource to
     * KNOWN, exactly matching the previous unconditional behavior.
     */
    public function install(
        Tire $tire,
        Vehicle $vehicle,
        string $wheelPosition,
        ?float $odometer,
        ?string $workOrderId,
        ?string $userId,
        ?\DateTimeInterface $installedAt = null,
        string $installedAtSource = 'KNOWN',
        ?float $baselineTreadDepthMm = null,
        ?string $baselineCondition = null,
    ): TireInstallation {
        if (! in_array($tire->current_status, ['IN_STOCK', 'RESERVED'], true)) {
            throw new TireException("Tire is {$tire->current_status} and cannot be installed.");
        }
        if (! in_array($installedAtSource, ['KNOWN', 'ESTIMATED', 'UNKNOWN'], true)) {
            throw new TireException('installedAtSource must be KNOWN, ESTIMATED, or UNKNOWN.');
        }

        $installedAt ??= now();
        if ($installedAt->format('Y-m-d H:i:s') > now()->format('Y-m-d H:i:s')) {
            throw new TireException('Installation date cannot be in the future.');
        }
        if ($tire->purchase_date && $installedAt->format('Y-m-d') < $tire->purchase_date->format('Y-m-d')) {
            throw new TireException('Installation date cannot predate this tire\'s recorded purchase date.');
        }

        $this->assertValidWheelPosition($tire->tenant_id, $vehicle->vehicle_category_id, $wheelPosition);

        return DB::transaction(function () use ($tire, $vehicle, $wheelPosition, $odometer, $workOrderId, $userId, $installedAt, $installedAtSource, $baselineTreadDepthMm, $baselineCondition) {
            try {
                $installation = TireInstallation::query()->create([
                    'tenant_id' => $tire->tenant_id,
                    'tire_id' => $tire->id,
                    'vehicle_id' => $vehicle->id,
                    'wheel_position' => $wheelPosition,
                    'installed_at' => $installedAt,
                    'installation_date_source' => $installedAtSource,
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

            // Baseline reading is never fabricated: only recorded when the caller actually supplied one.
            if ($baselineTreadDepthMm !== null || $baselineCondition !== null) {
                TireInspection::query()->create([
                    'tenant_id' => $tire->tenant_id,
                    'tire_id' => $tire->id,
                    'tread_depth_mm' => $baselineTreadDepthMm,
                    'condition' => $baselineCondition,
                    'recommendation' => $installedAtSource === 'KNOWN' ? null : 'Baseline reading captured at onboarding; installation date is '.$installedAtSource.'.',
                    'inspected_by' => $userId,
                    'inspected_at' => $installedAt,
                ]);
            }

            return $installation;
        });
    }

    /**
     * G-25: wheel_position/to_position were free strings, never checked against
     * the vehicle's own wheel_configurations. Validation only activates once a
     * vehicle's category actually has configured positions — a category with
     * zero rows configured keeps the previous permissive behavior rather than
     * fail-closed for every tenant that hasn't set up Wheel Configuration yet
     * (no platform-seeded default layout exists; see IMPROVEMENT_CONTEXT.md).
     */
    private function assertValidWheelPosition(string $tenantId, ?string $vehicleCategoryId, string $position): void
    {
        if (! $vehicleCategoryId) {
            return;
        }

        $configured = WheelConfiguration::query()
            ->where('vehicle_category_id', $vehicleCategoryId)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));

        if (! $configured->exists()) {
            return;
        }

        $valid = (clone $configured)->where('position_code', $position)->exists();
        if (! $valid) {
            throw new TireException("'{$position}' is not a configured wheel position for this vehicle's category.");
        }
    }

    public function rotate(Tire $tire, string $toPosition, ?float $odometer, ?string $workOrderId, ?string $userId): TireRotation
    {
        return DB::transaction(function () use ($tire, $toPosition, $odometer, $workOrderId, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if ($locked->current_status !== 'INSTALLED' || ! $locked->current_vehicle_id) {
                throw new TireException('Only a currently installed tire can be rotated.');
            }

            $vehicle = Vehicle::query()->findOrFail($locked->current_vehicle_id);
            $this->assertValidWheelPosition($locked->tenant_id, $vehicle->vehicle_category_id, $toPosition);

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

    /**
     * G-24: rotate() can only move a tire into an EMPTY position — the
     * partial unique index on (vehicle_id, wheel_position) WHERE
     * removed_at IS NULL rejects moving into an occupied one. This
     * atomically exchanges two already-installed tires' positions on the
     * same vehicle: both active installations are closed first, then both
     * replacements are created — at the moment either insert runs, the
     * position it targets has already been vacated by the other tire's
     * closure, so neither can collide with the other or with the index.
     */
    public function swapPositions(Tire $tireA, Tire $tireB, ?float $odometer, ?string $workOrderId, ?string $userId): array
    {
        if ($tireA->id === $tireB->id) {
            throw new TireException('Cannot swap a tire with itself.');
        }

        return DB::transaction(function () use ($tireA, $tireB, $odometer, $workOrderId, $userId) {
            // Lock both tire rows in a fixed order so two concurrent swaps can never deadlock on each other.
            $orderedIds = [$tireA->id, $tireB->id];
            sort($orderedIds);
            $locked = Tire::query()->whereIn('id', $orderedIds)->lockForUpdate()->get()->keyBy('id');
            $lockedA = $locked->get($tireA->id);
            $lockedB = $locked->get($tireB->id);

            foreach ([$lockedA, $lockedB] as $t) {
                if (! $t || $t->current_status !== 'INSTALLED' || ! $t->current_vehicle_id) {
                    throw new TireException('Both tires must be currently installed to swap positions.');
                }
            }
            if ($lockedA->current_vehicle_id !== $lockedB->current_vehicle_id) {
                throw new TireException('Both tires must be installed on the same vehicle to swap positions.');
            }

            $activeA = TireInstallation::query()->where('tire_id', $lockedA->id)->whereNull('removed_at')->lockForUpdate()->first();
            $activeB = TireInstallation::query()->where('tire_id', $lockedB->id)->whereNull('removed_at')->lockForUpdate()->first();
            if (! $activeA || ! $activeB) {
                throw new TireException('Both tires must have an active installation to swap positions.');
            }

            $positionA = $activeA->wheel_position;
            $positionB = $activeB->wheel_position;
            if ($positionA === $positionB) {
                throw new TireException('Both tires are already at the same position — nothing to swap.');
            }

            $activeA->update(['removed_at' => now()]);
            $activeB->update(['removed_at' => now()]);

            TireInstallation::query()->create([
                'tenant_id' => $lockedA->tenant_id, 'tire_id' => $lockedA->id, 'vehicle_id' => $lockedA->current_vehicle_id,
                'wheel_position' => $positionB, 'installed_at' => now(), 'installation_odometer' => $odometer,
                'work_order_id' => $workOrderId, 'performed_by' => $userId,
            ]);
            TireInstallation::query()->create([
                'tenant_id' => $lockedB->tenant_id, 'tire_id' => $lockedB->id, 'vehicle_id' => $lockedB->current_vehicle_id,
                'wheel_position' => $positionA, 'installed_at' => now(), 'installation_odometer' => $odometer,
                'work_order_id' => $workOrderId, 'performed_by' => $userId,
            ]);

            $rotationA = TireRotation::query()->create([
                'tenant_id' => $lockedA->tenant_id, 'tire_id' => $lockedA->id, 'vehicle_id' => $lockedA->current_vehicle_id,
                'from_position' => $positionA, 'to_position' => $positionB, 'odometer' => $odometer,
                'work_order_id' => $workOrderId, 'performed_by' => $userId, 'occurred_at' => now(),
            ]);
            $rotationB = TireRotation::query()->create([
                'tenant_id' => $lockedB->tenant_id, 'tire_id' => $lockedB->id, 'vehicle_id' => $lockedB->current_vehicle_id,
                'from_position' => $positionB, 'to_position' => $positionA, 'odometer' => $odometer,
                'work_order_id' => $workOrderId, 'performed_by' => $userId, 'occurred_at' => now(),
            ]);

            $lockedA->update(['current_position' => $positionB]);
            $lockedB->update(['current_position' => $positionA]);

            return ['tire_a' => $rotationA->load('tire'), 'tire_b' => $rotationB->load('tire')];
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

    /**
     * Section 32 / G-28: removal of the old tire + installation of the new
     * tire, atomically, on the same wheel position. $disposition previously
     * hardcoded to REUSE regardless of the old tire's actual condition —
     * it is now caller-supplied (REUSE/RETREAD/SCRAP), matching remove()'s
     * own already-flexible disposition handling.
     */
    public function replace(Tire $oldTire, Tire $newTire, string $reason, string $disposition, ?float $odometer, ?string $workOrderId, ?string $userId): array
    {
        if (! in_array($disposition, ['REUSE', 'RETREAD', 'SCRAP'], true)) {
            throw new TireException('Disposition must be one of: REUSE, RETREAD, SCRAP.');
        }

        return DB::transaction(function () use ($oldTire, $newTire, $reason, $disposition, $odometer, $workOrderId, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($oldTire->id);
            if (! in_array($locked->current_status, ['INSTALLED', 'IN_USE', 'UNDER_INSPECTION'], true)) {
                throw new TireException("Tire is {$locked->current_status} and cannot be replaced.");
            }

            $vehicleId = $locked->current_vehicle_id;
            $position = $locked->current_position;
            $vehicle = Vehicle::query()->findOrFail($vehicleId);

            $removal = $this->remove($locked, $reason, $disposition, $odometer, null, $workOrderId, $userId);
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
