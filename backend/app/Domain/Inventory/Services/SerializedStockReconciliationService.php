<?php

namespace App\Domain\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds serialized units (component assets / rims, tires) that were installed straight from a warehouse
 * BEFORE installations settled with the ledger, so the ledger may still count them (the stock bug fixed
 * by SerializedStockExitService).
 *
 * READ-ONLY: nothing here writes. Corrections go through StockReconciliationAdjustmentService (proposal ->
 * approval -> apply). Each installation with no exit record, taking the unit's FIRST installation only, falls
 * into exactly one category:
 *   COVERED_BY_WO_ISSUE     a Work Order Part Request issue of that product / warehouse already took a
 *                           unit out and has capacity left for this serial — nothing to correct;
 *   RESOLVED_BY_OPNAME      a posted stock opname of that warehouse / product is LATER than the installation: the
 *                           physical count already settled the quantity — no adjustment may be proposed;
 *   PROVABLE_UNDEDUCTED     the unit came from a posted Goods Receipt (warehouse known), no issued Work Order
 *                           line covers it and no later opname settled it — the only actionable class;
 *   AMBIGUOUS_NO_RECEIPT    a component without a Goods Receipt link (initial registration / legacy) — origin unknown;
 *   AMBIGUOUS_TIRE          a standard tire installation with no receipt link and no covering issue — origin unknown;
 *   NOT_WAREHOUSE_ORIGIN    a tire registered directly on a vehicle (initial registration) — never in stock.
 * Installations already corrected through an approved adjustment have an exit record and are counted separately
 * (CORRECTED_BY_RECONCILIATION). Opname evidence proves the PHYSICAL QUANTITY at a point in time — never the
 * historical cause; ambiguous tires are only ever reported with that evidence and never adjusted automatically.
 */
class SerializedStockReconciliationService
{
    public const UNDEDUCTED = 'PROVABLE_UNDEDUCTED';

    public const COVERED = 'COVERED_BY_WO_ISSUE';

    public const AMBIGUOUS_NO_RECEIPT = 'AMBIGUOUS_NO_RECEIPT';

    public const AMBIGUOUS_TIRE = 'AMBIGUOUS_TIRE';

    public const NOT_WAREHOUSE = 'NOT_WAREHOUSE_ORIGIN';

    public const RESOLVED_BY_OPNAME = 'RESOLVED_BY_OPNAME';

    public const CORRECTED = 'CORRECTED_BY_RECONCILIATION';

    /** @return array{generated_at: string, tenants: list<array<string, mixed>>} */
    public function plan(?string $tenantId = null): array
    {
        $tenants = DB::table('tenants')->when($tenantId, fn ($q) => $q->where('id', $tenantId))->orderBy('code')->get(['id', 'code']);
        $report = $tenants->map(fn ($t) => $this->planTenant($t->id, $t->code))->filter(fn ($r) => $r['summary']['installations'] > 0 || $r['balance'] !== [] || $r['summary'][self::CORRECTED] > 0)->values()->all();

        return ['generated_at' => now()->toIso8601String(), 'tenants' => $report];
    }

    /** The CURRENT classification of one installation of a tenant (null when it is no longer a candidate, e.g. it already has an exit record). */
    public function evaluate(string $tenantId, string $installationId): ?array
    {
        return collect($this->planTenant($tenantId, '')['candidates'])->firstWhere('installation_id', $installationId);
    }

    /** @return array<string, mixed> */
    private function planTenant(string $tenantId, string $code): array
    {
        $candidates = $this->componentCandidates($tenantId)->concat($this->tireCandidates($tenantId))->sortBy([['installed_at', 'asc'], ['installation_id', 'asc']])->values();
        $candidates = $this->withOpnameEvidence($tenantId, $this->classify($tenantId, $candidates))->all();

        $summary = ['installations' => count($candidates)];
        foreach ([self::UNDEDUCTED, self::COVERED, self::RESOLVED_BY_OPNAME, self::AMBIGUOUS_NO_RECEIPT, self::AMBIGUOUS_TIRE, self::NOT_WAREHOUSE] as $category) {
            $summary[$category] = count(array_filter($candidates, fn ($c) => $c['category'] === $category));
        }

        $summary[self::CORRECTED] = DB::table('installation_stock_exits')->where('tenant_id', $tenantId)->where('reason', 'RECONCILED')->count();

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

    /**
     * Opname evidence per candidate: the latest POSTED opname of the warehouse / product with its number, snapshot
     * time, system snapshot, physical count and variance. A component candidate later settled by a posted opname
     * becomes RESOLVED_BY_OPNAME. Tires have no warehouse: they get the evidence of EVERY warehouse holding that
     * product (never an adjustment, never a guessed receipt link).
     */
    private function withOpnameEvidence(string $tenantId, Collection $candidates): Collection
    {
        return $candidates->map(function (array $c) use ($tenantId) {
            $c['opname'] = null;
            $c['warehouse_evidence'] = [];
            if ($c['kind'] === 'TIRE' && $c['category'] === self::AMBIGUOUS_TIRE) {
                $c['warehouse_evidence'] = $this->warehouseEvidence($tenantId, $c['product_id']);

                return $c;
            }
            if (! $c['warehouse_id']) {
                return $c;
            }
            $c['opname'] = $this->latestPostedOpname($tenantId, $c['warehouse_id'], $c['product_id']);
            if ($c['category'] === self::UNDEDUCTED && $c['opname'] && $c['opname']['posted_at'] > $c['installed_at']) {
                $c['category'] = self::RESOLVED_BY_OPNAME;
                $c['evidence'] = "Posted stock opname {$c['opname']['opname_number']} ({$c['opname']['posted_at']}) is later than the installation: the physical count already settled this quantity.";
            }

            return $c;
        });
    }

    /** @return list<array<string, mixed>> */
    private function warehouseEvidence(string $tenantId, string $productId): array
    {
        return DB::table('warehouse_stocks as ws')->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->where('ws.tenant_id', $tenantId)->where('ws.product_id', $productId)->orderBy('w.name')
            ->get(['ws.warehouse_id', 'w.name as warehouse', 'ws.quantity_on_hand'])
            ->map(fn ($r) => ['warehouse_id' => $r->warehouse_id, 'warehouse' => $r->warehouse, 'system_quantity' => number_format((float) $r->quantity_on_hand, 4, '.', ''),
                'opname' => $this->latestPostedOpname($tenantId, $r->warehouse_id, $productId)])->all();
    }

    /** @return array<string, mixed>|null */
    public function latestPostedOpname(string $tenantId, string $warehouseId, string $productId): ?array
    {
        $row = DB::table('stock_opname_items as i')->join('stock_opnames as o', 'o.id', '=', 'i.stock_opname_id')
            ->where('o.tenant_id', $tenantId)->where('o.warehouse_id', $warehouseId)->where('i.product_id', $productId)
            ->where('o.status', 'POSTED')->whereNotNull('i.physical_quantity')->orderByDesc('o.posted_at')->orderByDesc('o.id')
            ->first(['o.id', 'o.opname_number', 'o.created_at', 'o.posted_at', 'i.system_quantity', 'i.physical_quantity']);
        if (! $row) {
            return null;
        }
        $base = DB::table('stock_movements')->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('product_id', $productId)
            ->where(fn ($q) => $q->where('movement_type', '!=', 'STOCK_OPNAME')->orWhere('reference_id', '!=', $row->id)->orWhereNull('reference_id'));

        return [
            'opname_id' => $row->id, 'opname_number' => $row->opname_number,
            'snapshot_at' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String(), 'posted_at' => \Illuminate\Support\Carbon::parse($row->posted_at)->toIso8601String(),
            'system_quantity' => number_format((float) $row->system_quantity, 4, '.', ''), 'physical_quantity' => number_format((float) $row->physical_quantity, 4, '.', ''),
            'variance' => number_format((float) $row->physical_quantity - (float) $row->system_quantity, 4, '.', ''),
            // The posted variance was applied to the on-hand of the POSTING time: movements between the snapshot and the posting make it stale.
            'snapshot_stale' => (clone $base)->where('created_at', '>', $row->created_at)->where('created_at', '<', $row->posted_at)->exists(),
            'movements_after' => (clone $base)->where('created_at', '>', $row->posted_at)->count(),
            'proves' => 'PHYSICAL_QUANTITY_AT_THAT_TIME',
        ];
    }
}
