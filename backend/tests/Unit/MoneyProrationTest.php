<?php

namespace Tests\Unit;

use App\Domain\Pricing\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyProrationTest extends TestCase
{
    public function test_prorate_half_period(): void
    {
        $this->assertSame('1500000.00', Money::prorate('3000000', 15, 30));
    }

    public function test_prorate_full_period_returns_full_amount(): void
    {
        $this->assertSame('3000000.00', Money::prorate('3000000', 30, 30));
    }

    public function test_prorate_rounds_half_up_deterministically(): void
    {
        // 1000000 / 3 * 1 = 333333.33333... -> rounds to 333333.33
        $this->assertSame('333333.33', Money::prorate('1000000', 1, 3));
    }

    public function test_add_and_subtract_are_exact_with_no_floating_point_drift(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('0.10', Money::subtract('0.30', '0.20'));
    }

    public function test_percentage_of_calculates_tax(): void
    {
        $this->assertSame('110000.00', Money::percentageOf('1000000', '11'));
    }
}
