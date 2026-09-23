<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Tenant;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\QualityControl\Services\QualityControlService;
use App\Domain\QualityControl\Services\RoadTestService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\VehicleRelease\Services\VehicleReleaseService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderExecutionService;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\Workshop\Services\LaborTimerService;
use App\Domain\Workshop\Services\MechanicAssignmentService;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Internal Work Order status-coverage scenarios (Section 30-31, FT-WO-*):
 * eight Work Orders, one per canonical status the Scheduler/status-color
 * UI needs to demonstrate, each reached by real `WorkOrderService`/
 * `WorkOrderExecutionService` transitions — never an inserted status
 * string. Every scenario is looked up first by its `[FT-WO-...]` complaint
 * prefix; a rerun refreshes only the relative scheduling dates on the
 * already-reached WO instead of replaying transitions or duplicating rows
 * (Section 40).
 */
class FunctionalTestWorkOrderSeeder
{
    public function run(Tenant $tenant, object $ops, object $products, Carbon $referenceDate): void
    {
        $workshopManagerId = User::query()->where('email', 'ft.workshopmanager@optifleet.test')->value('id');
        $engineGroupId = ComponentGroup::query()->where('code', 'CG-ENGINE')->whereNull('tenant_id')->value('id');
        $brakeGroupId = ComponentGroup::query()->where('code', 'CG-BRAKE')->whereNull('tenant_id')->value('id');

        $workOrders = app(WorkOrderService::class);
        $execution = app(WorkOrderExecutionService::class);
        $mechanics = app(MechanicAssignmentService::class);
        $laborTimer = app(LaborTimerService::class);
        $qc = app(QualityControlService::class);
        $partService = app(WorkOrderPartService::class);

        $leadMechanic = $ops->workers['LEAD_MECHANIC'];
        $qcInspector = $ops->workers['QC'];
        $bay1 = $ops->workspaces['BAY_1'];
        $bay2 = $ops->workspaces['BAY_2'];

        // FT-WO-DRAFT: just created, nothing else — the simplest possible state.
        $this->scenario('FT-WO-DRAFT', $ops->vehicles['CAR_1'], $referenceDate, function () use ($workOrders, $ops) {
            return $workOrders->create($ops->vehicles['CAR_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'LOW',
                'complaint' => '[FT-WO-DRAFT] Routine 10,000km service due.',
            ], null);
        }, null);

        // FT-WO-SCHEDULED: scheduled 1 day from now, in Bay 1.
        $this->scenario('FT-WO-SCHEDULED', $ops->vehicles['CAR_2'], $referenceDate, function () use ($workOrders, $ops, $bay1, $referenceDate) {
            $wo = $workOrders->create($ops->vehicles['CAR_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-WO-SCHEDULED] AC not cooling, needs inspection.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);

            return $workOrders->schedule($wo, $bay1->id, $referenceDate->copy()->addDay()->setTime(9, 0), $referenceDate->copy()->addDay()->setTime(12, 0));
        }, fn (WorkOrder $wo) => $wo->update([
            'target_start_at' => $referenceDate->copy()->addDay()->setTime(9, 0),
            'target_completion_at' => $referenceDate->copy()->addDay()->setTime(12, 0),
        ]));

        // FT-WO-IN_PROGRESS: started today, mid-execution (finding + job + mechanic assigned + labor running).
        $this->scenario('FT-WO-IN_PROGRESS', $ops->vehicles['TRUCK_1'], $referenceDate, function () use ($workOrders, $execution, $mechanics, $laborTimer, $ops, $bay2, $referenceDate, $brakeGroupId, $leadMechanic) {
            $wo = $workOrders->create($ops->vehicles['TRUCK_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
                'complaint' => '[FT-WO-IN_PROGRESS] Brake pedal soft, needs inspection.',
            ], null);

            // Findings/Diagnosis are a Draft-only scoping exercise (see
            // WorkOrderExecutionService::assertFindingScopeEditable) — must be
            // recorded before submit()/approve()/.../start() move the WO past Draft.
            $finding = $execution->addFinding($wo, ['component_group_id' => $brakeGroupId, 'severity' => 'HIGH', 'description' => 'Worn brake pads.'], $leadMechanic->id);
            $execution->addDiagnosis($wo, ['work_order_finding_id' => $finding->id, 'root_cause' => 'Brake pads worn beyond limit.'], $leadMechanic->id);

            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay2->id, $referenceDate->copy()->setTime(8, 0), $referenceDate->copy()->setTime(11, 0));
            $wo = $workOrders->start($wo);

            $job = $execution->addJob($wo, ['component_group_id' => $brakeGroupId, 'service_item' => 'Replace brake pads', 'description' => 'Replace front brake pads.', 'estimated_hours' => 1.5]);
            $mechanics->assign($wo, $leadMechanic, 'PRIMARY', $job->id, null);
            $execution->updateJobStatus($job, 'ASSIGNED');
            $laborTimer->start($job, $leadMechanic->id);

            return $wo->fresh();
        }, null);

        // FT-WO-ON_HOLD: same shape as IN_PROGRESS, then paused (e.g. waiting on customer decision).
        $this->scenario('FT-WO-ON_HOLD', $ops->vehicles['TRUCK_2'], $referenceDate, function () use ($workOrders, $execution, $ops, $bay2, $referenceDate, $engineGroupId) {
            $wo = $workOrders->create($ops->vehicles['TRUCK_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-WO-ON_HOLD] Engine noise on cold start.',
            ], null);

            // Findings are a Draft-only scoping exercise (see
            // WorkOrderExecutionService::assertFindingScopeEditable) — must be
            // recorded before submit()/approve()/.../start() move the WO past Draft.
            $execution->addFinding($wo, ['component_group_id' => $engineGroupId, 'severity' => 'MEDIUM', 'description' => 'Awaiting customer approval for engine mount replacement.'], null);

            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay2->id, $referenceDate->copy()->setTime(8, 0), $referenceDate->copy()->setTime(11, 0));
            $wo = $workOrders->start($wo);

            return $workOrders->hold($wo);
        }, null);

        // FT-WO-WAITING_PART: in progress, blocked on a part with no stock at this warehouse.
        $this->scenario('FT-WO-WAITING_PART', $ops->vehicles['CAR_1'], $referenceDate, function () use ($workOrders, $execution, $ops, $bay1, $referenceDate, $products) {
            $wo = $workOrders->create($ops->vehicles['CAR_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-WO-WAITING_PART] Hydraulic lift arm requires replacement part not in stock.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay1->id, $referenceDate->copy()->setTime(8, 0), $referenceDate->copy()->setTime(11, 0));
            $wo = $workOrders->start($wo);
            $execution->addPlannedPart($wo, ['product_id' => $products->bySku['TEST-EQP-002']->id, 'description' => 'Replacement lift arm assembly', 'quantity' => 1]);

            return $workOrders->waitForPart($wo);
        }, null);

        // FT-WO-QC_PENDING: job finished, submitted to QC, inspection not yet started.
        $this->scenario('FT-WO-QC_PENDING', $ops->vehicles['CAR_2'], $referenceDate, function () use ($workOrders, $execution, $mechanics, $laborTimer, $ops, $bay1, $referenceDate, $engineGroupId, $leadMechanic) {
            $wo = $workOrders->create($ops->vehicles['CAR_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'MEDIUM',
                'complaint' => '[FT-WO-QC_PENDING] Scheduled oil & filter change, ready for QC.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay1->id, $referenceDate->copy()->setTime(8, 0), $referenceDate->copy()->setTime(9, 0));
            $wo = $workOrders->start($wo);
            $job = $execution->addJob($wo, ['component_group_id' => $engineGroupId, 'service_item' => 'Oil & filter change', 'description' => 'Routine oil and filter service.', 'estimated_hours' => 1]);
            $mechanics->assign($wo, $leadMechanic, 'PRIMARY', $job->id, null);
            $execution->updateJobStatus($job, 'ASSIGNED');
            $log = $laborTimer->start($job, $leadMechanic->id);
            $laborTimer->finish($log);
            $execution->updateJobStatus($job->fresh(), 'COMPLETED');

            return $workOrders->submitToQc($wo);
        }, null);

        // FT-WO-COMPLETED: full flow through QC pass + road test + complete (yesterday).
        $this->scenario('FT-WO-COMPLETED', $ops->vehicles['CAR_3'], $referenceDate, function () use ($workOrders, $execution, $mechanics, $laborTimer, $qc, $ops, $bay1, $referenceDate, $brakeGroupId, $leadMechanic, $qcInspector, $partService, $products) {
            $yesterday = $referenceDate->copy()->subDay();
            $wo = $workOrders->create($ops->vehicles['CAR_3'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'CORRECTIVE', 'priority' => 'HIGH',
                'complaint' => '[FT-WO-COMPLETED] Brake pads replaced and verified.',
            ], null);

            // Findings/Diagnosis are a Draft-only scoping exercise (see
            // WorkOrderExecutionService::assertFindingScopeEditable) — must be
            // recorded before submit()/approve()/.../start() move the WO past Draft.
            $finding = $execution->addFinding($wo, ['component_group_id' => $brakeGroupId, 'severity' => 'HIGH', 'description' => 'Worn brake pads.'], $leadMechanic->id);
            $execution->addDiagnosis($wo, ['work_order_finding_id' => $finding->id, 'root_cause' => 'Brake pads worn beyond limit.'], $leadMechanic->id);

            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay1->id, $yesterday->copy()->setTime(8, 0), $yesterday->copy()->setTime(11, 0));
            $wo = $workOrders->start($wo);

            $job = $execution->addJob($wo, ['component_group_id' => $brakeGroupId, 'service_item' => 'Replace brake pads', 'description' => 'Replace front brake pads.', 'estimated_hours' => 1.5]);
            $mechanics->assign($wo, $leadMechanic, 'PRIMARY', $job->id, null);
            $execution->updateJobStatus($job, 'ASSIGNED');
            $log = $laborTimer->start($job, $leadMechanic->id);
            $laborTimer->finish($log);
            $execution->updateJobStatus($job->fresh(), 'COMPLETED');

            $part = $execution->addPlannedPart($wo, ['product_id' => $products->bySku['TEST-SP-001']->id, 'description' => 'Brake Pad Set (Front)', 'quantity' => 1]);
            $part = $partService->reserve($part, null, null, null);
            $part = $partService->issue($part, null, null);
            $partService->consume($part, null, null);
            $execution->resolveFinding($finding, 'Brake pads replaced.', $leadMechanic->id);

            $wo = $workOrders->submitToQc($wo);
            $inspection = $qc->start($wo, $qcInspector->id, null);
            $qc->pass($inspection);
            $qc->complete($inspection);

            app(RoadTestService::class)->record($wo, [
                'tester_worker_id' => $qcInspector->id,
                'start_odometer' => (float) $ops->vehicles['CAR_3']->current_odometer,
                'end_odometer' => (float) $ops->vehicles['CAR_3']->current_odometer + 8,
                'duration_minutes' => 15, 'result' => 'PASS',
            ]);

            $wo = $workOrders->complete($wo, 'Brake pads replaced, road test passed.', null);
            app(VehicleReleaseService::class)->release($wo, [
                'release_odometer' => (float) $ops->vehicles['CAR_3']->current_odometer + 8,
                'release_condition' => 'GOOD', 'notes' => 'Released to customer.',
            ], null);

            return $wo->fresh();
        }, null);

        // FT-WO-CLOSED: same shape, older, and explicitly closed (Scheduler must exclude it).
        $this->scenario('FT-WO-CLOSED', $ops->vehicles['CAR_1'], $referenceDate, function () use ($workOrders, $execution, $mechanics, $laborTimer, $qc, $ops, $bay1, $referenceDate, $engineGroupId, $leadMechanic, $qcInspector) {
            $twoDaysAgo = $referenceDate->copy()->subDays(2);
            $wo = $workOrders->create($ops->vehicles['CAR_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'LOW',
                'complaint' => '[FT-WO-CLOSED] Oil change completed and closed.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay1->id, $twoDaysAgo->copy()->setTime(8, 0), $twoDaysAgo->copy()->setTime(9, 0));
            $wo = $workOrders->start($wo);
            $job = $execution->addJob($wo, ['component_group_id' => $engineGroupId, 'service_item' => 'Oil change', 'description' => 'Routine oil change.', 'estimated_hours' => 1]);
            $mechanics->assign($wo, $leadMechanic, 'PRIMARY', $job->id, null);
            $execution->updateJobStatus($job, 'ASSIGNED');
            $log = $laborTimer->start($job, $leadMechanic->id);
            $laborTimer->finish($log);
            $execution->updateJobStatus($job->fresh(), 'COMPLETED');

            $wo = $workOrders->submitToQc($wo);
            $inspection = $qc->start($wo, $qcInspector->id, null);
            $qc->pass($inspection);
            $qc->complete($inspection);
            $wo = $workOrders->complete($wo, 'Oil change completed.', null);

            return $workOrders->close($wo);
        }, null);

        // Additional relative-dated Scheduled WOs (Section 29): Today+2/+3 for the
        // 7-day window, plus Today+7/Today-7 to exercise next-week/previous-week navigation.
        $this->scenario('FT-WO-SCHEDULED-PLUS2', $ops->vehicles['TRUCK_2'], $referenceDate, function () use ($workOrders, $ops, $bay2, $referenceDate) {
            $wo = $workOrders->create($ops->vehicles['TRUCK_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'LOW',
                'complaint' => '[FT-WO-SCHEDULED-PLUS2] Tire rotation scheduled.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);

            return $workOrders->schedule($wo, $bay2->id, $referenceDate->copy()->addDays(2)->setTime(9, 0), $referenceDate->copy()->addDays(2)->setTime(10, 0));
        }, fn (WorkOrder $wo) => $wo->update([
            'target_start_at' => $referenceDate->copy()->addDays(2)->setTime(9, 0),
            'target_completion_at' => $referenceDate->copy()->addDays(2)->setTime(10, 0),
        ]));

        $this->scenario('FT-WO-SCHEDULED-PLUS3', $ops->vehicles['CAR_1'], $referenceDate, function () use ($workOrders, $ops, $bay1, $referenceDate) {
            $wo = $workOrders->create($ops->vehicles['CAR_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'LOW',
                'complaint' => '[FT-WO-SCHEDULED-PLUS3] Air filter replacement scheduled.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);

            return $workOrders->schedule($wo, $bay1->id, $referenceDate->copy()->addDays(3)->setTime(9, 0), $referenceDate->copy()->addDays(3)->setTime(10, 0));
        }, fn (WorkOrder $wo) => $wo->update([
            'target_start_at' => $referenceDate->copy()->addDays(3)->setTime(9, 0),
            'target_completion_at' => $referenceDate->copy()->addDays(3)->setTime(10, 0),
        ]));

        $this->scenario('FT-WO-SCHEDULED-NEXTWEEK', $ops->vehicles['TRUCK_1'], $referenceDate, function () use ($workOrders, $ops, $bay2, $referenceDate) {
            $wo = $workOrders->create($ops->vehicles['TRUCK_1'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'LOW',
                'complaint' => '[FT-WO-SCHEDULED-NEXTWEEK] Major service scheduled next week.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);

            return $workOrders->schedule($wo, $bay2->id, $referenceDate->copy()->addWeek()->setTime(9, 0), $referenceDate->copy()->addWeek()->setTime(13, 0));
        }, fn (WorkOrder $wo) => $wo->update([
            'target_start_at' => $referenceDate->copy()->addWeek()->setTime(9, 0),
            'target_completion_at' => $referenceDate->copy()->addWeek()->setTime(13, 0),
        ]));

        // Previous-week: completed rather than left Scheduled, since a past target date
        // that never started would be a stale/implausible state, not a real historical record.
        $this->scenario('FT-WO-COMPLETED-PREVWEEK', $ops->vehicles['CAR_2'], $referenceDate, function () use ($workOrders, $qc, $ops, $bay1, $referenceDate, $qcInspector) {
            $lastWeek = $referenceDate->copy()->subWeek();
            $wo = $workOrders->create($ops->vehicles['CAR_2'], [
                'workshop_id' => $ops->workshop->id, 'maintenance_type' => 'PREVENTIVE', 'priority' => 'LOW',
                'complaint' => '[FT-WO-COMPLETED-PREVWEEK] Service completed last week.',
            ], null);
            $wo = $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo, $bay1->id, $lastWeek->copy()->setTime(9, 0), $lastWeek->copy()->setTime(10, 0));
            $wo = $workOrders->start($wo);
            $wo = $workOrders->submitToQc($wo);
            $inspection = $qc->start($wo, $qcInspector->id, null);
            $qc->pass($inspection);
            $qc->complete($inspection);

            return $workOrders->complete($wo, 'Completed last week.', null);
        }, null);
    }

    /**
     * Looks the scenario up by its stable `[FT-WO-...]` complaint prefix;
     * if it already exists, only refreshes relative-dated fields via
     * `$refreshDates` and returns (idempotent, Section 40). Otherwise runs
     * `$build` once to create and transition it.
     */
    private function scenario(string $scenarioId, Vehicle $vehicle, Carbon $referenceDate, \Closure $build, ?\Closure $refreshDates): void
    {
        $existing = WorkOrder::query()
            ->where('tenant_id', $vehicle->tenant_id)
            ->where('complaint', 'like', "[{$scenarioId}]%")
            ->first();

        if ($existing) {
            $refreshDates?->__invoke($existing);

            return;
        }

        $build();
    }
}
