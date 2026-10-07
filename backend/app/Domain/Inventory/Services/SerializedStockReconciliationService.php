<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\InstallationStockExit;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds serialized units (component assets / rims, tires) that were installed straight from a warehouse
 * BEFORE installations settled with the ledger, so the ledger may still count them (the stock bug fixed
 * by SerializedStockExitService), and optionally books the missing issue.
 *
 * READ-ONLY by default (plan): nothing is written. Each installation with no exit record, taking the
 * unit's FIRST installation only, falls into exactly one category:
 *   COVERED_BY_WO_ISSUE     a Work Order Part Request issue of that product / warehouse already took a
 *                           unit out and has capacity left for this serial — nothing to correct;
 *   PROVABLE_UNDEDUCTED     the unit came from a posted Goods Receipt (warehouse known), and no issued
 *                           Work Order line covers it — the ledger still holds it. The only actionable class;
 *   AMBIGUOUS_NO_RECEIPT    a component without a Goods Receipt link (initial registration / legacy) — origin unknown;
 *   AMBIGUOUS_TIRE          a standard tire installation with no receipt link and no covering issue — origin unknown;
 *   NOT_WAREHOUSE_ORIGIN    a tire registered directly on a vehicle (initial registration) — never in stock.
 * Only PROVABLE_UNDEDUCTED is ever corrected, and only through apply() with the plan hash of a reviewed
 * plan. The correction is a normal ISSUE movement booked now (never back-dated) whose reason names the
 * original installation date, plus an exit record (reason RECONCILED) — the unique exit row makes a second
 * apply a no-op. A unit the ledger cannot cover (insufficient stock) is skipped and reported, never forced.
 * Nothing is deleted or rewritten; installation history is untouched.
 */
class SerializedStockReconciliationService
{
    public const UNDEDUCTED = 'PROVABLE_UNDEDUCTED';

    public const COVERED = 'COVERED_BY_WO_ISSUE';

    public const AMBIGUOUS_NO_RECEIPT = 'AMBIGUOUS_NO_RECEIPT';

    public const AMBIGUOUS_TIRE = 'AMBIGUOUS_TIRE';

    public const NOT_WAREHOUSE = 'NOT_WAREHOUSE_ORIGIN';

    public function __construct(private readonly InventoryService $inventory, private readonly TenantContext $context) {}

    /** @return array{generated_at: string, tenants: list<array<string, mixed>>, plan_hash: string} */
    public function plan(?string $tenantId = null): array
    {
        $tenants = DB::table('tenants')->when($tenantId, fn ($q) => $q->where('id', $tenantId))->orderBy('code')->get(['id', 'code']);
        $report = $tenants->map(fn ($t) => $this->planTenant($t->id, $t->code))->filter(fn ($r) => $r['summary']['installations'] > 0 || $r['balance'] !== [])->values()->all();

        return ['generated_at' => now()->toIso8601String(), 'tenants' => $report, 'plan_hash' => $this->hash($report)];
    }

    /**
     * Books the missing issue for every PROVABLE_UNDEDUCTED installation of a plan the caller reviewed.
     *
     * @return array{applied: int, skipped: list<array<string, string>>, already_done: int}
     */
    public function apply(string $expectedPlanHash, ?string $tenantId = null, ?string $userId = null): array
    {
        $plan = $this->plan($tenantId);
        if (! hash_equals($plan['plan_hash'], $expectedPlanHash)) {
            throw new InventoryException('The data changed since the plan was reviewed; run the dry-run again and confirm the new plan.');
        }

        $result = ['applied' => 0, 'skipped' => [], 'already_done' => 0];
        foreach ($plan['tenants'] as $tenant) {
            foreach (array_filter($tenant['candidates'], fn ($c) => $c['category'] === self::UNDEDUCTED) as $c) {
                $outcome = $this->correct($tenant['tenant_id'], $c, $userId);
                if ($outcome === 'APPLIED') {
                    $result['applied']++;
                } elseif ($outcome === 'ALREADY_DONE') {
                    $result['already_done']++;
                } else {
                    $result['skipped'][] = ['installation_id' => $c['installation_id'], 'reason' => $outcome];
                }
            }
        }

        return $result;
    }

    private function correct(string $tenantId, array $c, ?string $userId): string
    {
        $previous = $this->context->tenantId();
        $this->context->setTenantId($tenantId);
        try {
            return DB::transaction(function () use ($tenantId, $c, $userId) {
                // Serialise with a concurrent install / reconciliation of the same unit.
                DB::table('component_assets')->where('id', $c['asset_id'])->lockForUpdate()->first();
                if (InstallationStockExit::query()->where('installation_id', $c['installation_id'])->exists()) {
                    return 'ALREADY_DONE';
                }
                $warehouse = Warehouse::query()->findOrFail($c['warehouse_id']);
                $product = Product::query()->findOrFail($c['product_id']);
                try {
                    $this->inventory->issue($warehouse, $product, 1, $c['installation_class'], $c['installation_id'], $userId,
                        "Reconciliation: unit {$c['serial']} was installed on {$c['installed_at']} without a warehouse issue.");
                } catch (InventoryException $e) {
                    return 'INSUFFICIENT_STOCK';
                }
                $movement = StockMovement::query()->where('reference_type', $c['installation_class'])->where('reference_id', $c['installation_id'])->where('movement_type', 'ISSUE')->value('id');
                InstallationStockExit::query()->create([
                    'tenant_id' => $tenantId, 'asset_type' => InstallationStockExit::TYPE_COMPONENT, 'asset_id' => $c['asset_id'], 'installation_id' => $c['installation_id'],
                    'product_id' => $c['product_id'], 'warehouse_id' => $c['warehouse_id'], 'source' => InstallationStockExit::SOURCE_DIRECT, 'reason' => 'RECONCILED',
                    'stock_movement_id' => $movement, 'created_by' => $userId,
                ]);

                return 'APPLIED';
            });
        } finally {
            $this->context->setTenantId($previous);
        }
    }

    /** @return array<string, mixed> */
    private function planTenant(string $tenantId, string $code): array
    {
        $candidates = $this->componentCandidates($tenantId)->concat($this->tireCandidates($tenantId))->sortBy([['installed_at', 'asc'], ['installation_id', 'asc']])->values();
        $candidates = $this->classify($tenantId, $candidates)->all();

        $summary = ['installations' => count($candidates)];
        foreach ([self::UNDEDUCTED, self::COVERED, self::AMBIGUOUS_NO_RECEIPT, self::AMBIGUOUS_TIRE, self::NOT_WAREHOUSE] as $category) {
            $summary[$category] = count(array_filter($candidates, fn ($c) => $c['category'] === $category));
        }

        return ['tenant_id' => $tenantId, 'tenant_code' => $code, 'summary' => $summary, 'candidates' => $candidates, 'balance' => $this->balance($tenantId, $candidates)];
    }

    /** First installation of each component asset with no exit record. */
    private function componentCandidates(string $tenantId): Collection
    {
        return DB::table('component_installations as ci')
            ->join('component_assets as ca', 'ca.id', '=', 'ci.component_asset_id')
            ->leftJoin('goods_receipt_items as gri', 'gri.id', '=', 'ca.goods_receipt_item_id')
            ->leftJoin('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'ci.vehicle_id')
            ->where('ci.tenant_id', $tenantId)
            ->whereNotExists(fn ($q) => $q->from('installation_stock_exits as e')->whereColumn('e.installation_id', 'ci.id'))
            ->whereRaw('ci.id = (select x.id from component_installations x where x.component_asset_id = ca.id order by x.installed_at, x.id limit 1)')
            ->orderBy('ci.installed_at')
            ->get(['ci.id as installation_id', 'ci.component_asset_id as asset_id', 'ci.work_order_id', 'ci.installed_at', 'ci.performed_by', 'ca.product_id',
                DB::raw("coalesce(ca.serial_number, ca.asset_number) as serial"), 'ca.goods_receipt_item_id', 'gr.warehouse_id', 'v.registration_number'])
            ->map(fn ($r) => (array) $r + ['kind' => 'COMPONENT', 'installation_class' => \App\Domain\ComponentAsset\Models\ComponentInstallation::class, 'initial' => false]);
    }

    /** First installation of each tire with no exit record. */
    private function tireCandidates(string $tenantId): Collection
    {
        return DB::table('tire_installations as ti')
            ->join('tires as t', 't.id', '=', 'ti.tire_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'ti.vehicle_id')
            ->where('ti.tenant_id', $tenantId)
            ->whereNotExists(fn ($q) => $q->from('installation_stock_exits as e')->whereColumn('e.installation_id', 'ti.id'))
            ->whereRaw('ti.id = (select x.id from tire_installations x where x.tire_id = t.id order by x.installed_at, x.id limit 1)')
            ->orderBy('ti.installed_at')
            ->get(['ti.id as installation_id', 'ti.tire_id as asset_id', 'ti.work_order_id', 'ti.installed_at', 'ti.performed_by', 't.product_id', 't.serial_number as serial',
                'ti.installation_source', 'v.registration_number'])
            ->map(fn ($r) => (array) $r + ['kind' => 'TIRE', 'warehouse_id' => null, 'goods_receipt_item_id' => null, 'installation_class' => \App\Domain\Tire\Models\TireInstallation::class,
                'initial' => $r->installation_source === 'INITIAL_REGISTRATION']);
    }

    /** Work Order issue coverage first (capacity is consumed in installation order), then the origin evidence. */
    private function classify(string $tenantId, Collection $candidates): Collection
    {
        $capacity = []; // planned_part_id => remaining uncovered issued units

        return $candidates->map(function (array $c) use ($tenantId, &$capacity) {
            $c['evidence'] = null;
            if ($c['initial']) {
                return $c + ['category' => self::NOT_WAREHOUSE, 'evidence' => 'Registered directly on the vehicle (initial registration).'];
            }
            if ($c['work_order_id']) {
                $lines = DB::table('work_order_planned_parts')->where('tenant_id', $tenantId)->where('work_order_id', $c['work_order_id'])->where('product_id', $c['product_id'])
                    ->when($c['warehouse_id'], fn ($q, $w) => $q->where('warehouse_id', $w))
                    ->where(fn ($q) => $q->whereNull('stock_condition')->orWhere('stock_condition', '!=', 'USED'))->where('issued_quantity', '>', 0)->orderBy('created_at')->orderBy('id')
                    ->get(['id', 'issued_quantity', 'returned_quantity', 'warehouse_id']);
                foreach ($lines as $line) {
                    $capacity[$line->id] ??= floor((float) $line->issued_quantity - (float) $line->returned_quantity)
                        - (int) DB::table('installation_stock_exits')->where('planned_part_id', $line->id)->count();
                    if ($capacity[$line->id] >= 1) {
                        $capacity[$line->id]--;

                        return $c + ['category' => self::COVERED, 'evidence' => "Work Order part line {$line->id} was issued (ledger already reduced)."];
                    }
                }
            }
            if ($c['kind'] === 'COMPONENT' && $c['warehouse_id']) {
                return $c + ['category' => self::UNDEDUCTED, 'evidence' => 'Unit generated by a posted Goods Receipt; no issued Work Order line covers it; no exit record.'];
            }

            return $c + ($c['kind'] === 'COMPONENT'
                ? ['category' => self::AMBIGUOUS_NO_RECEIPT, 'evidence' => 'No Goods Receipt link: the unit\'s origin cannot be proven.']
                : ['category' => self::AMBIGUOUS_TIRE, 'evidence' => 'Tire serials are not linked to a Goods Receipt: whether the ledger held this unit cannot be proven.']);
        });
    }

    /** Ledger vs the physical units registered IN_STOCK, per warehouse and component product — only where they differ. */
    private function balance(string $tenantId, array $candidates): array
    {
        $physical = DB::table('component_assets')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('current_status', 'IN_STOCK')->whereNotNull('current_warehouse_id')
            ->groupBy('current_warehouse_id', 'product_id')->selectRaw('current_warehouse_id as warehouse_id, product_id, count(*) as units')->get()
            ->keyBy(fn ($r) => $r->warehouse_id.'|'.$r->product_id);
        $products = DB::table('component_assets')->where('tenant_id', $tenantId)->whereNull('deleted_at')->distinct()->pluck('product_id');
        $undeducted = collect($candidates)->where('category', self::UNDEDUCTED)->groupBy(fn ($c) => $c['warehouse_id'].'|'.$c['product_id'])->map->count();

        return DB::table('warehouse_stocks as ws')->join('products as p', 'p.id', '=', 'ws.product_id')->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->where('ws.tenant_id', $tenantId)->whereIn('ws.product_id', $products)->get(['ws.warehouse_id', 'ws.product_id', 'ws.quantity_on_hand', 'p.name as product', 'w.name as warehouse'])
            ->map(function ($r) use ($physical, $undeducted) {
                $key = $r->warehouse_id.'|'.$r->product_id;
                $units = (int) ($physical[$key]->units ?? 0);

                return ['warehouse_id' => $r->warehouse_id, 'warehouse' => $r->warehouse, 'product_id' => $r->product_id, 'product' => $r->product,
                    'ledger_on_hand' => (string) $r->quantity_on_hand, 'registered_in_stock_units' => $units,
                    'difference' => number_format((float) $r->quantity_on_hand - $units, 4, '.', ''), 'explained_by_provable_undeducted' => (int) ($undeducted[$key] ?? 0)];
            })->filter(fn ($b) => (float) $b['difference'] !== 0.0)->values()->all();
    }

    private function hash(array $report): string
    {
        $actionable = collect($report)->flatMap(fn ($t) => collect($t['candidates'])->where('category', self::UNDEDUCTED)->map(fn ($c) => $c['installation_id']))->sort()->values()->all();

        return hash('sha256', json_encode($actionable));
    }
}
