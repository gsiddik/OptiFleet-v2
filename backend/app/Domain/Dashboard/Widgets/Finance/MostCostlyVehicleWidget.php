<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\Dashboard\WorkTime\WorkTimeQuery;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FN-07 Most Costly Vehicle — vehicles ranked by operating cost in the period (OperatingCostQuery:
 * parts/tires consumed + mechanic cost + external cost paid). Every vehicle in scope is ranked,
 * including those without cost (0 — no activity, which is not "efficient"). Drill-down: ranking →
 * vehicle (its Work Orders) → Work Order (consumption, mechanic and payment lines).
 */
class MostCostlyVehicleWidget extends Widget
{
    public const TOP = 10;

    public function id(): string
    {
        return 'FN-07';
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

    public function paramRules(): array
    {
        return ['vehicle_category_id' => ['nullable', 'uuid'], 'vehicle_id' => ['nullable', 'uuid']];
    }

    public function compute(DashboardContext $context): array
    {
        $lines = $this->lines($context);
        $ranking = $this->ranking($context, $lines);

        return [
            'data' => [
                'totals' => OperatingCostQuery::totals($lines),
                'vehicles' => array_slice($ranking, 0, self::TOP),
                'vehicles_total' => count($ranking),
                'vehicles_with_cost' => count(array_filter($ranking, fn ($v) => BigDecimal::of($v['total'])->isPositive())),
                'options' => $this->options($context),
            ],
            'limitations' => OperatingCostQuery::limitations($context, $lines),
        ];
    }

    public function detailRules(): ?array
    {
        return ['vehicle_id' => ['nullable', 'uuid'], 'work_order_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        if (! empty($params['work_order_id'])) {
            $lines = $this->lines($context, ['work_order_id' => $params['work_order_id']]);

            return $this->paginateList($lines->sortBy([['component', 'asc'], ['on', 'asc']])->values()->all(), $params, fn ($l) => $this->presentLine($l));
        }
        if (! empty($params['vehicle_id'])) {
            $lines = $this->lines($context, ['vehicle_id' => $params['vehicle_id']]);
            $complete = WorkTimeQuery::historyComplete($context, $lines->pluck('work_order_id')->unique()->values()->all());
            $rows = $lines->groupBy('work_order_id')->map(fn (Collection $rows, $id) => [
                'work_order_id' => $id, 'wo_number' => $rows->first()['wo_number'], 'history_complete' => $complete[$id] ?? false,
            ] + OperatingCostQuery::totals($rows))->sortByDesc(fn ($r) => (float) $r['total'])->values()->all();

            return $this->paginateList($rows, $params);
        }

        return $this->paginateList($this->ranking($context, $this->lines($context)), $params);
    }

    private function lines(DashboardContext $context, array $narrow = []): Collection
    {
        $narrow += array_filter([
            'vehicle_category_id' => $context->filters->param('vehicle_category_id'),
            'vehicle_id' => $context->filters->param('vehicle_id'),
        ]);

        return OperatingCostQuery::lines($context, $context->periodStartDate(), $context->periodEndDateExclusive(), $narrow);
    }

    /** All vehicles in scope (plus any vehicle with cost on an accessible Work Order), highest total first. */
    private function ranking(DashboardContext $context, Collection $lines): array
    {
        $perVehicle = OperatingCostQuery::perVehicle($lines);
        $inScope = DB::table('vehicles as v')->where('v.tenant_id', $context->tenantId)->whereNull('v.deleted_at')
            ->when($context->filters->param('vehicle_category_id'), fn ($q, $id) => $q->where('v.vehicle_category_id', $id))
            ->when($context->filters->param('vehicle_id'), fn ($q, $id) => $q->where('v.id', $id));
        $context->scopeBranch($inScope, 'v.branch_id');
        $ids = $inScope->pluck('v.id')->merge($perVehicle->keys())->unique()->values();

        $zero = array_fill_keys([...OperatingCostQuery::COMPONENTS, 'total'], '0.00');
        $rows = DB::table('vehicles as v')->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->leftJoin('vehicle_categories as c', 'c.id', '=', 'v.vehicle_category_id')
            ->whereIn('v.id', $ids)->where('v.tenant_id', $context->tenantId)
            ->get(['v.id', 'v.registration_number', 'b.name as branch_name', 'c.name as category_name'])
            ->map(fn ($v) => ['vehicle_id' => $v->id, 'registration_number' => $v->registration_number, 'branch_name' => $v->branch_name,
                'category_name' => $v->category_name] + ($perVehicle[$v->id] ?? $zero))
            ->all();
        usort($rows, fn ($a, $b) => BigDecimal::of($b['total'])->compareTo($a['total']) ?: strcmp((string) $a['registration_number'], (string) $b['registration_number']));

        return $rows;
    }

    /** Filter choices for the in-card selects: categories and vehicles in the user's branch scope. */
    private function options(DashboardContext $context): array
    {
        $vehicles = DB::table('vehicles as v')->leftJoin('vehicle_categories as c', 'c.id', '=', 'v.vehicle_category_id')
            ->where('v.tenant_id', $context->tenantId)->whereNull('v.deleted_at');
        $context->scopeBranch($vehicles, 'v.branch_id');
        $rows = $vehicles->orderBy('v.registration_number')->get(['v.id', 'v.registration_number', 'v.vehicle_category_id', 'c.name as category_name']);

        return [
            'categories' => $rows->whereNotNull('vehicle_category_id')->unique('vehicle_category_id')->sortBy('category_name')
                ->map(fn ($r) => ['id' => $r->vehicle_category_id, 'name' => $r->category_name])->values()->all(),
            'vehicles' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->registration_number, 'category_id' => $r->vehicle_category_id])->values()->all(),
        ];
    }

    private function presentLine(array $l): array
    {
        $ref = $l['ref'];
        $rate = null;
        if ($l['component'] === 'LABOR' && $l['amount'] !== null && $ref['seconds'] > 0) {
            $rate = (string) BigDecimal::of($l['amount'])->multipliedBy(3600)->dividedBy($ref['seconds'], 2, RoundingMode::HALF_UP);
        }

        return [
            'id' => $ref['id'], 'component' => $l['component'], 'on' => $l['on'], 'amount' => $l['amount'],
            'work_order_id' => $l['work_order_id'], 'wo_number' => $l['wo_number'],
            'description' => match ($l['component']) {
                'PARTS' => trim(($ref['sku'] ? $ref['sku'].' · ' : '').$ref['product_name']),
                'LABOR' => $ref['worker_name'],
                default => $ref['document'],
            },
            'source' => $ref['source'] ?? ($l['component'] === 'PARTS' ? $ref['product_type'] : null),
            'quantity' => $ref['quantity'] ?? null, 'unit_cost' => $ref['unit_cost'] ?? null,
            'hours' => $ref['hours'] ?? null, 'effective_rate' => $rate, 'cycle' => $ref['cycle'] ?? null, 'open' => $ref['open'] ?? false,
        ];
    }
}
