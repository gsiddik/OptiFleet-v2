<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Validation\Rule;

/**
 * FN-08 Cost mix per month — parts consumed vs mechanic cost vs external cost paid, per tenant-local
 * month, on exactly the FN-07 basis (OperatingCostQuery), so the period total equals FN-07's total.
 * Months without cost are shown as zero; the running month is flagged. Drill-down: month → cost lines.
 */
class CostMixWidget extends Widget
{
    public function id(): string
    {
        return 'FN-08';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'money';
    }

    public function modules(): array
    {
        return ['WORK_ORDER'];
    }

    public function permissions(): array
    {
        return [DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'period'];
    }

    public function version(): int
    {
        return 2; // completeness basis, payment anomalies
    }

    public function compute(DashboardContext $context): array
    {
        $lines = OperatingCostQuery::lines($context, $context->periodStartDate(), $context->periodEndDateExclusive());
        $byMonth = $lines->groupBy('month');
        $months = array_map(fn (string $month) => [
            'month' => $month, 'is_current' => $month === $context->currentMonth(),
        ] + OperatingCostQuery::totals($byMonth[$month] ?? collect()), $context->months());

        $coverage = OperatingCostQuery::laborCoverage($context, $lines, $context->periodStartDate(), $context->periodEndDateExclusive());
        $anomalies = OperatingCostQuery::paymentAnomalies($context, $context->periodStartDate(), $context->periodEndDateExclusive());

        return [
            'data' => ['months' => $months, 'totals' => OperatingCostQuery::totals($lines), 'payment_anomalies' => $anomalies->count()],
            'limitations' => OperatingCostQuery::limitations($context, $lines, $coverage, $anomalies),
            'basis' => OperatingCostQuery::basis($context, $coverage),
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['required', 'date_format:Y-m'], 'component' => ['nullable', Rule::in(OperatingCostQuery::COMPONENTS)]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        [$from, $to] = $context->monthDateBounds($params['month']);
        $lines = OperatingCostQuery::lines($context, $from, $to)
            ->when($params['component'] ?? null, fn ($c, $component) => $c->where('component', $component))
            ->sortBy([['on', 'asc'], ['wo_number', 'asc']])->values()->all();

        return $this->paginateList($lines, $params, fn ($l) => [
            'id' => $l['ref']['id'], 'month' => $l['month'], 'on' => $l['on'], 'component' => $l['component'], 'amount' => $l['amount'],
            'vehicle_id' => $l['vehicle_id'], 'work_order_id' => $l['work_order_id'], 'wo_number' => $l['wo_number'],
            'description' => $l['ref']['product_name'] ?? $l['ref']['worker_name'] ?? $l['ref']['document'] ?? null,
            'hours' => $l['ref']['hours'] ?? null, 'quantity' => $l['ref']['quantity'] ?? null,
        ]);
    }
}
