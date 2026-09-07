<?php

namespace App\Domain\MaintenancePolicy\Services;

/**
 * Section 17: pure due-status calculation, deliberately side-effect free so
 * it is exhaustively unit-testable without touching the database. A single
 * schedule can carry several due dimensions at once (date, odometer,
 * engine-hour — "whichever comes first" for COMBINATION intervals); the
 * overall status is the most urgent of them.
 *
 * Per-dimension distance = how far away the due point still is, in that
 * dimension's own unit (days, km, hours). distance < 0 means the due point
 * has already passed.
 *   distance <  0            -> OVERDUE
 *   distance == 0             -> DUE
 *   0 < distance <= tolerance -> DUE_SOON
 *   distance >  tolerance     -> UPCOMING
 */
class MaintenanceDueService
{
    private const RANK = ['OVERDUE' => 3, 'DUE' => 2, 'DUE_SOON' => 1, 'UPCOMING' => 0];

    public function evaluateDimension(?float $distance, float $tolerance): ?string
    {
        if ($distance === null) {
            return null;
        }

        return match (true) {
            $distance < 0 => 'OVERDUE',
            $distance == 0.0 => 'DUE',
            $distance <= $tolerance => 'DUE_SOON',
            default => 'UPCOMING',
        };
    }

    /**
     * @param array<int, array{distance: ?float, tolerance: float}> $dimensions
     */
    public function evaluate(array $dimensions): string
    {
        $statuses = array_filter(array_map(
            fn ($d) => $this->evaluateDimension($d['distance'], $d['tolerance']),
            $dimensions
        ));

        if (empty($statuses)) {
            return 'UPCOMING';
        }

        usort($statuses, fn ($a, $b) => self::RANK[$b] <=> self::RANK[$a]);

        return $statuses[0];
    }

    public function evaluateSchedule(
        ?float $currentOdometer,
        ?float $currentEngineHour,
        ?\DateTimeInterface $today,
        ?float $nextDueOdometer,
        ?float $nextDueEngineHour,
        ?\DateTimeInterface $nextDueDate,
        float $toleranceOdometer,
        int $toleranceDays,
    ): string {
        $dimensions = [];

        if ($nextDueOdometer !== null && $currentOdometer !== null) {
            $dimensions[] = ['distance' => $nextDueOdometer - $currentOdometer, 'tolerance' => $toleranceOdometer];
        }
        if ($nextDueEngineHour !== null && $currentEngineHour !== null) {
            $dimensions[] = ['distance' => $nextDueEngineHour - $currentEngineHour, 'tolerance' => $toleranceOdometer];
        }
        if ($nextDueDate !== null && $today !== null) {
            $days = (int) $today->diff($nextDueDate)->format('%r%a');
            $dimensions[] = ['distance' => (float) $days, 'tolerance' => (float) $toleranceDays];
        }

        return $this->evaluate($dimensions);
    }
}
