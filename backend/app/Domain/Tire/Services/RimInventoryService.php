<?php

namespace App\Domain\Tire\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ProductMaster\Models\Product;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Rim inventory per Rim Product (Product of Item Type RIM). A physical rim is a serialized Component
 * Asset of that product (component_assets: serial number, status, warehouse) and its fitment is the
 * Component Installation (vehicle + wheel position, removed_at IS NULL = active) — the same lifecycle
 * (install / remove / replace / repair / sell) as every other serialized component. Tires keep their own
 * records (tires / tire_installations), so a tire and a rim can be on the same wheel position.
 *
 * One classification serves the Rim List counts and the Rim Detail tables, so they always agree:
 *
 *   INSTALLED  an active installation exists (the installation table is the source of truth);
 *   NEW        IN_STOCK and never installed;
 *   USED       was installed before / came back: REMOVED, FAILED, UNDER_REPAIR, RECONDITIONED,
 *              or IN_STOCK after an earlier installation;
 *   (none)     SCRAPPED / SOLD / RETURNED_TO_VENDOR are no longer stock (history is kept).
 *
 * Visibility follows the component register's data scope: a branch-scoped user sees rims installed on
 * vehicles of their branches, rims in their warehouses, and unlocated rims last installed in their
 * branches (the same rule as tires).
 */
class RimInventoryService
{
    public const NEW = 'NEW';

    public const INSTALLED = 'INSTALLED';

    public const USED = 'USED';

    public const CATEGORIES = [self::NEW, self::INSTALLED, self::USED];

    public const TERMINAL = ['SCRAPPED', 'SOLD', 'RETURNED_TO_VENDOR'];

    public function __construct(private readonly DataScopeService $scope) {}

    /** Rim Products (tenant + platform) with their specification and counts — one grouped query. */
    public function productList(string $tenantId, User $user, ?string $search, int $perPage): LengthAwarePaginator
    {
        $counts = DB::query()->fromSub($this->classified($tenantId, $user), 'cr')
            ->selectRaw('product_id')
            ->selectRaw("count(*) filter (where category = 'NEW') as new_qty")
            ->selectRaw("count(*) filter (where category = 'INSTALLED') as installed_qty")
            ->selectRaw("count(*) filter (where category = 'USED') as used_qty")
            ->groupBy('product_id');

        return Product::query()
            ->where('products.product_type', 'RIM')
            ->leftJoinSub($counts, 'c', 'c.product_id', '=', 'products.id')
            ->leftJoin('product_rims as rs', 'rs.product_id', '=', 'products.id')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('products.name', 'ilike', "%{$search}%")
                ->orWhere('products.brand', 'ilike', "%{$search}%")->orWhere('products.sku', 'ilike', "%{$search}%")
                ->orWhere('rs.model', 'ilike', "%{$search}%")))
            ->orderBy('products.brand')->orderBy('products.name')
            ->select(['products.id', 'products.tenant_id', 'products.code', 'products.sku', 'products.name', 'products.brand', 'products.status'])
            ->selectRaw('rs.model, rs.rim_type, rs.diameter_inch, rs.width_inch, rs.bolt_holes, rs.pcd_mm, rs.offset_mm, rs.center_bore_mm, rs.material')
            ->selectRaw('coalesce(c.new_qty, 0)::int as new_qty, coalesce(c.installed_qty, 0)::int as installed_qty, coalesce(c.used_qty, 0)::int as used_qty')
            ->paginate($perPage);
    }

    /** @return array{new_qty: int, installed_qty: int, used_qty: int} */
    public function summary(string $tenantId, User $user, string $productId): array
    {
        $row = DB::query()->fromSub($this->classified($tenantId, $user), 'cr')->where('product_id', $productId)
            ->selectRaw("count(*) filter (where category = 'NEW') as new_qty")
            ->selectRaw("count(*) filter (where category = 'INSTALLED') as installed_qty")
            ->selectRaw("count(*) filter (where category = 'USED') as used_qty")
            ->first();

        return ['new_qty' => (int) $row->new_qty, 'installed_qty' => (int) $row->installed_qty, 'used_qty' => (int) $row->used_qty];
    }

    /** Physical rims of one product in one category (paginated). */
    public function rows(string $tenantId, User $user, string $productId, string $category, int $perPage): LengthAwarePaginator
    {
        return DB::query()->fromSub($this->classified($tenantId, $user), 'cr')
            ->join('component_assets as ca', 'ca.id', '=', 'cr.id')
            ->where('cr.product_id', $productId)->where('cr.category', $category)
            ->leftJoin('component_installations as ci', fn ($j) => $j->on('ci.component_asset_id', '=', 'ca.id')->whereNull('ci.removed_at'))
            ->leftJoin('vehicles as v', 'v.id', '=', 'ci.vehicle_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ca.current_warehouse_id')
            ->orderByRaw('ca.serial_number NULLS LAST')->orderBy('ca.asset_number')
            ->select([
                'ca.id', 'ca.serial_number', 'ca.asset_number', DB::raw('ca.current_status::text as current_status'), 'ca.purchase_date',
                'ci.vehicle_id', 'v.registration_number', 'ci.position_location as position_code', 'ci.installed_at',
                'ca.current_warehouse_id as warehouse_id', 'w.name as warehouse_name',
            ])
            ->paginate($perPage);
    }

    /** Every non-terminal rim asset of the tenant visible to the user, with its category. */
    private function classified(string $tenantId, User $user): Builder
    {
        $query = DB::table('component_assets as a')
            ->join('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('component_installations as ai', fn ($j) => $j->on('ai.component_asset_id', '=', 'a.id')->whereNull('ai.removed_at'))
            ->where('a.tenant_id', $tenantId)->whereNull('a.deleted_at')->where('p.product_type', 'RIM')
            ->whereNotIn('a.current_status', self::TERMINAL)
            ->select(['a.id', 'a.product_id', 'a.current_status'])
            ->selectRaw("CASE
                WHEN ai.id IS NOT NULL THEN 'INSTALLED'
                WHEN a.current_status::text = 'IN_STOCK' AND NOT EXISTS (SELECT 1 FROM component_installations h WHERE h.component_asset_id = a.id) THEN 'NEW'
                ELSE 'USED' END AS category");

        return $this->scopeToUser($query, $tenantId, $user);
    }

    public function scopeToUser(Builder $query, string $tenantId, User $user, string $assets = 'a', string $activeInstallation = 'ai'): Builder
    {
        $branches = $this->scope->allowedBranchIds($user, $tenantId);
        $warehouses = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($branches !== null || $warehouses !== null) {
            $branches ??= [];
            $query->where(fn ($q) => $q
                ->whereIn("{$activeInstallation}.vehicle_id", DB::table('vehicles')->whereIn('branch_id', $branches)->select('id'))
                ->orWhereIn("{$assets}.current_warehouse_id", $warehouses ?? [])
                ->orWhere(fn ($o) => $o->whereNull("{$activeInstallation}.id")->whereNull("{$assets}.current_warehouse_id")
                    ->whereIn(DB::raw("(SELECT lv.branch_id FROM component_installations li JOIN vehicles lv ON lv.id = li.vehicle_id
                        WHERE li.component_asset_id = {$assets}.id ORDER BY li.installed_at DESC LIMIT 1)"), $branches)));
        }

        return $query;
    }
}
