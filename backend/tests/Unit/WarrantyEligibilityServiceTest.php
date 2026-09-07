<?php

namespace Tests\Unit;

use App\Domain\Warranty\Services\WarrantyEligibilityService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class WarrantyEligibilityServiceTest extends TestCase
{
    private function evaluate(WarrantyEligibilityService $service, array $overrides, Carbon $currentDate, ?float $currentOdometer = null, ?float $currentEngineHour = null): string
    {
        $defaults = [
            'coverage_basis' => 'DATE',
            'duration_months' => 12,
            'duration_km' => null,
            'duration_engine_hours' => null,
            'tolerance_days' => 0,
            'tolerance_km' => 0,
            'tolerance_engine_hours' => 0,
            'starts_at' => Carbon::parse('2025-01-01'),
            'start_odometer' => 0.0,
            'start_engine_hour' => 0.0,
        ];
        $args = array_merge($defaults, $overrides);

        return $service->evaluate(
            $args['coverage_basis'],
            $args['duration_months'],
            $args['duration_km'],
            $args['duration_engine_hours'],
            $args['tolerance_days'],
            $args['tolerance_km'],
            $args['tolerance_engine_hours'],
            $args['starts_at'],
            $args['start_odometer'],
            $args['start_engine_hour'],
            $currentDate,
            $currentOdometer,
            $currentEngineHour,
        );
    }

    public function test_date_based_warranty_active_within_period(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['duration_months' => 12], Carbon::parse('2025-06-01'));

        $this->assertSame('ACTIVE', $result);
    }

    public function test_date_based_warranty_expired_after_period(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['duration_months' => 12], Carbon::parse('2026-02-01'));

        $this->assertSame('EXPIRED', $result);
    }

    public function test_date_based_warranty_boundary_exact_expiry_is_still_active(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['duration_months' => 12], Carbon::parse('2026-01-01'));

        $this->assertSame('ACTIVE', $result);
    }

    public function test_date_based_warranty_one_day_past_boundary_is_expired(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['duration_months' => 12], Carbon::parse('2026-01-02'));

        $this->assertSame('EXPIRED', $result);
    }

    public function test_date_based_warranty_tolerance_grace_period(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['duration_months' => 12, 'tolerance_days' => 10], Carbon::parse('2026-01-05'));

        $this->assertSame('ACTIVE', $result);
    }

    public function test_mileage_based_warranty_active_within_limit(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['coverage_basis' => 'MILEAGE', 'duration_months' => null, 'duration_km' => 20000], Carbon::now(), 15000);

        $this->assertSame('ACTIVE', $result);
    }

    public function test_mileage_based_warranty_expired_beyond_limit(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['coverage_basis' => 'MILEAGE', 'duration_months' => null, 'duration_km' => 20000], Carbon::now(), 20001);

        $this->assertSame('EXPIRED', $result);
    }

    public function test_engine_hour_based_warranty(): void
    {
        $service = new WarrantyEligibilityService();
        $overrides = ['coverage_basis' => 'ENGINE_HOUR', 'duration_months' => null, 'duration_engine_hours' => 2000];

        $this->assertSame('ACTIVE', $this->evaluate($service, $overrides, Carbon::now(), null, 1999));
        $this->assertSame('EXPIRED', $this->evaluate($service, $overrides, Carbon::now(), null, 2001));
    }

    public function test_combination_warranty_expires_when_any_single_dimension_is_exceeded(): void
    {
        // The brief's own worked example: 12 months OR 20,000 km; current
        // state is 8 months / 22,000 km -> EXPIRED via the mileage leg alone.
        $service = new WarrantyEligibilityService();
        $overrides = ['coverage_basis' => 'COMBINATION', 'duration_months' => 12, 'duration_km' => 20000];

        $result = $this->evaluate($service, $overrides, Carbon::parse('2025-01-01')->addMonths(8), 22000);

        $this->assertSame('EXPIRED', $result);
    }

    public function test_combination_warranty_active_when_neither_dimension_exceeded(): void
    {
        $service = new WarrantyEligibilityService();
        $overrides = ['coverage_basis' => 'COMBINATION', 'duration_months' => 12, 'duration_km' => 20000];

        $result = $this->evaluate($service, $overrides, Carbon::parse('2025-01-01')->addMonths(8), 15000);

        $this->assertSame('ACTIVE', $result);
    }

    public function test_no_configured_dimension_defaults_to_active(): void
    {
        $service = new WarrantyEligibilityService();

        $result = $this->evaluate($service, ['coverage_basis' => 'MILEAGE', 'duration_months' => null, 'duration_km' => null], Carbon::now());

        $this->assertSame('ACTIVE', $result);
    }
}
