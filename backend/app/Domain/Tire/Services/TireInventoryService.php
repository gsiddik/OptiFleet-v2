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
 *   USED       everything else: was installed before and is not now (REMOVED, RETREAD, REPAIR,
 *              QUARANTINED, UNDER_INSPECTION, or back IN_STOCK after removal). The tire keeps its
 *              own current_status; the category is only a grouping.
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
            ->selectRaw('coalesce(c.new_qty, 0)::int as new_qty, coalesce(c.installed_qty, 0)::int as installed_qty, coalesce(c.used_qty, 0)::int as used_qty')
            ->paginate($perPage);
    }

    /** @return array{new_qty: int, installed_qty: int, used_qty: int} */
    public function summary(string $tenantId, User $user, string $productId): array
    {
        $row = DB::query()->fromSub($this->classifiedTires($tenantId, $user), 'ct')->where('product_id', $productId)
            ->selectRaw("count(*) filter (where category = 'NEW') as new_qty")
            ->selectRaw("count(*) filter (where category = 'INSTALLED') as installed_qty")
            ->selectRaw("count(*) filter (where category = 'USED') as used_qty")
            ->first();

        return ['new_qty' => (int) $row->new_qty, 'installed_qty' => (int) $row->installed_qty, 'used_qty' => (int) $row->used_qty];
    }

    /** Physical tires of one product in one category (paginated), with the columns its table needs. */
    public function rows(string $tenantId, User $user, string $productId, string $category, int $perPage): LengthAwarePaginator
    {
        $page = DB::query()->fromSub($this->classifiedTires($tenantId, $user), 'ct')
            ->join('tires as t', 't.id', '=', 'ct.id')
            ->where('ct.product_id', $productId)->where('ct.category', $category)
            ->leftJoin('vehicles as v', 'v.id', '=', 'ct.active_vehicle_id')
            ->orderBy('t.serial_number')
            ->select(['t.id', 't.serial_number', 't.current_status', 't.current_position', 'ct.active_vehicle_id as vehicle_id', 'v.registration_number', 'v.current_odometer', 'v.engine_hour'])
            ->paginate($perPage);

        $ids = collect($page->items())->pluck('id')->all();
        if ($category === self::USED && $ids !== []) {
            $usage = $this->usage($ids);
            $treads = $this->latestTread($ids);
            $page->getCollection()->transform(fn ($r) => (object) ((array) $r + [
                'usage_km' => $usage[$r->id] ?? null,
                // Installations record odometer only; no hours-meter readings exist per tire.
                'usage_hours' => null,
                'current_tread_depth_mm' => $treads[$r->id] ?? null,
            ]));
        }

        return $page;
    }

    /**
     * Accumulated KM per tire over all its installation periods: each period runs from its
     * installation odometer to the removal odometer (tire_removals) or, after a rotation, to the
     * next installation's odometer. Periods without both readings are skipped (never guessed);
     * a tire with no measurable period has no value.
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
            ->get(['ti.tire_id', 'ti.installation_odometer', 'ti.removed_at', 'tr.removal_odometer']);

        $usage = [];
        foreach ($installations->groupBy('tire_id') as $tireId => $periods) {
            $total = null; // hundredths of a km — integer arithmetic, no float rounding
            $periods = $periods->values();
            foreach ($periods as $i => $period) {
                $end = $period->removal_odometer ?? ($periods[$i + 1]->installation_odometer ?? null);
                if ($period->removed_at === null || $period->installation_odometer === null || $end === null) {
                    continue;
                }
                $distance = $this->hundredths($end) - $this->hundredths($period->installation_odometer);
                if ($distance >= 0) {
                    $total = ($total ?? 0) + $distance;
                }
            }
            if ($total !== null) {
                $usage[$tireId] = intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
            }
        }

        return $usage;
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
            ->select(['tires.id', 'tires.product_id', 'active.vehicle_id as active_vehicle_id'])
            ->selectRaw("CASE
                WHEN active.id IS NOT NULL THEN 'INSTALLED'
                WHEN tires.current_status IN ({$terminal}) THEN NULL
                WHEN tires.current_status IN ('IN_STOCK', 'RESERVED')
                    AND NOT EXISTS (SELECT 1 FROM tire_installations h WHERE h.tire_id = tires.id) THEN 'NEW'
                ELSE 'USED' END AS category");

        $branches = $this->scope->allowedBranchIds($user, $tenantId);
        $warehouses = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($branches !== null || $warehouses !== null) {
            $query->where(fn ($q) => $q
                ->whereIn('tires.current_vehicle_id', DB::table('vehicles')->whereIn('branch_id', $branches ?? [])->select('id'))
                ->orWhereIn('tires.current_warehouse_id', $warehouses ?? []));
        }

        return $query;
    }
}
