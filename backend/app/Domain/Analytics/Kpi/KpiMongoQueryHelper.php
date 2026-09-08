<?php

namespace App\Domain\Analytics\Kpi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Section 43: KPI calculators aggregate the already-computed daily_*
 * collections over the requested date range rather than re-querying
 * PostgreSQL — that is the whole point of the Mongo projection layer.
 *
 * Every daily_* collection's unique key is (tenant_id, snapshot_date,
 * <dimension field>) — $field/$value select which document set to read:
 * a null $value (the default) matches the tenant-wide rollup document,
 * a real ID matches that one dimension's document (e.g. one branch).
 */
class KpiMongoQueryHelper
{
    private function query(string $collection, string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $field, mixed $value)
    {
        return DB::connection('mongodb')->table($collection)
            ->where('tenant_id', $tenantId)
            ->where('snapshot_date', '>=', $from->format('Y-m-d'))
            ->where('snapshot_date', '<=', $to->format('Y-m-d'))
            ->where($field, $value);
    }

    /** Sum one field (dot-path supported, e.g. "rework.count") over the range. */
    public function sum(string $collection, string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $field, mixed $value, string $sumField): float
    {
        return (float) $this->query($collection, $tenantId, $from, $to, $field, $value)->sum($sumField);
    }

    /** The most recent document at or before $to — for point-in-time figures (stock levels, MTBF, vehicle counts). */
    public function latest(string $collection, string $tenantId, CarbonImmutable $to, string $field, mixed $value): ?object
    {
        $row = DB::connection('mongodb')->table($collection)
            ->where('tenant_id', $tenantId)
            ->where('snapshot_date', '<=', $to->format('Y-m-d'))
            ->where($field, $value)
            ->orderByDesc('snapshot_date')
            ->first();

        return $row ? (object) (array) $row : null;
    }

    /** Raw rows for a weighted average / custom aggregation computed client-side. */
    public function rows(string $collection, string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->query($collection, $tenantId, $from, $to, $field, $value)->get($columns)
            ->map(fn ($row) => (object) (array) $row);
    }

    /**
     * Reads a possibly-nested field ("availability.available_count") off a
     * document regardless of whether the Mongo driver hydrated that
     * sub-document as an array or an object.
     */
    public static function nested(mixed $doc, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $value = $doc;
        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } else {
                return $default;
            }
        }

        return $value;
    }
}
