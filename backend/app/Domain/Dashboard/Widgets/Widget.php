<?php

namespace App\Domain\Dashboard\Widgets;

use App\Domain\Dashboard\DashboardContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * One dashboard widget (ID from the audit catalog). A widget declares what it needs (modules, all
 * required permissions, optionally "any of" permissions) and computes its payload from PostgreSQL
 * with the context's scope helpers. Availability is decided on the server only.
 */
abstract class Widget
{
    public const KIND_CURRENT = 'current';

    public const KIND_PERIOD = 'period';

    /** Audit widget ID, e.g. "FN-01". */
    abstract public function id(): string;

    /** `current` = condition now (ignores the period filter); `period` = per tenant-local month. */
    public function kind(): string
    {
        return self::KIND_CURRENT;
    }

    /** Main unit of the widget's values: count, money, days, mixed. */
    public function unit(): string
    {
        return 'count';
    }

    /** @return list<string> modules that must all be active */
    abstract public function modules(): array;

    /** @return list<string> permissions that must all be held */
    public function permissions(): array
    {
        return [];
    }

    /** @return list<string> at least one of these must be held (empty = no such requirement) */
    public function anyPermissions(): array
    {
        return [];
    }

    /** @return list<string> global filters that apply: branch, workshop, warehouse, period */
    public function filters(): array
    {
        return ['branch'];
    }

    /** Bump when the metric definition changes (part of the cache key). */
    public function version(): int
    {
        return 1;
    }

    /** Cache lifetime in seconds. */
    public function ttl(): int
    {
        return $this->kind() === self::KIND_PERIOD ? 300 : 120;
    }

    public function isAvailable(DashboardContext $context): bool
    {
        foreach ($this->modules() as $module) {
            if (! $context->hasModule($module)) {
                return false;
            }
        }
        foreach ($this->permissions() as $permission) {
            if (! $context->can($permission)) {
                return false;
            }
        }
        $any = $this->anyPermissions();

        return $any === [] || collect($any)->contains(fn (string $p) => $context->can($p));
    }

    /**
     * @return array{data: array<string, mixed>, limitations?: list<array{code: string, params?: array<string, mixed>}>}
     */
    abstract public function compute(DashboardContext $context): array;

    /** Validation rules of the drill-down parameters; null = the widget has no drill-down. */
    public function detailRules(): ?array
    {
        return null;
    }

    /**
     * Widget-specific filters accepted by the widget endpoint (and its drill-down), as validation
     * rules. Ids are re-checked inside the widget's scoped queries: an id outside the scope matches
     * nothing.
     */
    public function paramRules(): array
    {
        return [];
    }

    /** Drill-down rows with the same metric and scope rules as compute(). */
    public function detail(DashboardContext $context, array $params): array
    {
        return ['items' => [], 'meta' => null];
    }

    // ---------------------------------------------------------------- helpers

    /** Money as a 2-decimal string (half-up), never a float. */
    protected static function money(mixed $value): string
    {
        return (string) BigDecimal::of($value === null || $value === '' ? '0' : (string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    protected static function moneySum(iterable $values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(BigDecimal::of($value === null || $value === '' ? '0' : (string) $value));
        }

        return (string) $total->toScale(2, RoundingMode::HALF_UP);
    }

    protected static function decimal(mixed $value, int $scale = 2): string
    {
        return (string) BigDecimal::of($value === null || $value === '' ? '0' : (string) $value)->toScale($scale, RoundingMode::HALF_UP);
    }

    /** A stored UTC `timestamp` (naive string) as ISO-8601 UTC. */
    protected static function isoUtc(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, 'UTC')->toIso8601ZuluString();
    }

    /** Whole days between a local date (Y-m-d) and the context's local today (positive = in the past). */
    protected static function daysSince(DashboardContext $context, ?string $date): ?int
    {
        if ($date === null) {
            return null;
        }

        // Both sides as plain calendar dates (no time zone shift between them).
        return (int) CarbonImmutable::parse(substr($date, 0, 10), 'UTC')
            ->diffInDays(CarbonImmutable::parse($context->todayDate(), 'UTC'), false);
    }

    /** Paginate a query-builder query: items + meta. */
    protected function paginate(Builder $query, array $params, ?callable $map = null): array
    {
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $total = (clone $query)->getCountForPagination();
        $rows = $query->forPage($page, $perPage)->get();

        return [
            'items' => $map ? $rows->map($map)->values()->all() : $rows->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /** Paginate an already computed (and ordered) list: items + meta. */
    protected function paginateList(array $items, array $params, ?callable $map = null): array
    {
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));
        $total = count($items);
        $slice = array_slice(array_values($items), ($page - 1) * $perPage, $perPage);

        return [
            'items' => $map ? array_map($map, $slice) : $slice,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /** Count rows grouped by a column, keyed by value, including zero for every expected key. */
    protected static function countsByKey(Builder $query, string $column, array $keys): array
    {
        $rows = $query->selectRaw("{$column} as k, count(*) as c")->groupBy($column)->pluck('c', 'k');
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = (int) ($rows[$key] ?? 0);
        }

        return $result;
    }
}
