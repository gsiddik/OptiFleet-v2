<?php

namespace Tests\Unit;

use App\Domain\MaintenancePolicy\Services\MaintenanceDueService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class MaintenanceDueServiceTest extends TestCase
{
    private MaintenanceDueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MaintenanceDueService;
    }

    public function test_odometer_based_due_soon(): void
    {
        // current 49,800 / due 50,000 / tolerance 500 -> DUE_SOON (spec example).
        $status = $this->service->evaluateSchedule(49800, null, null, 50000, null, null, 500, 0);
        $this->assertSame('DUE_SOON', $status);
    }

    public function test_odometer_based_upcoming_outside_tolerance(): void
    {
        $status = $this->service->evaluateSchedule(40000, null, null, 50000, null, null, 500, 0);
        $this->assertSame('UPCOMING', $status);
    }

    public function test_odometer_based_overdue(): void
    {
        $status = $this->service->evaluateSchedule(50500, null, null, 50000, null, null, 500, 0);
        $this->assertSame('OVERDUE', $status);
    }

    public function test_odometer_exact_boundary_is_due(): void
    {
        $status = $this->service->evaluateSchedule(50000, null, null, 50000, null, null, 500, 0);
        $this->assertSame('DUE', $status);
    }

    public function test_engine_hour_based_due_soon(): void
    {
        $status = $this->service->evaluateSchedule(null, 980, null, null, 1000, null, 50, 0);
        $this->assertSame('DUE_SOON', $status);
    }

    public function test_engine_hour_overdue(): void
    {
        $status = $this->service->evaluateSchedule(null, 1050, null, null, 1000, null, 50, 0);
        $this->assertSame('OVERDUE', $status);
    }

    public function test_calendar_date_upcoming(): void
    {
        $today = Carbon::parse('2026-01-01');
        $due = Carbon::parse('2026-06-01');
        $status = $this->service->evaluateSchedule(null, null, $today, null, null, $due, 0, 14);
        $this->assertSame('UPCOMING', $status);
    }

    public function test_calendar_date_due_soon_within_tolerance(): void
    {
        $today = Carbon::parse('2026-01-01');
        $due = Carbon::parse('2026-01-10');
        $status = $this->service->evaluateSchedule(null, null, $today, null, null, $due, 0, 14);
        $this->assertSame('DUE_SOON', $status);
    }

    public function test_calendar_date_overdue(): void
    {
        $today = Carbon::parse('2026-06-15');
        $due = Carbon::parse('2026-06-01');
        $status = $this->service->evaluateSchedule(null, null, $today, null, null, $due, 0, 14);
        $this->assertSame('OVERDUE', $status);
    }

    public function test_calendar_date_exact_boundary_is_due(): void
    {
        $today = Carbon::parse('2026-06-01');
        $due = Carbon::parse('2026-06-01');
        $status = $this->service->evaluateSchedule(null, null, $today, null, null, $due, 0, 14);
        $this->assertSame('DUE', $status);
    }

    public function test_combination_whichever_comes_first_odometer_wins(): void
    {
        // Odometer is overdue, calendar date is still far out -> overall must be OVERDUE.
        $today = Carbon::parse('2026-01-01');
        $due = Carbon::parse('2026-12-01');
        $status = $this->service->evaluateSchedule(51000, null, $today, 50000, null, $due, 500, 14);
        $this->assertSame('OVERDUE', $status);
    }

    public function test_combination_whichever_comes_first_calendar_wins(): void
    {
        // Odometer far from due, but calendar date already overdue -> overall OVERDUE.
        $today = Carbon::parse('2026-12-15');
        $due = Carbon::parse('2026-12-01');
        $status = $this->service->evaluateSchedule(10000, null, $today, 50000, null, $due, 500, 14);
        $this->assertSame('OVERDUE', $status);
    }

    public function test_combination_takes_most_urgent_of_all_dimensions(): void
    {
        // Odometer UPCOMING, engine-hour DUE_SOON, calendar OVERDUE -> OVERDUE wins.
        $today = Carbon::parse('2026-06-15');
        $due = Carbon::parse('2026-06-01');
        $status = $this->service->evaluateSchedule(10000, 980, $today, 50000, 1000, $due, 500, 14);
        $this->assertSame('OVERDUE', $status);
    }

    public function test_no_configured_dimensions_defaults_to_upcoming(): void
    {
        $status = $this->service->evaluateSchedule(10000, null, null, null, null, null, 0, 0);
        $this->assertSame('UPCOMING', $status);
    }

    public function test_missing_current_value_for_a_dimension_ignores_that_dimension(): void
    {
        // next_due_odometer set but current odometer null -> that dimension can't be evaluated.
        $status = $this->service->evaluateSchedule(null, null, null, 50000, null, null, 500, 0);
        $this->assertSame('UPCOMING', $status);
    }
}
