<?php

namespace App\Domain\Analytics\Kpi;

use App\Domain\Analytics\Support\AnalyticsValidator;
use Carbon\CarbonImmutable;

/**
 * Phase 6 Section 39 — the initial KPI catalog. Each KPI is exposed only
 * because the underlying daily_* fields it reads already exist (Section
 * 39: "only expose a KPI if the source data required exists") — every
 * formula below is documented at the point it is registered.
 *
 * Aggregation mode note (Section 43): event-style totals (counts, costs,
 * time-in-status) are SUMMED across the requested date range; point-in-
 * time balances (stock levels, MTBF, fleet size) use the LATEST document
 * in range, since summing a balance across days would double count.
 */
class KpiCatalog
{
    public function __construct(private readonly KpiMongoQueryHelper $q) {}

    public function registerAll(KpiRegistry $registry): void
    {
        foreach ($this->definitions() as $definition) {
            $registry->register($definition);
        }
    }

    /** @return KpiDefinition[] */
    private function definitions(): array
    {
        return [
            new KpiDefinition(
                'fleet_availability', 'Fleet Availability', 'percentage',
                'Share of the fleet that was ACTIVE (available for use) as of the latest snapshot in the period.',
                'Available Vehicle Count / Planned (Total) Vehicle Count x 100',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $doc = $this->q->latest('daily_fleet_snapshots', $tenantId, $to, 'branch_id', $branchId);
                    $available = KpiMongoQueryHelper::nested($doc, 'availability.available_count', 0);
                    $planned = KpiMongoQueryHelper::nested($doc, 'availability.planned_count', 0);

                    return $this->ratio($available, $planned);
                },
            ),
            new KpiDefinition(
                'maintenance_compliance', 'Maintenance Compliance', 'percentage',
                'Share of currently-scheduled maintenance that is not overdue, as of the latest snapshot in the period.',
                '(Scheduled Maintenance Count - Overdue Maintenance) / Scheduled Maintenance Count x 100',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $doc = $this->q->latest('daily_maintenance_metrics', $tenantId, $to, 'branch_id', $branchId);
                    $scheduled = $doc->scheduled_maintenance_count ?? 0;
                    $overdue = $doc->overdue_maintenance ?? 0;

                    return $this->ratio($scheduled - $overdue, $scheduled);
                },
            ),
            new KpiDefinition(
                'preventive_maintenance_ratio', 'Preventive Maintenance Ratio', 'percentage',
                'Share of maintenance Work Orders completed in the period that were PREVENTIVE.',
                'Preventive Maintenance Count / (Preventive + Corrective + Breakdown) x 100',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $preventive = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'preventive_maintenance');
                    $corrective = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'corrective_maintenance');
                    $breakdown = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'breakdown_maintenance');

                    return $this->ratio($preventive, $preventive + $corrective + $breakdown);
                },
            ),
            new KpiDefinition(
                'breakdown_rate', 'Breakdown Rate', 'per_100_vehicles',
                'Breakdowns reported in the period per 100 vehicles in the fleet (fleet size taken as of the latest snapshot in the period).',
                'Breakdown Count (period) / Total Vehicles (latest) x 100',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $breakdowns = $this->q->sum('daily_breakdown_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'breakdown_count');
                    $fleetDoc = $this->q->latest('daily_fleet_snapshots', $tenantId, $to, 'branch_id', $branchId);
                    $totalVehicles = $fleetDoc->total_vehicles ?? 0;

                    return $this->ratio($breakdowns, $totalVehicles);
                },
            ),
            new KpiDefinition(
                'mttr', 'Mean Time To Repair', 'minutes',
                'Total qualifying repair duration / number of qualifying repairs (COMPLETED/CLOSED Work Orders only), across the period.',
                'Sum(mttr_minutes x mttr_sample_size) / Sum(mttr_sample_size)',
                ['vehicle'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $vehicleId) {
                    $rows = $this->q->rows('daily_downtime_metrics', $tenantId, $from, $to, 'vehicle_id', $vehicleId, ['mttr_minutes', 'mttr_sample_size']);
                    $totalMinutes = $rows->sum(fn ($r) => ($r->mttr_minutes ?? 0) * ($r->mttr_sample_size ?? 0));
                    $totalSamples = $rows->sum('mttr_sample_size');

                    return $this->ratioRaw($totalMinutes, $totalSamples);
                },
            ),
            new KpiDefinition(
                'mtbf', 'Mean Time Between Failures', 'hours',
                'Observed hours / breakdown count over a rolling lookback window, as of the latest snapshot in the period. Descriptive reliability statistic, not a prediction.',
                'Sum(mtbf_observed_hours) / Sum(mtbf_breakdown_count)',
                ['vehicle'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $vehicleId) {
                    $doc = $this->q->latest('daily_downtime_metrics', $tenantId, $to, 'vehicle_id', $vehicleId);

                    return $this->ratioRaw($doc->mtbf_observed_hours ?? 0, $doc->mtbf_breakdown_count ?? 0);
                },
            ),
            new KpiDefinition(
                'repeat_repair_rate', 'Repeat Repair Rate', 'percentage',
                'Share of maintenance activity in the period that was a repeat repair on the same vehicle/component group.',
                'Repeat Maintenance Count / (Preventive + Corrective + Breakdown) x 100',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $repeat = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'repeat_maintenance');
                    $preventive = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'preventive_maintenance');
                    $corrective = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'corrective_maintenance');
                    $breakdown = $this->q->sum('daily_maintenance_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'breakdown_maintenance');

                    return $this->ratio($repeat, $preventive + $corrective + $breakdown);
                },
            ),
            new KpiDefinition(
                'rework_rate', 'Rework Rate', 'percentage',
                'Share of completed Work Orders whose audit trail shows a transition into REWORK status.',
                'Sum(rework.count) / Sum(rework.of_completed) x 100',
                ['workshop'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $workshopId) {
                    $reworkCount = $this->q->sum('daily_work_order_metrics', $tenantId, $from, $to, 'workshop_id', $workshopId, 'rework.count');
                    $ofCompleted = $this->q->sum('daily_work_order_metrics', $tenantId, $from, $to, 'workshop_id', $workshopId, 'rework.of_completed');

                    return $this->ratio($reworkCount, $ofCompleted);
                },
            ),
            new KpiDefinition(
                'work_order_cycle_time', 'Work Order Cycle Time', 'minutes',
                'Average creation-to-completion time for Work Orders completed in the period, weighted by daily completed count.',
                'Sum(avg_cycle_time_minutes x completed) / Sum(completed)',
                ['workshop'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $workshopId) {
                    $rows = $this->q->rows('daily_work_order_metrics', $tenantId, $from, $to, 'workshop_id', $workshopId, ['avg_cycle_time_minutes', 'completed']);
                    $totalMinutes = $rows->sum(fn ($r) => ($r->avg_cycle_time_minutes ?? 0) * ($r->completed ?? 0));
                    $totalCompleted = $rows->sum('completed');

                    return $this->ratioRaw($totalMinutes, $totalCompleted);
                },
            ),
            new KpiDefinition(
                'workshop_utilization', 'Workshop Utilization', 'percentage',
                'Workspace-occupied minutes / workspace-available minutes over the period.',
                'Sum(occupied_minutes) / Sum(available_minutes) x 100',
                ['workshop'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $workshopId) {
                    $occupied = $this->q->sum('daily_workshop_metrics', $tenantId, $from, $to, 'workshop_id', $workshopId, 'workspace_utilization.occupied_minutes');
                    $available = $this->q->sum('daily_workshop_metrics', $tenantId, $from, $to, 'workshop_id', $workshopId, 'workspace_utilization.available_minutes');

                    return $this->ratio($occupied, $available);
                },
            ),
            new KpiDefinition(
                'mechanic_utilization', 'Mechanic Utilization', 'percentage',
                'Actual labor minutes / standard shift minutes over the period, against a configured standard shift length.',
                'Sum(actual_minutes) / Sum(standard_shift_minutes) x 100',
                ['mechanic'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $mechanicId) {
                    $actual = $this->q->sum('daily_mechanic_metrics', $tenantId, $from, $to, 'mechanic_id', $mechanicId, 'utilization.actual_minutes');
                    $shift = $this->q->sum('daily_mechanic_metrics', $tenantId, $from, $to, 'mechanic_id', $mechanicId, 'utilization.standard_shift_minutes');

                    return $this->ratio($actual, $shift);
                },
            ),
            new KpiDefinition(
                'inventory_turnover_foundation', 'Inventory Turnover (Foundation)', 'ratio',
                'Value issued via stock movements over the period, relative to the latest inventory value. A same-period foundation ratio, not an annualized turnover rate.',
                'Sum(issued_value) / Latest(inventory_value)',
                ['warehouse'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $warehouseId) {
                    $issued = $this->q->sum('daily_inventory_metrics', $tenantId, $from, $to, 'warehouse_id', $warehouseId, 'issued_value');
                    $latest = $this->q->latest('daily_inventory_metrics', $tenantId, $to, 'warehouse_id', $warehouseId);

                    return $this->ratioRaw($issued, $latest->inventory_value ?? 0);
                },
            ),
            new KpiDefinition(
                'stockout_rate', 'Stockout Rate', 'percentage',
                'Share of tracked products with zero (or negative) stock on hand, as of the latest snapshot in the period.',
                'Out Of Stock Count / Total Products Tracked x 100',
                ['warehouse'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $warehouseId) {
                    $doc = $this->q->latest('daily_inventory_metrics', $tenantId, $to, 'warehouse_id', $warehouseId);

                    return $this->ratio($doc->out_of_stock_count ?? 0, $doc->total_products_tracked ?? 0);
                },
            ),
            new KpiDefinition(
                'procurement_lead_time', 'Procurement Lead Time', 'days',
                'Average time from PO order date to goods receipt, over receipts posted in the period.',
                'Sum(procurement_lead_time.total_days) / Sum(procurement_lead_time.sample_size)',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $totalDays = $this->q->sum('daily_procurement_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'procurement_lead_time.total_days');
                    $samples = $this->q->sum('daily_procurement_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'procurement_lead_time.sample_size');

                    return $this->ratioRaw($totalDays, $samples);
                },
            ),
            new KpiDefinition(
                'vendor_on_time_delivery', 'Vendor On-Time Delivery', 'percentage',
                'Share of a vendor\'s receipts in the period that arrived on or before the PO\'s expected delivery date.',
                'Sum(on_time_delivery.on_time_count) / Sum(on_time_delivery.total_receipts) x 100',
                ['vendor'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $vendorId) {
                    $onTime = $this->q->sum('daily_vendor_metrics', $tenantId, $from, $to, 'vendor_id', $vendorId, 'on_time_delivery.on_time_count');
                    $total = $this->q->sum('daily_vendor_metrics', $tenantId, $from, $to, 'vendor_id', $vendorId, 'on_time_delivery.total_receipts');

                    return $this->ratio($onTime, $total);
                },
            ),
            new KpiDefinition(
                'maintenance_cost_per_vehicle', 'Maintenance Cost per Vehicle', 'currency',
                'Total maintenance cost in the period, divided by fleet size as of the latest snapshot in the period.',
                'Sum(total_maintenance_cost) / Latest(vehicle_count)',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $totalCost = $this->q->sum('daily_cost_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'total_maintenance_cost');
                    $latest = $this->q->latest('daily_cost_metrics', $tenantId, $to, 'branch_id', $branchId);

                    return $this->ratioRaw($totalCost, $latest->vehicle_count ?? 0);
                },
            ),
            new KpiDefinition(
                'maintenance_cost_per_km', 'Maintenance Cost per Km', 'currency',
                'Total maintenance cost in the period, divided by the fleet\'s total odometer reading as of the latest snapshot in the period (a current-odometer proxy, not a true period-distance delta).',
                'Sum(total_maintenance_cost) / Latest(total_odometer_km)',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $totalCost = $this->q->sum('daily_cost_metrics', $tenantId, $from, $to, 'branch_id', $branchId, 'total_maintenance_cost');
                    $latest = $this->q->latest('daily_cost_metrics', $tenantId, $to, 'branch_id', $branchId);

                    return $this->ratioRaw($totalCost, $latest->total_odometer_km ?? 0);
                },
            ),
            new KpiDefinition(
                'tire_cost_per_km', 'Tire Cost per Km', 'currency',
                'Average tire cost per kilometer of tread life, over tires removed in the period.',
                'Sum(cost_per_km x cost_per_km_sample_size) / Sum(cost_per_km_sample_size)',
                ['branch'],
                function (string $tenantId, CarbonImmutable $from, CarbonImmutable $to, mixed $branchId) {
                    $rows = $this->q->rows('daily_tire_metrics', $tenantId, $from, $to, 'branch_id', $branchId, ['cost_per_km', 'cost_per_km_sample_size']);
                    $weighted = $rows->sum(fn ($r) => ($r->cost_per_km ?? 0) * ($r->cost_per_km_sample_size ?? 0));
                    $samples = $rows->sum('cost_per_km_sample_size');

                    return $this->ratioRaw($weighted, $samples);
                },
            ),
        ];
    }

    private function ratio(float|int $numerator, float|int $denominator): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => AnalyticsValidator::safeDivide((float) $numerator * 100, (float) $denominator),
        ];
    }

    private function ratioRaw(float|int $numerator, float|int $denominator): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            'value' => AnalyticsValidator::safeDivide((float) $numerator, (float) $denominator),
        ];
    }
}
