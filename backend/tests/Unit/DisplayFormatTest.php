<?php

namespace Tests\Unit;

use App\Domain\Shared\Support\DisplayFormat;
use PHPUnit\Framework\TestCase;

/** Money = exactly two decimals (half-up, grouped); quantities drop meaningless decimals. */
class DisplayFormatTest extends TestCase
{
    public function test_money_always_has_two_decimals_with_half_up_rounding(): void
    {
        $cases = [
            '100' => '100.00', '100.5' => '100.50', '100.567' => '100.57', '100.005' => '100.01',
            '1234567.8912' => '1,234,567.89', '99.995' => '100.00', '-5.125' => '-5.13',
            '0' => '0.00', '-0.001' => '0.00', '150.0000' => '150.00',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, DisplayFormat::money($input), $input);
        }
        $this->assertNull(DisplayFormat::money(null));
    }

    public function test_quantity_drops_meaningless_decimals(): void
    {
        $cases = ['50.0000' => '50', '12.00' => '12', '3.000' => '3', '2.5000' => '2.5', '1234.0000' => '1,234', '0.1250' => '0.125'];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, DisplayFormat::quantity($input), $input);
        }
        $this->assertNull(DisplayFormat::quantity(null));
    }
}
