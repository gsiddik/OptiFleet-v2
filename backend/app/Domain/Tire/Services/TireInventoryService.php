<?php

namespace App\Domain\Tire\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ProductMaster\Models\Product;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Tire inventory per Tire Product (Product of Item Type TIRE), aggregated from the physical tires
 * (serial numbers) of that product — the single classification used by both the product-level
 * Tire List counts and the Tire Detail inventory tables, so the two always agree.
 *
 *   INSTALLED  the tire has an active installation (tire_installations.removed_at IS NULL) —
 *              the installation table is the source of truth, not the status string;
 *   (none)     terminal states SCRAPPED / SOLD / LOST are not stock;
 *   NEW        never installed and IN_STOCK / RESERVED;
 *   USED       everything else: was installed before and is not now (REMOVED, REUSE, HOLD,
 *              RETREAD, REPAIR, QUARANTINED, UNDER_INSPECTION). The tire keeps its own
 *              current_status; the category is only a grouping — visibility, not availability.
 *              Of the used tires only REUSE is reusable stock (reusable_qty).
 *
 * New Stock counts serialised physical tires, not warehouse quantity: a Tire product may hold
 * warehouse quantity whose serials are not registered yet — those are not shown as tires.
 */
class TireInventoryService
{
    public const NEW = 'NEW';

    public const INSTALLED = 'INSTALLED';

    public const USED = 'USED';

    public const CATEGORIES = [self::NEW, self::INSTALLED, self::USED];

    private const TERMINAL = ['SCRAPPED', 'SOLD', 'LOST'];

    public function __construct(private readonly DataScopeService $scope) {}

    /** Tire Products (tenant + platform) with their counts — one grouped query, no N+1. */
    public function productList(string $tenantId, User $user, ?string $search, int $perPage): LengthAwarePaginator
    {
        $counts = DB::query()->fromSub($this->classifiedTires($tenantId, $user), 'ct')
            ->selectRaw('product_id')
            ->selectRaw("count(*) filter (where category = 'NEW') as new_qty")
            ->selectRaw("count(*) filter (where category = 'INSTALLED') as installed_qty")
            ->selectRaw("count(*) filter (where category = 'USED') as used_qty")
            ->selectRaw("count(*) filter (where category = 'USED' and current_status = 'REUSE') as reusable_qty")
            ->groupBy('product_id');

        return Product::query()
            ->where('products.product_type', 'TIRE')
            ->leftJoinSub($counts, 'c', 'c.product_id', '=', 'products.id')
            ->leftJoin('product_tires as ts', 'ts.product_id', '=', 'products.id')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('products.name', 'ilike', "%{$search}%")
                ->orWhere('products.brand', 'ilike', "%{$search}%")->orWhere('products.sku', 'ilike', "%{$search}%")))
            ->orderBy('products.brand')->orderBy('products.name')
            ->select(['products.id', 'products.tenant_id', 'products.code', 'products.sku', 'products.name', 'products.brand', 'products.status'])
            ->selectRaw('ts.rim_diameter_inch, ts.tire_size_computed')
            ->selectRaw('coalesce(c.new_qty, 0)::int as new_qty, coalesce(c.installed_qty, 0)::int as installed_qty, coalesce(c.used_qty, 0)::int as used_qty, coalesce(c.reusable_qty, 0)::int as reusable_qty')
            ->paginate($perPage);
    }

    /** @return array{new_qty: int, installed_qty: int, used_qty: int, reusable_qty: int} reusable_qty = used tires available for installation (REUSE) */
    public function summary(string $tenantId, User $user, string $productId): array
    {
        $row = DB::query()->fromSub($this->classifiedTires($tenantId, $user), 'ct')->where('product_id', $productId)
            ->selectRaw("count(*) filter (where category = 'NEW') as new_qty")
            ->selectRaw("count(*) filter (where category = 'INSTALLED') as installed_qty")
            ->selectRaw("count(*) filter (where category = 'USED') as used_qty")
            ->selectRaw("count(*) filter (where category = 'USED' and current_status = 'REUSE') as reusable_qty")
            ->first();

        return ['new_qty' => (int) $row->new_qty, 'installed_qty' => (int) $row->installed_qty, 'used_qty' => (int) $row->used_qty, 'reusable_qty' => (int) $row->reusable_qty];
    }

    /** Physical tires of one product in one category (paginated), with the columns its table needs. */
    public function rows(string $tenantId, User $user, string $productId, string $category, int $perPage): LengthAwarePaginator
    {
        $page = DB::query()->fromSub($this->classifiedTires($tenantId, $user), 'ct')
            ->join('tires as t', 't.id', '=', 'ct.id')
            ->where('ct.product_id', $productId)->where('ct.category', $category)
            ->leftJoin('vehicles as v', 'v.id', '=', 'ct.active_vehicle_id')
            ->orderBy('t.serial_number')
            ->select(['t.id', 't.serial_number', 't.current_status', 't.current_position', 't.manufacture_date_code', 't.purchase_date', 'ct.active_vehicle_id as vehicle_id', 'v.registration_number', 'v.current_odometer', 'v.engine_hour'])
            ->paginate($perPage);

        $ids = collect($page->items())->pluck('id')->all();
        if ($category === self::USED && $ids !== []) {
            $usage = $this->usage($ids);
            $hours = $this->usageHours($ids);
            $treads = $this->latestTread($ids);
            $page->getCollection()->transform(fn ($r) => (object) ((array) $r + [
                'usage_km' => $usage[$r->id] ?? null,
                'usage_hours' => $hours[$r->id] ?? null,
                'current_tread_depth_mm' => $treads[$r->id] ?? null,
            ]));
        }

        return $page;
    }

    /**
     * Accumulated KM per tire over all its installation periods. A period starts at its
     * installation odometer and is measured up to the latest known reading inside it: the removal
     * odometer (tire_removals), the next installation's odometer after a rotation, or the KM of an
     * applied Tire Operation on that tire (e.g. an inspection). Usage therefore first appears at a
     * tire's first operation/removal and every later reading adds only its delta; periods without
     * a reading are skipped (never guessed), and a tire with no measurable period has no value.
     *
     * @param  list<string>  $tireIds
     * @return array<string, string>
     */
    public function usage(array $tireIds): array
    {
        $installations = DB::table('tire_installations as ti')
            ->leftJoin('tire_removals as tr', 'tr.tire_installation_id', '=', 'ti.id')
            ->whereIn('ti.tire_id', $tireIds)
            // A rotation closes one period and opens the next in the same instant: order the closed
            // period first (removed_at NULLS LAST, then the lower odometer) so "next" is always the
            // follow-up installation.
            ->orderBy('ti.tire_id')->orderBy('ti.installed_at')->orderByRaw('ti.removed_at ASC NULLS LAST')
            ->orderByRaw('ti.installation_odometer ASC NULLS FIRST')->orderBy('ti.created_at')
            ->get(['ti.tire_id', 'ti.installed_at', 'ti.installation_odometer', 'ti.removed_at', 'tr.removal_odometer']);

        $readings = $this->operationReadings($tireIds);

        $usage = [];
        foreach ($installations->groupBy('tire_id') as $tireId => $periods) {
            $total = null; // hundredths of a km — integer arithmetic, no float rounding
            $periods = $periods->values();
            foreach ($periods as $i => $period) {
                if ($period->installation_odometer === null) {
                    continue;
                }
                $start = $this->hundredths($period->installation_odometer);
                // A removal or the follow-up installation (rotation) closes the period explicitly;
                // otherwise the latest operation reading inside the period is its end so far.
                $explicit = $period->removal_odometer ?? ($period->removed_at !== null ? ($periods[$i + 1]->installation_odometer ?? null) : null);
                $end = $explicit !== null ? $this->hundredths($explicit) : null;
                if ($end === null) {
                    $from = strtotime((string) $period->installed_at);
                    $until = $period->removed_at !== null ? strtotime((string) $period->removed_at) : PHP_INT_MAX;
                    foreach ($readings[$tireId] ?? [] as [$at, $odometer]) {
                        if ($at >= $from && $at <= $until) {
                            $end = max($end ?? $odometer, $odometer);
                        }
                    }
                }
                if ($end === null || $end < $start) {
                    continue;
                }
                $total = ($total ?? 0) + ($end - $start);
            }
            if ($total !== null) {
                $usage[$tireId] = intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
            }
        }

        return $usage;
    }

    /**
     * Accumulated Usage Time (hours, "1234.50") per tire — the same periods and rule as usage(),
     * measured in date/time instead of KM. A period starts at its installation date/time (the Last
     * Known Installation Date + Time of the first tire on a position, or the Tire Operations
     * Date + Time of the replacement / rotation that put the tire there) and runs to the latest
     * known event inside it: the removal or follow-up installation, otherwise the latest applied
     * Tire Operation on the tire. So the first value appears at the tire's first operation and
     * every later operation adds only the time since the previous one.
     *
     * @param  list<string>  $tireIds
     * @return array<string, string>
     */
    public function usageHours(array $tireIds): array
    {
        $installations = DB::table('tire_installations as ti')
            ->whereIn('ti.tire_id', $tireIds)
            ->orderBy('ti.tire_id')->orderBy('ti.installed_at')->orderByRaw('ti.removed_at ASC NULLS LAST')->orderBy('ti.created_at')
            ->get(['ti.tire_id', 'ti.installed_at', 'ti.removed_at']);
        $readings = $this->operationReadings($tireIds);

        $usage = [];
        foreach ($installations->groupBy('tire_id') as $tireId => $periods) {
            $seconds = null;
            foreach ($periods as $period) {
                $start = strtotime((string) $period->installed_at);
                $end = $period->removed_at !== null ? strtotime((string) $period->removed_at) : null;
                if ($end === null) {
                    foreach ($readings[$tireId] ?? [] as [$at]) {
                        if ($at >= $start) {
                            $end = max($end ?? $at, $at);
                        }
                    }
                }
                if ($end === null || $end <= $start) {
                    continue;
                }
                $seconds = ($seconds ?? 0) + ($end - $start);
            }
            if ($seconds !== null) {
                $hundredths = intdiv($seconds * 100, 3600); // hundredths of an hour, integer arithmetic
                $usage[$tireId] = intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
            }
        }

        return $usage;
    }

    /**
     * KM readings of applied (executed) Tire Operations per tire.
     *
     * @param  list<string>  $tireIds
     * @return array<string, list<array{0: int, 1: int}>> tire id => [[operated_at epoch seconds, odometer in hundredths], …]
     */
    private function operationReadings(array $tireIds): array
    {
        $rows = DB::table('tire_operation_items as oi')
            ->join('tire_operations as o', 'o.id', '=', 'oi.tire_operation_id')
            ->whereIn('oi.tire_id', $tireIds)->whereNotNull('oi.applied_at')->whereNull('o.cancelled_at')
            ->get(['oi.tire_id', 'o.operated_at', 'o.odometer']);

        $readings = [];
        foreach ($rows as $row) {
            $readings[$row->tire_id][] = [strtotime((string) $row->operated_at), $this->hundredths($row->odometer)];
        }

        return $readings;
    }

    /** "12500.75" (numeric(12,2) from the database) → 1250075. */
    private function hundredths(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * Latest measured tread depth per tire (inspection, registration baseline or retread/repair
     * inspection — all stored as tire_inspections).
     *
     * @param  list<string>  $tireIds
     * @return array<string, string>
     */
    public function latestTread(array $tireIds): array
    {
        return DB::table('tire_inspections')->whereIn('tire_id', $tireIds)->whereNotNull('tread_depth_mm')
            ->selectRaw('DISTINCT ON (tire_id) tire_id, tread_depth_mm')
            ->orderBy('tire_id')->orderByDesc('inspected_at')->orderByDesc('created_at')
            ->pluck('tread_depth_mm', 'tire_id')->all();
    }

    /**
     * Every non-deleted physical tire of the tenant visible to the user, with its inventory
     * category and active vehicle. The same data-scope rule as the tire index applies.
     */
    private function classifiedTires(string $tenantId, User $user): Builder
    {
        $terminal = "'".implode("','", self::TERMINAL)."'";
        $query = DB::table('tires')
            ->leftJoin('tire_installations as active', fn ($j) => $j->on('active.tire_id', '=', 'tires.id')->whereNull('active.removed_at'))
            ->where('tires.tenant_id', $tenantId)->whereNull('tires.deleted_at')
            ->select(['tires.id', 'tires.product_id', 'tires.current_status', 'active.vehicle_id as active_vehicle_id'])
            ->selectRaw("CASE
                WHEN active.id IS NOT NULL THEN 'INSTALLED'
                WHEN tires.current_status IN ({$terminal}) THEN NULL
                WHEN tires.current_status IN ('IN_STOCK', 'RESERVED')
                    AND NOT EXISTS (SELECT 1 FROM tire_installations h WHERE h.tire_id = tires.id) THEN 'NEW'
                ELSE 'USED' END AS category");

        return $this->scopeToUser($query, $tenantId, $user);
    }

    /**
     * The tire index's data-scope rule on a query over `tires`: with a restricted branch or
     * warehouse scope, only tires on a vehicle of an allowed branch or in an allowed warehouse.
     */
    public function scopeToUser(Builder $query, string $tenantId, User $user, string $tires = 'tires'): Builder
    {
        $branches = $this->scope->allowedBranchIds($user, $tenantId);
        $warehouses = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($branches !== null || $warehouses !== null) {
            $query->where(fn ($q) => $q
                ->whereIn("{$tires}.current_vehicle_id", DB::table('vehicles')->whereIn('branch_id', $branches ?? [])->select('id'))
                ->orWhereIn("{$tires}.current_warehouse_id", $warehouses ?? []));
        }

        return $query;
    }
}
