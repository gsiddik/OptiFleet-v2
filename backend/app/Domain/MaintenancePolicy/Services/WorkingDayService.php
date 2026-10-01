<?php

namespace App\Domain\MaintenancePolicy\Services;

use Carbon\CarbonImmutable;

/**
 * Section 12: pure, side-effect-free working-day calculation for a PERIODIC
 * schedule's next date. The document's literal order is followed exactly —
 * sum the period first, then shift a non-working-day result forward to the
 * next working day — rather than a "N business days" calculation.
 *
 *   workshop_working_days = 5 (Mon-Fri): Saturday or Sunday -> next Monday
 *   workshop_working_days = 6 (Mon-Sat): Sunday -> next Monday
 *   workshop_working_days = 7 (Mon-Sun): no adjustment
 */
class WorkingDayService
{
    public function addCalendarDays(CarbonImmutable $scheduleStartDate, int $schedulePeriod, int $workshopWorkingDays): CarbonImmutable
    {
        return $this->adjust($scheduleStartDate->addDays($schedulePeriod), $workshopWorkingDays);
    }

    public function addCalendarMonths(CarbonImmutable $scheduleStartDate, int $schedulePeriod, int $workshopWorkingDays): CarbonImmutable
    {
        return $this->adjust($scheduleStartDate->addMonthsNoOverflow($schedulePeriod), $workshopWorkingDays);
    }

    /**
     * N working days after $date, counting Monday–Friday only (Saturday and Sunday are skipped).
     * Used for vendor invoice terms of payment ("30" = 30 working days). Public holidays are not
     * counted as non-working days: the platform has no holiday calendar yet.
     *
     *   Friday + 1 -> Monday;  Friday + 5 -> next Friday;  0 -> the same date.
     */
    public function addBusinessDays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        $result = $date->startOfDay();
        for ($remaining = $days; $remaining > 0;) {
            $result = $result->addDay();
            if ($result->dayOfWeekIso <= 5) {
                $remaining--;
            }
        }

        return $result;
    }

    private function adjust(CarbonImmutable $candidate, int $workshopWorkingDays): CarbonImmutable
    {
        $isoDayOfWeek = $candidate->dayOfWeekIso; // 1 = Monday ... 6 = Saturday, 7 = Sunday

        $isNonWorkingDay = match ($workshopWorkingDays) {
            5 => in_array($isoDayOfWeek, [6, 7], true),
            6 => $isoDayOfWeek === 7,
            default => false, // 7: every day is a working day, no adjustment.
        };

        return $isNonWorkingDay ? $candidate->next(CarbonImmutable::MONDAY) : $candidate;
    }
}
