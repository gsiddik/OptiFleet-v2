<?php

namespace Tests\Unit;

use App\Domain\Shared\Support\DisplayFormat;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Money = exactly two decimals (half-up, grouped); quantities drop meaningless decimals. Printed
 * documents format numbers and dates for their locale (i18n D3); English numbers are unchanged.
 */
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

    public function test_numbers_follow_the_document_locale(): void
    {
        $this->assertSame('1,234.56', DisplayFormat::money('1234.56'));
        $this->assertSame('1,234.56', DisplayFormat::money('1234.56', 'en'));
        $this->assertSame('1.234,56', DisplayFormat::money('1234.56', 'id'));
        $this->assertSame('-1.234.567,50', DisplayFormat::money('-1234567.5', 'id'));
        $this->assertSame('0,00', DisplayFormat::money('0', 'id'));
        $this->assertSame('1,234.5', DisplayFormat::quantity('1234.5000'));
        $this->assertSame('1.234,5', DisplayFormat::quantity('1234.5000', 'id'));
        $this->assertSame('5', DisplayFormat::quantity('5.0000', 'id'));
        $this->assertNull(DisplayFormat::money(null, 'id'));
    }

    public function test_dates_follow_the_document_locale(): void
    {
        $date = CarbonImmutable::parse('2026-10-06 14:05:09');
        $this->assertSame('October 6, 2026', DisplayFormat::date($date, 'en'));
        $this->assertSame('6 Oktober 2026', DisplayFormat::date($date, 'id'));
        $this->assertSame('6 Oktober 2026', DisplayFormat::date('2026-10-06', 'id'));
        $this->assertSame('October 6, 2026 14:05', DisplayFormat::dateTime($date, 'en'));
        $this->assertSame('6 Oktober 2026 14:05', DisplayFormat::dateTime($date, 'id'));
        $this->assertNull(DisplayFormat::date(null, 'id'));
        $this->assertSame('2026-10-06 14:05:09', $date->toDateTimeString(), 'The value itself is never changed.');
    }
}
