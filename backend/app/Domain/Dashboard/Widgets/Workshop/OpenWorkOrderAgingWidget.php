<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use Illuminate\Database\Query\Builder;

/**
 * WS-02 Open Work Order Aging — open Work Orders by age since creation, in tenant-local days.
 * Fixed buckets (owner decision 5): 0–3, 4–7, 8–14, 15–30, >30; green ≤ 7, amber 8–14, red > 14.
 * "Time in the current status" is not measured (it would need a curated status history).
 */
class OpenWorkOrderAgingWidget extends WorkOrderWidget
{
    /** bucket => [min days, max days|null] */
    public const BUCKETS = ['d0_3' => [0, 3], 'd4_7' => [4, 7], 'd8_14' => [8, 14], 'd15_30' => [15, 30], 'd30_plus' => [31, null]];

    public function id(): string
    {
        return 'WS-02';
    }

    public function unit(): string
    {
        return 'count';
    }

    public function compute(DashboardContext $context): array
    {
        $counts = [];
        foreach (array_keys(self::BUCKETS) as $bucket) {
            $counts[$bucket] = $this->bucket($this->open($context), $bucket, $context)->count();
        }

        return ['data' => ['total' => array_sum($counts), 'buckets' => $counts]];
    }

    public function detailRules(): ?array
    {
        return ['bucket' => ['nullable', 'in:'.implode(',', array_keys(self::BUCKETS))]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->withListColumns($this->open($context))->orderBy('wo.created_at');
        if ($bucket = $params['bucket'] ?? null) {
            $this->bucket($query, $bucket, $context);
        }

        return $this->paginate($query, $params, fn ($r) => $this->presentRow($r, $context));
    }

    private function open(DashboardContext $context): Builder
    {
        return $this->workOrders($context)->whereIn('wo.status', self::OPEN_STATUSES);
    }

    private function bucket(Builder $query, string $bucket, DashboardContext $context): Builder
    {
        [$min, $max] = self::BUCKETS[$bucket];
        $age = '(?::date - '.$context->localDateSql('wo.created_at').')';
        $query->whereRaw("{$age} >= ?", [$context->todayDate(), $min]);
        if ($max !== null) {
            $query->whereRaw("{$age} <= ?", [$context->todayDate(), $max]);
        }

        return $query;
    }
}
