<?php

namespace App\Domain\Dashboard;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Everything a dashboard widget may rely on for one request: the tenant, the user's effective
 * permissions / data scope / entitled modules, the tenant's time zone and the validated filters.
 *
 * Scope semantics (owner decision 4 — no access-policy change):
 *  - vehicle-based data   → the user's branch scope on the vehicle's / record's branch;
 *  - work-order-based data → the user's workshop scope on `work_orders.workshop_id` (the rule the
 *    Work Order list/detail already enforce). The branch filter then only narrows by business
 *    attribution (`work_orders.branch_id`) INSIDE the accessible Work Orders — it never widens access;
 *  - warehouse-based data → the user's warehouse scope; the branch filter narrows to warehouses of
 *    that branch (warehouses without a branch only ever appear when they are inside the scope).
 *
 * Allowed-id lists are `null` for tenant-wide scope and a (possibly empty) list otherwise.
 */
final class DashboardContext
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $modules
     * @param  list<string>|null  $branchIds
     * @param  list<string>|null  $workshopIds
     * @param  list<string>|null  $warehouseIds
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly User $user,
        public readonly string $timezone,
        public readonly array $permissions,
        public readonly array $modules,
        public readonly ?array $branchIds,
        public readonly ?array $workshopIds,
        public readonly ?array $warehouseIds,
        public readonly DashboardFilters $filters,
        public readonly CarbonImmutable $now,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasModule(string $code): bool
    {
        return in_array($code, $this->modules, true);
    }

    public function canFinance(): bool
    {
        return $this->can(DashboardPermissions::FINANCE);
    }

    /** Tenant-local "today" (a date, no time). */
    public function today(): CarbonImmutable
    {
        return $this->now->setTimezone($this->timezone)->startOfDay();
    }

    public function todayDate(): string
    {
        return $this->today()->toDateString();
    }

    /** "Now" as a UTC naive timestamp string, comparable with stored timestamps. */
    public function nowUtc(): string
    {
        return $this->now->utc()->format('Y-m-d H:i:s');
    }

    /** Tenant-local month keys (YYYY-MM): N full months followed by the current month. */
    public function months(): array
    {
        $current = $this->today()->startOfMonth();
        $months = [];
        for ($i = $this->filters->months; $i >= 0; $i--) {
            $months[] = $current->subMonthsNoOverflow($i)->format('Y-m');
        }

        return $months;
    }

    public function currentMonth(): string
    {
        return $this->today()->format('Y-m');
    }

    /** First local day of the period (inclusive) and first local day after it (exclusive). */
    public function periodStartDate(): string
    {
        return $this->today()->startOfMonth()->subMonthsNoOverflow($this->filters->months)->toDateString();
    }

    public function periodEndDateExclusive(): string
    {
        return $this->today()->startOfMonth()->addMonthNoOverflow()->toDateString();
    }

    /** UTC bounds of a local date range, for `timestamp` columns stored in UTC. */
    public function utcBounds(string $fromLocalDate, string $toLocalDateExclusive): array
    {
        return [
            CarbonImmutable::parse($fromLocalDate, $this->timezone)->utc()->format('Y-m-d H:i:s'),
            CarbonImmutable::parse($toLocalDateExclusive, $this->timezone)->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** UTC bounds of one local month (YYYY-MM). */
    public function monthUtcBounds(string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01', $this->timezone);

        return $this->utcBounds($start->toDateString(), $start->addMonthNoOverflow()->toDateString());
    }

    public function monthDateBounds(string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');

        return [$start->toDateString(), $start->addMonthNoOverflow()->toDateString()];
    }

    /** SQL expression bucketing a UTC `timestamp` column into a tenant-local YYYY-MM key. */
    public function localMonthSql(string $column): string
    {
        return "to_char(({$column} AT TIME ZONE 'UTC') AT TIME ZONE ".$this->quotedTimezone().", 'YYYY-MM')";
    }

    /** SQL expression: the tenant-local date of a UTC `timestamp` column. */
    public function localDateSql(string $column): string
    {
        return "(({$column} AT TIME ZONE 'UTC') AT TIME ZONE ".$this->quotedTimezone().')::date';
    }

    private function quotedTimezone(): string
    {
        // The time zone comes from the tenant record and is validated as a PHP time zone identifier
        // when the context is built; quoting keeps it a literal regardless.
        return "'".str_replace("'", "''", $this->timezone)."'";
    }

    // ---------------------------------------------------------------- scope helpers (query builder)

    /** Vehicle/branch-owned records: user branch scope + branch filter on `$branchColumn`. */
    public function scopeBranch(Builder $query, string $branchColumn): Builder
    {
        if ($this->branchIds !== null) {
            $query->whereIn($branchColumn, $this->branchIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $this->branchIds);
        }
        if ($this->filters->branchId !== null) {
            $query->where($branchColumn, $this->filters->branchId);
        }

        return $query;
    }

    /**
     * Work-order-owned records: workshop scope (access) + workshop filter + branch filter on the
     * Work Order's business branch (attribution only, never widens access).
     */
    public function scopeWorkOrder(Builder $query, string $workshopColumn, string $branchColumn): Builder
    {
        if ($this->workshopIds !== null) {
            $query->whereIn($workshopColumn, $this->workshopIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $this->workshopIds);
        }
        if ($this->filters->workshopId !== null) {
            $query->where($workshopColumn, $this->filters->workshopId);
        }
        if ($this->filters->branchId !== null) {
            $query->where($branchColumn, $this->filters->branchId);
        }

        return $query;
    }

    /** Workshop-owned records without a work order (workspaces): workshop scope + filters. */
    public function scopeWorkshop(Builder $query, string $workshopColumn): Builder
    {
        if ($this->workshopIds !== null) {
            $query->whereIn($workshopColumn, $this->workshopIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $this->workshopIds);
        }
        if ($this->filters->workshopId !== null) {
            $query->where($workshopColumn, $this->filters->workshopId);
        }
        if ($this->filters->branchId !== null) {
            $query->whereIn($workshopColumn, fn (Builder $q) => $q->select('id')->from('workshops')
                ->where('tenant_id', $this->tenantId)->where('branch_id', $this->filters->branchId));
        }

        return $query;
    }

    /**
     * Warehouse-owned records: warehouse scope + warehouse filter + branch filter (warehouses of
     * that branch). A warehouse without a branch is reachable only when it is inside the scope.
     */
    public function scopeWarehouse(Builder $query, string $warehouseColumn): Builder
    {
        if ($this->warehouseIds !== null) {
            $query->whereIn($warehouseColumn, $this->warehouseIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $this->warehouseIds);
        }
        if ($this->filters->warehouseId !== null) {
            $query->where($warehouseColumn, $this->filters->warehouseId);
        }
        if ($this->filters->branchId !== null) {
            $query->whereIn($warehouseColumn, fn (Builder $q) => $q->select('id')->from('warehouses')
                ->where('tenant_id', $this->tenantId)->where('branch_id', $this->filters->branchId));
        }

        return $query;
    }

    /** Stable description of the effective access, part of every cache key. */
    public function accessSignature(): array
    {
        $sorted = fn (?array $ids) => $ids === null ? null : collect($ids)->sort()->values()->all();
        $permissions = $this->permissions;
        sort($permissions);
        $modules = $this->modules;
        sort($modules);

        return [
            'tenant' => $this->tenantId,
            'permissions' => $permissions,
            'modules' => $modules,
            'branches' => $sorted($this->branchIds),
            'workshops' => $sorted($this->workshopIds),
            'warehouses' => $sorted($this->warehouseIds),
            'tz' => $this->timezone,
        ];
    }
}
