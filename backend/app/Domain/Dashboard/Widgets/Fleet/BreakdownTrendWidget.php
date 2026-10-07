<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** FL-04 Breakdown Trend — breakdowns reported per tenant-local month, by severity. */
class BreakdownTrendWidget extends Widget
{
    public const SEVERITIES = ['MINOR', 'MAJOR', 'IMMOBILIZED'];

    public function id(): string
    {
        return 'FL-04';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function modules(): array
    {
        return ['MAINTENANCE'];
    }

    public function permissions(): array
    {
        return ['breakdown.view'];
    }

    public function filters(): array
    {
        return ['branch', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->reported($context)
            ->groupBy('month', 'bd.severity')
            ->selectRaw($context->localMonthSql('bd.reported_at').' as month, bd.severity, count(*) as c')
            ->get();
        $months = [];
        foreach ($context->months() as $month) {
            $months[$month] = ['month' => $month, 'is_current' => $month === $context->currentMonth(), 'total' => 0] + array_fill_keys(self::SEVERITIES, 0);
        }
        foreach ($rows as $r) {
            $months[$r->month][$r->severity] = (int) $r->c;
            $months[$r->month]['total'] += (int) $r->c;
        }

        return ['data' => ['months' => array_values($months), 'total' => array_sum(array_column($months, 'total'))]];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['nullable', 'date_format:Y-m'], 'vehicle_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->reported($context, $params['month'] ?? null)
            ->when($params['vehicle_id'] ?? null, fn ($q, $v) => $q->where('bd.vehicle_id', $v))
            ->leftJoin('vehicles as v', 'v.id', '=', 'bd.vehicle_id')
            ->leftJoin('branches as b', 'b.id', '=', 'bd.branch_id')
            ->orderByDesc('bd.reported_at')
            ->select(['bd.id', 'bd.severity', 'bd.status', 'bd.reported_at', 'bd.resolved_at', 'v.id as vehicle_id', 'v.registration_number', 'b.name as branch_name']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'severity' => $r->severity, 'status' => $r->status, 'reported_at' => self::isoUtc($r->reported_at),
            'resolved_at' => self::isoUtc($r->resolved_at), 'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number,
            'branch_name' => $r->branch_name,
        ]);
    }

    /** Breakdowns reported in the period (or in one month of it), branch-scoped. */
    protected function reported(DashboardContext $context, ?string $month = null): Builder
    {
        [$from, $to] = $month !== null && in_array($month, $context->months(), true)
            ? $context->monthUtcBounds($month)
            : $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $query = DB::table('breakdowns as bd')->where('bd.tenant_id', $context->tenantId)
            ->where('bd.reported_at', '>=', $from)->where('bd.reported_at', '<', $to);

        return $context->scopeBranch($query, 'bd.branch_id');
    }
}
