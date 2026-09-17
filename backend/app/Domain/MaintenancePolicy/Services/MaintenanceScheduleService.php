<?php

namespace App\Domain\MaintenancePolicy\Services;

use App\Domain\MaintenancePolicy\Models\MaintenanceInterval;
use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenancePolicy\Models\VehicleMaintenanceProfile;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Section 16: generates/refreshes the single (vehicle, package) schedule
 * row from the package's interval rules. Each interval rule can carry
 * several trigger dimensions ("every 10,000km OR every 6 months") — when a
 * package has multiple interval rules, the soonest candidate per dimension
 * wins across all of them, keeping a single schedule row per vehicle+
 * package (matches the DB unique constraint) rather than accumulating
 * duplicate rows on every regeneration.
 */
class MaintenanceScheduleService
{
    public function __construct(private readonly MaintenanceDueService $due) {}

    public function generateForProfile(VehicleMaintenanceProfile $profile): MaintenanceSchedule
    {
        $vehicle = $profile->vehicle ?? Vehicle::query()->findOrFail($profile->vehicle_id);
        $package = MaintenancePackage::query()->with('intervals')->findOrFail($profile->maintenance_package_id);

        return $this->refresh($vehicle, $package, $profile);
    }

    public function refresh(Vehicle $vehicle, MaintenancePackage $package, ?VehicleMaintenanceProfile $profile = null): MaintenanceSchedule
    {
        return DB::transaction(function () use ($vehicle, $package, $profile) {
            $existing = MaintenanceSchedule::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('maintenance_package_id', $package->id)
                ->lockForUpdate()
                ->first();

            $baselineOdometer = (float) ($existing?->last_completed_odometer ?? $vehicle->current_odometer);
            $baselineDate = $existing?->last_completed_at
                ? Carbon::parse($existing->last_completed_at)
                : Carbon::parse($profile?->effective_from ?? $existing?->created_at ?? now());

            $bestOdometer = null;
            $bestEngineHour = null;
            $bestDate = null;
            $toleranceOdometer = 0;
            $toleranceDays = 0;

            foreach ($package->intervals as $interval) {
                if ($interval->odometer_km) {
                    $candidate = $baselineOdometer + $interval->odometer_km;
                    if ($bestOdometer === null || $candidate < $bestOdometer) {
                        $bestOdometer = $candidate;
                        $toleranceOdometer = max($toleranceOdometer, $interval->tolerance_km);
                    }
                }
                if ($interval->engine_hours) {
                    $candidate = (float) ($vehicle->engine_hour ?? 0) + $interval->engine_hours;
                    $bestEngineHour = $bestEngineHour === null ? $candidate : min($bestEngineHour, $candidate);
                }
                if ($interval->calendar_days) {
                    $candidate = $baselineDate->copy()->addDays($interval->calendar_days);
                    if ($bestDate === null || $candidate->lt($bestDate)) {
                        $bestDate = $candidate;
                        $toleranceDays = max($toleranceDays, $interval->tolerance_days);
                    }
                }
                if ($interval->months) {
                    $candidate = $baselineDate->copy()->addMonthsNoOverflow($interval->months);
                    if ($bestDate === null || $candidate->lt($bestDate)) {
                        $bestDate = $candidate;
                        $toleranceDays = max($toleranceDays, $interval->tolerance_days);
                    }
                }
            }

            $status = $this->due->evaluateSchedule(
                (float) $vehicle->current_odometer,
                $vehicle->engine_hour !== null ? (float) $vehicle->engine_hour : null,
                Carbon::today(),
                $bestOdometer,
                $bestEngineHour,
                $bestDate,
                (float) $toleranceOdometer,
                $toleranceDays,
            );

            $attributes = [
                'tenant_id' => $vehicle->tenant_id,
                'vehicle_id' => $vehicle->id,
                'maintenance_package_id' => $package->id,
                'vehicle_maintenance_profile_id' => $profile?->id ?? $existing?->vehicle_maintenance_profile_id,
                'next_due_date' => $bestDate?->toDateString(),
                'next_due_odometer' => $bestOdometer,
                'next_due_engine_hour' => $bestEngineHour,
                'tolerance_days' => $toleranceDays,
                'tolerance_odometer' => $toleranceOdometer,
                'source_policy' => $package->code,
                'status' => $status,
            ];

            if ($existing) {
                return tap($existing)->update($attributes);
            }

            // Section 10-11: captured once, only at first creation — later
            // package edits (items, thresholds) must never alter a schedule
            // already generated from an earlier package composition.
            $attributes['package_snapshot'] = $package->toSnapshot();

            try {
                return MaintenanceSchedule::query()->create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                // Lost the race against a concurrent first-time generation for this
                // (vehicle, package) pair — the unique constraint is the real guard;
                // fall back to updating the row the winner just created instead of
                // surfacing a raw 500 for the loser.
                $winner = MaintenanceSchedule::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->where('maintenance_package_id', $package->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                return tap($winner)->update($attributes);
            }
        });
    }

    /**
     * Re-evaluates status for every open schedule of a vehicle against its
     * current odometer/engine-hour/date — called after any odometer update
     * (inspection submission, WO progress) so schedules don't go stale
     * until the next full regeneration.
     */
    public function reevaluateForVehicle(Vehicle $vehicle): void
    {
        MaintenanceSchedule::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', '!=', 'COMPLETED')
            ->each(function (MaintenanceSchedule $schedule) use ($vehicle) {
                $status = $this->due->evaluateSchedule(
                    (float) $vehicle->current_odometer,
                    $vehicle->engine_hour !== null ? (float) $vehicle->engine_hour : null,
                    Carbon::today(),
                    $schedule->next_due_odometer !== null ? (float) $schedule->next_due_odometer : null,
                    $schedule->next_due_engine_hour !== null ? (float) $schedule->next_due_engine_hour : null,
                    $schedule->next_due_date,
                    (float) $schedule->tolerance_odometer,
                    $schedule->tolerance_days,
                );
                if ($status !== $schedule->status) {
                    $schedule->update(['status' => $status]);
                }
            });
    }

    public function markCompleted(MaintenanceSchedule $schedule, float $odometer, ?string $workOrderId = null): MaintenanceSchedule
    {
        $schedule->update([
            'status' => 'COMPLETED',
            'last_completed_at' => now(),
            'last_completed_odometer' => $odometer,
            'last_completed_work_order_id' => $workOrderId,
        ]);

        $package = MaintenancePackage::query()->with('intervals')->find($schedule->maintenance_package_id);
        $vehicle = Vehicle::query()->find($schedule->vehicle_id);
        if ($package && $vehicle) {
            $this->refresh($vehicle, $package);
        }

        return $schedule->fresh();
    }
}
