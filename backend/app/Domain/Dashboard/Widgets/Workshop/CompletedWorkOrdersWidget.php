<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use Illuminate\Database\Query\Builder;

/** WS-03 Completed Work Orders per tenant-local month of completion, by maintenance type. */
class CompletedWorkOrdersWidget extends WorkOrderWidget
{
    public const TYPES = ['PREVENTIVE', 'CORRECTIVE', 'BREAKDOWN', 'INSPECTION', 'CAMPAIGN'];

    public function id(): string
    {
        return 'WS-03';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->completed($context)->groupBy('month', 'wo.maintenance_type')
            ->selectRaw($context->localMonthSql('wo.completed_at').' as month, wo.maintenance_type, count(*) as c')->get();
        $months = [];
        foreach ($context->months() as $month) {
            $months[$month] = ['month' => $month, 'is_current' => $month === $context->currentMonth(), 'total' => 0] + array_fill_keys(self::TYPES, 0);
        }
        foreach ($rows as $r) {
            $months[$r->month][$r->maintenance_type] = (int) $r->c;
            $months[$r->month]['total'] += (int) $r->c;
        }

        return ['data' => ['months' => array_values($months), 'total' => array_sum(array_column($months, 'total'))]];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['nullable', 'date_format:Y-m'], 'maintenance_type' => ['nullable', 'in:'.implode(',', self::TYPES)]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->withListColumns($this->completed($context, $params['month'] ?? null))
            ->when($params['maintenance_type'] ?? null, fn ($q, $t) => $q->where('wo.maintenance_type', $t))
            ->orderByDesc('wo.completed_at');

        return $this->paginate($query, $params, fn ($r) => $this->presentRow($r, $context));
    }

    /** Work Orders COMPLETED or CLOSED with completed_at in the period (or one month of it). */
    protected function completed(DashboardContext $context, ?string $month = null): Builder
    {
        [$from, $to] = $month !== null && in_array($month, $context->months(), true)
            ? $context->monthUtcBounds($month)
            : $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());

        return $this->workOrders($context)->whereIn('wo.status', ['COMPLETED', 'CLOSED'])
            ->where('wo.completed_at', '>=', $from)->where('wo.completed_at', '<', $to);
    }
}
