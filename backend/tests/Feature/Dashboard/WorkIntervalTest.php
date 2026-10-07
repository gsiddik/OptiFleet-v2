<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\WorkTime\WorkTimeQuery;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderWorkInterval;
use App\Domain\WorkOrder\Services\WorkOrderException;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\Workshop\Models\WorkOrderMechanicAssignment;
use App\Domain\Workshop\Services\MechanicAssignmentService;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Work intervals (owner rule): start → QC is work time; hold / waiting for parts / QC are not; each
 * rework adds a cycle. Mechanic hours = overlap of the intervals with each mechanic's assignments,
 * priced at the assignment's rate snapshot.
 */
class WorkIntervalTest extends TestCase
{
    use DashboardTestHelpers;

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'WIT-'.Str::random(4)]);
        $this->grantModules($tenant, ['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        [$user] = $this->makeTenantUser($tenant, []);
        app(TenantContext::class)->setTenantId($tenant->id);

        return [$tenant, $branch, $workshop, $vehicle, $user];
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'UTC'));
    }

    private function startedWorkOrder($vehicle, $workshop, User $user): WorkOrder
    {
        $wo = app(WorkOrderService::class);
        $order = $wo->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id);
        $order = $wo->schedule($wo->assign($wo->approve($wo->submit($order))));

        return $wo->start($this->withApprovedWorkspace($order));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_intervals_exclude_pauses_and_qc_and_count_rework_cycles(): void
    {
        [$tenant, , $workshop, $vehicle, $user] = $this->scenario();
        $service = app(WorkOrderService::class);

        $this->at('2026-09-01 08:00:00');
        $order = $this->startedWorkOrder($vehicle, $workshop, $user);
        $this->at('2026-09-01 10:00:00');
        $order = $service->waitForPart($order);          // 2h work
        $this->at('2026-09-02 08:00:00');
        $order = $service->resume($order);
        $this->at('2026-09-02 09:30:00');
        $order = $service->submitToQc($order);           // +1.5h
        $this->at('2026-09-02 15:00:00');
        $order = $service->rework($order);               // waiting + doing QC: not work
        $this->at('2026-09-03 08:00:00');
        $order = $service->resume($order);               // rework starts (REWORK → IN_PROGRESS)
        $this->at('2026-09-03 09:00:00');
        $order = $service->submitToQc($order);           // +1h, cycle 2

        $intervals = WorkOrderWorkInterval::query()->where('work_order_id', $order->id)->orderBy('started_at')->get();
        $this->assertSame([1, 1, 2], $intervals->pluck('cycle')->all());
        $this->assertSame(['WAITING_PART', 'QC_PENDING', 'QC_PENDING'], $intervals->pluck('end_to_status')->all());
        $this->assertSame(['SCHEDULED', 'WAITING_PART', 'REWORK'], $intervals->pluck('start_from_status')->all());
        $this->assertSame(16200, $intervals->sum(fn ($i) => $i->ended_at->getTimestamp() - $i->started_at->getTimestamp()));
        $this->assertTrue($intervals->first()->started_at->equalTo($order->fresh()->started_at), 'first interval starts with the Work Order');

        $context = app(DashboardService::class)->context($user, $tenant->id);
        $this->assertSame([$order->id => true], WorkTimeQuery::historyComplete($context, [$order->id]));
    }

    public function test_a_failed_transition_leaves_no_interval_and_only_one_interval_can_be_open(): void
    {
        [, , $workshop, $vehicle, $user] = $this->scenario();
        $this->at('2026-09-01 08:00:00');
        $order = $this->startedWorkOrder($vehicle, $workshop, $user);

        try {
            app(WorkOrderService::class)->complete($order); // IN_PROGRESS → COMPLETED is not in the workflow
            $this->fail('transition should be refused');
        } catch (WorkOrderException) {
        }
        $this->assertSame(1, WorkOrderWorkInterval::query()->where('work_order_id', $order->id)->count());
        $this->assertNull(WorkOrderWorkInterval::query()->where('work_order_id', $order->id)->value('ended_at'));

        // Repeating "start" is refused by the status guard; a second open interval is impossible.
        try {
            app(WorkOrderService::class)->start($order->fresh());
            $this->fail('second start should be refused');
        } catch (WorkOrderException) {
        }
        $this->expectException(QueryException::class);
        DB::transaction(fn () => WorkOrderWorkInterval::query()->create([
            'tenant_id' => $order->tenant_id, 'work_order_id' => $order->id, 'cycle' => 1,
            'started_at' => now(), 'start_from_status' => 'SCHEDULED',
        ]));
    }

    public function test_mechanic_attribution_overlap_change_of_mechanic_rates_and_missing_rate(): void
    {
        [$tenant, $branch, $workshop, $vehicle, $user] = $this->scenario();
        $a = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'M-A', 'hourly_rate' => '50000.00']);
        $b = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'M-B', 'hourly_rate' => '40000.00']);
        $c = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'M-C', 'hourly_rate' => null]);
        $assign = app(MechanicAssignmentService::class);
        $service = app(WorkOrderService::class);

        $this->at('2026-09-01 08:00:00');
        $order = $this->startedWorkOrder($vehicle, $workshop, $user);
        $primaryA = $assign->assign($order, $a, 'PRIMARY', null, $user->id);          // A from 08:00
        $assign->assign($order, $b, 'ASSISTANT', null, $user->id);                    // B from 08:00
        $this->at('2026-09-01 09:00:00');
        $a->update(['hourly_rate' => '60000.00']);                                     // rate change: snapshot rules
        $assign->assign($order, $a, 'ASSISTANT', null, $user->id);                    // A again (overlap) at 60000
        $this->at('2026-09-01 10:00:00');
        $assign->unassign($primaryA);
        $assign->assign($order, $c, 'PRIMARY', null, $user->id);                       // C joins without a rate
        $this->at('2026-09-01 11:00:00');
        $order = $service->submitToQc($order);
        $this->at('2026-09-01 12:00:00');                                              // after QC: no work time

        $context = app(DashboardService::class)->context($user, $tenant->id);
        $rows = collect(WorkTimeQuery::attribute($context, WorkTimeQuery::intervals($context, DB::table('work_orders')->where('id', $order->id)->select('id'))))
            ->keyBy('worker_id');

        // A: 08–09 at 50000 (first assignment), 09–11 at 60000 (latest active assignment) — counted once.
        $this->assertSame(3 * 3600, $rows[$a->id]['seconds']);
        $this->assertSame('170000.00', $rows[$a->id]['cost']);
        $this->assertSame(3 * 3600, $rows[$b->id]['seconds']);
        $this->assertSame('120000.00', $rows[$b->id]['cost']);
        $this->assertSame(3600, $rows[$c->id]['seconds']);
        $this->assertNull($rows[$c->id]['cost'], 'no rate snapshot → cost unknown, never 0');
        $this->assertSame('3.00', WorkTimeQuery::hours($rows[$a->id]['seconds']));
        $this->assertSame(3, WorkOrderMechanicAssignment::query()->where('work_order_id', $order->id)->whereNull('unassigned_at')->count());
    }

    public function test_open_interval_counts_until_now_and_history_before_the_feature_is_incomplete(): void
    {
        [$tenant, $branch, $workshop, $vehicle, $user] = $this->scenario();
        $mechanic = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'M-1', 'hourly_rate' => '36000.00']);

        $this->at('2026-09-01 08:00:00');
        $order = $this->startedWorkOrder($vehicle, $workshop, $user);
        app(MechanicAssignmentService::class)->assign($order, $mechanic, 'PRIMARY', null, $user->id);
        $legacy = $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'IN_PROGRESS', 'started_at' => '2026-08-01 08:00:00']);

        $this->at('2026-09-01 08:30:00');
        $context = app(DashboardService::class)->context($user, $tenant->id);
        $this->assertTrue(CarbonImmutable::now()->equalTo($context->now));
        $rows = WorkTimeQuery::attribute($context, WorkTimeQuery::intervals($context, DB::table('work_orders')->where('id', $order->id)->select('id')));
        $this->assertSame(1800, $rows[0]['seconds']);
        $this->assertTrue($rows[0]['open']);
        $this->assertSame('18000.00', $rows[0]['cost']);

        $this->assertEquals([$order->id => true, $legacy->id => false], WorkTimeQuery::historyComplete($context, [$order->id, $legacy->id]));
    }
}
