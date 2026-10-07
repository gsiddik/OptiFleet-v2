<?php

namespace Tests\Feature\Dashboard;

use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WS-07 Mechanic Performance (baseline per maintenance type, ≥ 5 valid samples, ≤ baseline meets)
 * and WS-08 Rework / first-pass rate (complete histories only).
 */
class DashboardMechanicPerformanceTest extends TestCase
{
    use DashboardTestHelpers;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $tenant = $this->makeTenant(['code' => 'MPF-'.Str::random(4)]);
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'WORKSHOP']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        $w = fn (string $code) => $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => $code, 'name' => $code, 'hourly_rate' => '40000.00']);
        [$m1, $m2, $m3, $m4] = [$w('MECH-1'), $w('MECH-2'), $w('MECH-3'), $w('MECH-4')];
        DB::table('mechanic_performance_baselines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'maintenance_type' => 'CORRECTIVE', 'baseline_hours' => '2.00', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (array $over) => $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, $over);

        // M1: 5 completed CORRECTIVE WOs, 2 h each → average exactly the baseline → MEETS.
        for ($i = 0; $i < 5; $i++) {
            $this->completed($make, $m1->id, 'CORRECTIVE', 2);
        }
        // ...plus one running WO (open interval): handled, not completed.
        $running = $make(['status' => 'IN_PROGRESS', 'maintenance_type' => 'CORRECTIVE', 'started_at' => '2026-09-20 10:00:00']);
        $this->interval($running, '2026-09-20 10:00:00', null, 1);
        $this->assign($running, $m1->id, '2026-09-20 10:00:00');
        // M2: 4 valid + 1 with incomplete history → INSUFFICIENT_SAMPLE (4 < 5).
        for ($i = 0; $i < 4; $i++) {
            $this->completed($make, $m2->id, 'CORRECTIVE', 1);
        }
        $legacy = $this->completed($make, $m2->id, 'CORRECTIVE', 1);
        $legacy->forceFill(['started_at' => '2026-08-01 00:00:00'])->save();
        // M4: 5 WOs of 3 h → ABOVE (ratio 1.50); one of them had a rework cycle.
        for ($i = 0; $i < 5; $i++) {
            $this->completed($make, $m4->id, 'CORRECTIVE', 3, $i === 0);
        }
        // M3: PREVENTIVE, no baseline.
        $this->completed($make, $m3->id, 'PREVENTIVE', 1);

        return compact('tenant', 'branch', 'workshop', 'm1', 'm2', 'm3', 'm4');
    }

    public function test_mechanic_performance_against_baseline_with_minimum_samples(): void
    {
        $s = $this->scenario();
        [, $token] = $this->makeTenantUser($s['tenant'], ['work_order.view', 'worker.view']);

        $data = $this->widget($token, 'WS-07', ['months' => 3])->assertOk()->json('data.data');
        $this->assertSame('CORRECTIVE', $data['maintenance_type'], 'defaults to the type with the most valid samples');
        $this->assertSame('2.00', $data['baseline_hours']);
        $this->assertFalse($data['can_manage_baseline']);
        $rows = collect($data['mechanics'])->keyBy('worker_name');
        $this->assertSame(['wo_handled' => 6, 'wo_completed' => 5, 'valid_samples' => 5, 'avg_hours' => '2.00', 'status' => 'MEETS'],
            array_intersect_key($rows['MECH-1'], array_flip(['wo_handled', 'wo_completed', 'valid_samples', 'avg_hours', 'status'])));
        $this->assertSame(['wo_completed' => 5, 'valid_samples' => 4, 'avg_hours' => '1.00', 'status' => 'INSUFFICIENT_SAMPLE'],
            array_intersect_key($rows['MECH-2'], array_flip(['wo_completed', 'valid_samples', 'avg_hours', 'status'])));
        $this->assertSame(['avg_hours' => '3.00', 'diff_hours' => '1.00', 'ratio' => '1.50', 'status' => 'ABOVE'],
            array_intersect_key($rows['MECH-4'], array_flip(['avg_hours', 'diff_hours', 'ratio', 'status'])));
        $this->assertArrayNotHasKey('MECH-3', $rows->all());

        $preventive = $this->widget($token, 'WS-07', ['months' => 3, 'maintenance_type' => 'PREVENTIVE'])->assertOk()->json('data.data');
        $this->assertNull($preventive['baseline_hours']);
        $this->assertSame('NO_BASELINE', $preventive['mechanics'][0]['status']);

        $detail = $this->details($token, 'WS-07', ['months' => 3, 'maintenance_type' => 'CORRECTIVE', 'worker_id' => $s['m2']->id])->assertOk()->json('data');
        $this->assertSame(5, $detail['meta']['total']);
        $this->assertSame(1, collect($detail['data'])->where('history_complete', false)->count());

        // Permission: worker data needs worker.view.
        [, $noWorker] = $this->makeTenantUser($s['tenant'], ['work_order.view']);
        $this->widget($noWorker, 'WS-07')->assertStatus(403);
        [, $manager] = $this->makeTenantUser($s['tenant'], ['work_order.view', 'worker.view', 'mechanic_baseline.manage']);
        $this->assertTrue($this->widget($manager, 'WS-07')->json('data.data.can_manage_baseline'));
    }

    public function test_rework_rate_counts_complete_histories_only(): void
    {
        $s = $this->scenario();
        [, $token] = $this->makeTenantUser($s['tenant'], ['work_order.view']);

        $env = $this->widget($token, 'WS-08', ['months' => 3])->assertOk()->json('data');
        // 5 + 4 + 5 + 1 valid completed; one with incomplete history excluded.
        $this->assertSame(['completed' => 15, 'first_pass' => 14, 'with_rework' => 1, 'rework_cycles' => 1, 'first_pass_rate' => 93.3], $env['data']['totals']);
        $this->assertSame('dashboard.limitations.reworkHistoryIncomplete', $env['limitations'][0]['code']);
        $this->assertSame(1, $env['limitations'][0]['params']['n']);
        $rework = $this->details($token, 'WS-08', ['months' => 3, 'rework_only' => 1])->assertOk()->json('data.data');
        $this->assertCount(1, $rework);
        $this->assertSame(1, $rework[0]['rework_cycles']);
    }

    // ------------------------------------------------------------------ fixtures

    /** A WO completed in September with `hours` of work by one mechanic (two intervals if rework). */
    private function completed(callable $make, string $workerId, string $type, int $hours, bool $rework = false): WorkOrder
    {
        $day = sprintf('2026-09-%02d', 1 + ($this->seq++ % 18));
        $wo = $make(['status' => 'COMPLETED', 'maintenance_type' => $type, 'started_at' => "{$day} 01:00:00", 'completed_at' => "{$day} 12:00:00"]);
        $this->assign($wo, $workerId, "{$day} 00:00:00");
        if ($rework) {
            $this->interval($wo, "{$day} 01:00:00", sprintf('%s %02d:00:00', $day, 1 + $hours - 1), 1);
            $this->interval($wo, "{$day} 08:00:00", "{$day} 09:00:00", 2);
        } else {
            $this->interval($wo, "{$day} 01:00:00", sprintf('%s %02d:00:00', $day, 1 + $hours), 1);
        }

        return $wo;
    }

    private function interval(WorkOrder $wo, string $start, ?string $end, int $cycle): void
    {
        DB::table('work_order_work_intervals')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $wo->tenant_id, 'work_order_id' => $wo->id,
            'cycle' => $cycle, 'started_at' => $start, 'ended_at' => $end, 'start_from_status' => $cycle > 1 ? 'REWORK' : 'SCHEDULED',
            'end_to_status' => $end ? 'QC_PENDING' : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assign(WorkOrder $wo, string $workerId, string $from): void
    {
        DB::table('work_order_mechanic_assignments')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $wo->id, 'worker_id' => $workerId,
            'role' => 'PRIMARY', 'hourly_rate_snapshot' => '40000.0000', 'assigned_at' => $from, 'created_at' => now(), 'updated_at' => now()]);
    }
}
