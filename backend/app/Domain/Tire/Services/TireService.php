<?php

namespace App\Domain\Tire\Services;

use App\Domain\Partner\Models\Partner;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\TireRemoval;
use App\Domain\Tire\Models\TireRepair;
use App\Domain\Tire\Models\TireRetread;
use App\Domain\Tire\Models\TireRotation;
use App\Domain\Tire\Models\TireSale;
use App\Domain\Tire\Models\TireScoringResult;
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
                'REPAIR' => 'REPAIR',
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
        if (! in_array($disposition, ['REUSE', 'RETREAD', 'REPAIR', 'SCRAP'], true)) {
            throw new TireException('Disposition must be one of: REUSE, RETREAD, REPAIR, SCRAP.');
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

    /**
     * Phase E (G-30): the receiving partner must be an ACTIVE partner of a
     * type actually capable of tire service work — not any arbitrary
     * partner record. EXTERNAL_WORKSHOP/TIRE_SUPPLIER are the two existing
     * partner_type values that plausibly perform this work; no new type is
     * invented without a documented business-classification source.
     */
    private const ELIGIBLE_SERVICE_PARTNER_TYPES = ['EXTERNAL_WORKSHOP', 'TIRE_SUPPLIER'];

    private function assertEligiblePartner(string $tenantId, string $partnerId): Partner
    {
        $partner = Partner::query()->where('tenant_id', $tenantId)->find($partnerId);
        if (! $partner) {
            throw new TireException('Partner not found for this tenant.');
        }
        if ($partner->status !== 'ACTIVE') {
            throw new TireException('This partner is not ACTIVE and cannot receive tires for service.');
        }
        if (! in_array($partner->partner_type, self::ELIGIBLE_SERVICE_PARTNER_TYPES, true)) {
            throw new TireException('This partner type is not eligible for tire retread/repair work (must be EXTERNAL_WORKSHOP or TIRE_SUPPLIER).');
        }

        return $partner;
    }

    /**
     * G-29: cycle-number assignment is race-free because the tire row is
     * locked for the whole transaction before the max(cycle_number)+1
     * read — a concurrent send for the same tire blocks on that lock
     * rather than racing to compute the same next number. (A FOR UPDATE
     * lock cannot be placed directly on an aggregate MAX query in
     * Postgres, so the tire row is the lock target, not the cycle table.)
     */
    public function retread(Tire $tire, string $partnerId, ?float $cost, ?string $notes, ?string $userId): TireRetread
    {
        return DB::transaction(function () use ($tire, $partnerId, $cost, $notes, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if ($locked->current_status !== 'RETREAD') {
                throw new TireException('Tire must be in RETREAD status (removed with a retread disposition) before sending it for retreading.');
            }
            $this->assertEligiblePartner($locked->tenant_id, $partnerId);
            if (TireRetread::query()->where('tire_id', $locked->id)->whereNotIn('status', ['APPROVED', 'REJECTED'])->exists()) {
                throw new TireException('An open retread cycle already exists for this tire — receive and approve it before sending again.');
            }

            $cycle = (int) TireRetread::query()->where('tire_id', $locked->id)->max('cycle_number') + 1;

            return TireRetread::query()->create([
                'tenant_id' => $locked->tenant_id,
                'tire_id' => $locked->id,
                'cycle_number' => $cycle,
                'sent_at' => now()->toDateString(),
                'sent_by' => $userId,
                'partner_id' => $partnerId,
                'cost' => $cost,
                'notes' => $notes,
                'status' => 'SENT',
            ]);
        });
    }

    public function repair(Tire $tire, string $partnerId, ?float $cost, ?string $notes, ?string $userId): TireRepair
    {
        return DB::transaction(function () use ($tire, $partnerId, $cost, $notes, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if ($locked->current_status !== 'REPAIR') {
                throw new TireException('Tire must be in REPAIR status (removed with a repair disposition) before sending it for repair.');
            }
            $this->assertEligiblePartner($locked->tenant_id, $partnerId);
            if (TireRepair::query()->where('tire_id', $locked->id)->whereNotIn('status', ['APPROVED', 'REJECTED'])->exists()) {
                throw new TireException('An open repair cycle already exists for this tire — receive and approve it before sending again.');
            }

            $cycle = (int) TireRepair::query()->where('tire_id', $locked->id)->max('cycle_number') + 1;

            return TireRepair::query()->create([
                'tenant_id' => $locked->tenant_id,
                'tire_id' => $locked->id,
                'cycle_number' => $cycle,
                'sent_at' => now()->toDateString(),
                'sent_by' => $userId,
                'partner_id' => $partnerId,
                'cost' => $cost,
                'notes' => $notes,
                'status' => 'SENT',
            ]);
        });
    }

    /**
     * G-36: recording receipt never, by itself, returns a tire to
     * available stock. The tire moves to UNDER_INSPECTION — the same
     * status already used elsewhere for "not currently sellable/
     * installable, pending a human decision" — and only a subsequent
     * final inspection + approval (see approveCycle()) can move it to
     * IN_STOCK, SCRAPPED, or QUARANTINED.
     */
    public function receiveRetread(TireRetread $retread, ?string $userId): TireRetread
    {
        return $this->receiveCycle($retread, $userId);
    }

    public function receiveRepair(TireRepair $repair, ?string $userId): TireRepair
    {
        return $this->receiveCycle($repair, $userId);
    }

    private function receiveCycle(TireRetread|TireRepair $cycle, ?string $userId): TireRetread|TireRepair
    {
        return DB::transaction(function () use ($cycle, $userId) {
            $locked = $cycle::query()->lockForUpdate()->findOrFail($cycle->id);
            if ($locked->status !== 'SENT') {
                throw new TireException("Cannot receive a cycle that is {$locked->status} (must be SENT).");
            }

            $locked->update(['received_at' => now()->toDateString(), 'received_by' => $userId, 'status' => 'RECEIVED']);

            $tire = Tire::query()->lockForUpdate()->findOrFail($locked->tire_id);
            $tire->update(['current_status' => 'UNDER_INSPECTION']);

            return $locked->fresh();
        });
    }

    /**
     * G-36: the critical safety evaluation. Also writes a normal
     * TireInspection row so the tire's unified inspection history stays
     * complete — a real reading tied to this cycle, never fabricated.
     */
    public function finalInspectRetread(TireRetread $retread, string $result, ?string $notes, ?string $userId): TireRetread
    {
        return $this->finalInspectCycle($retread, $result, $notes, $userId);
    }

    public function finalInspectRepair(TireRepair $repair, string $result, ?string $notes, ?string $userId): TireRepair
    {
        return $this->finalInspectCycle($repair, $result, $notes, $userId);
    }

    private function finalInspectCycle(TireRetread|TireRepair $cycle, string $result, ?string $notes, ?string $userId): TireRetread|TireRepair
    {
        if (! in_array($result, ['SAFE', 'UNSAFE'], true)) {
            throw new TireException('Final inspection result must be SAFE or UNSAFE.');
        }

        return DB::transaction(function () use ($cycle, $result, $notes, $userId) {
            $locked = $cycle::query()->lockForUpdate()->findOrFail($cycle->id);
            if ($locked->status !== 'RECEIVED') {
                throw new TireException("Cannot record a final inspection for a cycle that is {$locked->status} (must be RECEIVED).");
            }

            $locked->update([
                'status' => 'FINAL_INSPECTED',
                'final_inspected_by' => $userId,
                'final_inspected_at' => now(),
                'final_inspection_result' => $result,
                'final_inspection_notes' => $notes,
            ]);

            TireInspection::query()->create([
                'tenant_id' => $locked->tenant_id,
                'tire_id' => $locked->tire_id,
                'condition' => $result,
                'recommendation' => $notes,
                'inspected_by' => $userId,
                'inspected_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * G-32/G-33/G-36: final approval. The approving actor must not be the
     * same actor who received the tire back (maker-checker); an UNSAFE
     * final inspection can never be approved back into service (critical
     * safety gate, overrides everything else); and a reason is mandatory
     * and persisted with the decision (never optional, never blank).
     */
    public function approveRetread(TireRetread $retread, string $disposition, string $reason, ?string $userId): TireRetread
    {
        return $this->approveCycle($retread, $disposition, $reason, $userId);
    }

    public function approveRepair(TireRepair $repair, string $disposition, string $reason, ?string $userId): TireRepair
    {
        return $this->approveCycle($repair, $disposition, $reason, $userId);
    }

    private function approveCycle(TireRetread|TireRepair $cycle, string $disposition, string $reason, ?string $userId): TireRetread|TireRepair
    {
        if (! in_array($disposition, ['RETURN_TO_SERVICE', 'SCRAP', 'QUARANTINE'], true)) {
            throw new TireException('Approval disposition must be RETURN_TO_SERVICE, SCRAP, or QUARANTINE.');
        }
        if (trim($reason) === '') {
            throw new TireException('An approval reason is required and is persisted with the decision.');
        }

        return DB::transaction(function () use ($cycle, $disposition, $reason, $userId) {
            $locked = $cycle::query()->lockForUpdate()->findOrFail($cycle->id);
            if ($locked->status !== 'FINAL_INSPECTED') {
                throw new TireException("Cannot approve a cycle that is {$locked->status} (must be FINAL_INSPECTED).");
            }
            if ($userId !== null && $userId === $locked->received_by) {
                throw new TireException('The actor who received this tire back cannot also approve its final disposition.');
            }
            if ($locked->final_inspection_result === 'UNSAFE' && $disposition === 'RETURN_TO_SERVICE') {
                throw new TireException('A tire with an UNSAFE final inspection result cannot be approved for RETURN_TO_SERVICE — choose SCRAP or QUARANTINE.');
            }
            // Phase F: a structured scoring result marking this cycle critical-safety-fail
            // overrides RETURN_TO_SERVICE the same way an UNSAFE final inspection does —
            // the critical-fail gate is absolute regardless of which check surfaced it.
            $scoringLinkColumn = $cycle instanceof TireRetread ? 'tire_retread_id' : 'tire_repair_id';
            $hasCriticalFailScore = TireScoringResult::query()->where($scoringLinkColumn, $locked->id)->where('critical_safety_fail', true)->exists();
            if ($hasCriticalFailScore && $disposition === 'RETURN_TO_SERVICE') {
                throw new TireException('A tire with a critical safety failure on its scoring result cannot be approved for RETURN_TO_SERVICE — choose SCRAP or QUARANTINE.');
            }

            $locked->update([
                'status' => 'APPROVED',
                'approved_by' => $userId,
                'approved_at' => now(),
                'approval_disposition' => $disposition,
                'approval_reason' => $reason,
            ]);

            $tire = Tire::query()->lockForUpdate()->findOrFail($locked->tire_id);
            $tire->update(['current_status' => match ($disposition) {
                'RETURN_TO_SERVICE' => 'IN_STOCK',
                'SCRAP' => 'SCRAPPED',
                'QUARANTINE' => 'QUARANTINED',
            }]);

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

    /**
     * Phase F (BD-5/BD-6): "Sell" is never one generic action — the three
     * sell types are safety-distinct. SELL_FOR_OPERATIONAL_REUSE is the
     * one that can put a tire back into service elsewhere, so it is the
     * one this method gates hard: it requires a scoring result to exist
     * at all (an unscored tire's fitness for reuse is simply unknown),
     * and that result must be neither critical-safety-fail nor ineligible
     * for operational reuse. SELL_AS_RETREADABLE_CASING and
     * SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL carry no such requirement —
     * neither claims the tire is fit to keep running as-is.
     */
    public function sell(Tire $tire, string $sellType, string $reason, ?string $userId): TireSale
    {
        if (! in_array($sellType, ['SELL_FOR_OPERATIONAL_REUSE', 'SELL_AS_RETREADABLE_CASING', 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL'], true)) {
            throw new TireException('sellType must be SELL_FOR_OPERATIONAL_REUSE, SELL_AS_RETREADABLE_CASING, or SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL.');
        }
        if (trim($reason) === '') {
            throw new TireException('A sale reason is required.');
        }

        return DB::transaction(function () use ($tire, $sellType, $reason, $userId) {
            $locked = Tire::query()->lockForUpdate()->findOrFail($tire->id);
            if (in_array($locked->current_status, ['INSTALLED', 'IN_USE'], true)) {
                throw new TireException('Remove the tire from its vehicle before selling it.');
            }
            if ($locked->current_status === 'SOLD') {
                throw new TireException('This tire has already been sold.');
            }

            $scoringResult = null;
            if ($sellType === 'SELL_FOR_OPERATIONAL_REUSE') {
                $scoringResult = TireScoringResult::query()->where('tire_id', $locked->id)->orderByDesc('computed_at')->first();
                if (! $scoringResult) {
                    throw new TireException('SELL_FOR_OPERATIONAL_REUSE requires a scoring result — this tire has never been scored.');
                }
                if ($scoringResult->critical_safety_fail) {
                    throw new TireException('This tire has a critical safety failure on its most recent scoring result and cannot be sold for operational reuse.');
                }
                if (! $scoringResult->eligible_for_operational_reuse) {
                    throw new TireException("This tire's most recent scoring result ({$scoringResult->classification}) is not eligible for operational reuse.");
                }
            }

            $sale = TireSale::query()->create([
                'tenant_id' => $locked->tenant_id,
                'tire_id' => $locked->id,
                'sell_type' => $sellType,
                'tire_scoring_result_id' => $scoringResult?->id,
                'reason' => $reason,
                'sold_by' => $userId,
                'sold_at' => now(),
            ]);

            $locked->update(['current_status' => 'SOLD', 'current_vehicle_id' => null, 'current_position' => null, 'current_warehouse_id' => null]);

            return $sale->load('tire');
        });
    }
}
