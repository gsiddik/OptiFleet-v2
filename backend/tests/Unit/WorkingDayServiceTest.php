<?php

namespace Tests\Unit;

use App\Domain\MaintenancePolicy\Services\WorkingDayService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WorkingDayServiceTest extends TestCase
{
    private WorkingDayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkingDayService;
    }

    public function test_calendar_days_lands_on_a_working_day_already(): void
    {
        // 2026-09-17 is a Thursday; +5 days = 2026-09-22 (Tuesday).
        $start = CarbonImmutable::parse('2026-09-17');
        $result = $this->service->addCalendarDays($start, 5, 5);
        $this->assertSame('2026-09-22', $result->toDateString());
    }

    public function test_calendar_days_saturday_shifts_to_monday_for_five_working_days(): void
    {
        // 2026-09-17 (Thu) + 2 days = 2026-09-19 (Saturday) -> next Monday 2026-09-21.
        $start = CarbonImmutable::parse('2026-09-17');
        $result = $this->service->addCalendarDays($start, 2, 5);
        $this->assertSame('2026-09-21', $result->toDateString());
        $this->assertSame(1, $result->dayOfWeekIso);
    }

    public function test_calendar_days_sunday_shifts_to_monday_for_five_working_days(): void
    {
        // 2026-09-17 (Thu) + 3 days = 2026-09-20 (Sunday) -> next Monday 2026-09-21.
        $start = CarbonImmutable::parse('2026-09-17');
        $result = $this->service->addCalendarDays($start, 3, 5);
        $this->assertSame('2026-09-21', $result->toDateString());
    }

    public function test_calendar_days_sunday_shifts_to_monday_for_six_working_days_but_saturday_does_not(): void
    {
        $start = CarbonImmutable::parse('2026-09-17');

        // Saturday (2026-09-19) is a working day when working_days = 6.
        $saturdayResult = $this->service->addCalendarDays($start, 2, 6);
        $this->assertSame('2026-09-19', $saturdayResult->toDateString());

        // Sunday (2026-09-20) still shifts to Monday.
        $sundayResult = $this->service->addCalendarDays($start, 3, 6);
        $this->assertSame('2026-09-21', $sundayResult->toDateString());
    }

    public function test_calendar_days_no_adjustment_for_seven_working_days(): void
    {
        $start = CarbonImmutable::parse('2026-09-17');

        $saturdayResult = $this->service->addCalendarDays($start, 2, 7);
        $this->assertSame('2026-09-19', $saturdayResult->toDateString());

        $sundayResult = $this->service->addCalendarDays($start, 3, 7);
        $this->assertSame('2026-09-20', $sundayResult->toDateString());
    }

    public function test_calendar_months_handles_end_of_january_deterministically(): void
    {
        // 31 Jan + 1 month -> Carbon's addMonthsNoOverflow clamps to the last
        // day of February rather than overflowing into March.
        $start = CarbonImmutable::parse('2026-01-31');
        $result = $this->service->addCalendarMonths($start, 1, 7);
        $this->assertSame('2026-02-28', $result->toDateString());
    }

    public function test_calendar_months_leap_year_end_of_february(): void
    {
        // 2028 is a leap year: 31 Jan 2028 + 1 month -> 29 Feb 2028.
        $start = CarbonImmutable::parse('2028-01-31');
        $result = $this->service->addCalendarMonths($start, 1, 7);
        $this->assertSame('2028-02-29', $result->toDateString());
    }

    public function test_calendar_months_non_leap_year_end_of_february(): void
    {
        $start = CarbonImmutable::parse('2027-01-31');
        $result = $this->service->addCalendarMonths($start, 1, 7);
        $this->assertSame('2027-02-28', $result->toDateString());
    }

    public function test_calendar_months_applies_weekend_adjustment_after_adding_months(): void
    {
        // 2026-06-17 (Wed) + 3 months = 2026-09-17 (Thursday) -- pick a start
        // that lands on a weekend after adding months to exercise the shift.
        $start = CarbonImmutable::parse('2026-07-18'); // Saturday
        $result = $this->service->addCalendarMonths($start, 2, 5);
        // 2026-07-18 + 2 months = 2026-09-18 (Friday), a working day already.
        $this->assertSame('2026-09-18', $result->toDateString());

        $start2 = CarbonImmutable::parse('2026-06-19'); // Friday
        $result2 = $this->service->addCalendarMonths($start2, 1, 5);
        // 2026-06-19 + 1 month = 2026-07-19 (Sunday) -> next Monday 2026-07-20.
        $this->assertSame('2026-07-20', $result2->toDateString());
    }
}
