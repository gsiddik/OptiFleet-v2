<?php

namespace Tests\Unit;

use App\Domain\WorkOrder\Services\WalNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalNumberGeneratorTest extends TestCase
{
    public function test_format_uses_roman_month_date_and_padded_sequence(): void
    {
        $tenant = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $generator = app(WalNumberGenerator::class);

        $number = DB::transaction(fn () => $generator->generate($tenant->id, new \DateTimeImmutable('2026-09-19')));

        $this->assertSame('WAL/IX/20260919-001', $number);
    }

    public function test_sequence_increments_within_the_same_month(): void
    {
        $tenant = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $generator = app(WalNumberGenerator::class);

        $first = DB::transaction(fn () => $generator->generate($tenant->id, new \DateTimeImmutable('2026-09-01')));
        $second = DB::transaction(fn () => $generator->generate($tenant->id, new \DateTimeImmutable('2026-09-30')));

        $this->assertSame('WAL/IX/20260901-001', $first);
        $this->assertSame('WAL/IX/20260930-002', $second);
    }

    public function test_sequence_resets_across_calendar_months(): void
    {
        $tenant = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $generator = app(WalNumberGenerator::class);

        $september = DB::transaction(fn () => $generator->generate($tenant->id, new \DateTimeImmutable('2026-09-30')));
        $october = DB::transaction(fn () => $generator->generate($tenant->id, new \DateTimeImmutable('2026-10-01')));

        $this->assertSame('WAL/IX/20260930-001', $september);
        $this->assertSame('WAL/X/20261001-001', $october);
    }

    public function test_sequence_is_isolated_per_tenant(): void
    {
        $tenantA = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $tenantB = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $generator = app(WalNumberGenerator::class);

        $forA = DB::transaction(fn () => $generator->generate($tenantA->id, new \DateTimeImmutable('2026-09-19')));
        $forB = DB::transaction(fn () => $generator->generate($tenantB->id, new \DateTimeImmutable('2026-09-19')));

        $this->assertSame('WAL/IX/20260919-001', $forA);
        $this->assertSame('WAL/IX/20260919-001', $forB);
    }
}
