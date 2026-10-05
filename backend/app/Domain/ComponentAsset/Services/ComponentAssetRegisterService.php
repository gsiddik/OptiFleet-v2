<?php

namespace App\Domain\ComponentAsset\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Procurement\Models\GoodsReceiptItem;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\PurchaseReturn;
use App\Domain\Procurement\Services\ProcurementException;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Services\TireInventoryService;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Inventory → Component Assets: the register of physical items that keep resale / asset value and
 * need an individual identity (Product → Physical Asset → Serial / Asset# → Status → Location →
 * History).
 *
 *  - Non-tire products with Track Serial Number (the existing product flag; never consumables):
 *    one component_assets row per unit actually received by a Goods Receipt, with a generated
 *    Asset# — idempotent per receipt line (unique goods_receipt_item_id + receipt_sequence).
 *  - Tires (owner decision): the existing physical Tire records are listed as they are — the tire
 *    Serial Number is its Asset#, no copy rows — so the tire list and this register point to the
 *    same physical tire.
 *
 * Location is derived from the current relations, never a stored text: installed → the vehicle
 * of the active installation; SOLD / RETURNED_TO_VENDOR → none; otherwise the warehouse.
 */
class ComponentAssetRegisterService
{
    public const KIND_COMPONENT = 'COMPONENT';

    public const KIND_TIRE = 'TIRE';

    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TireInventoryService $tires,
        private readonly NumberSequenceService $sequences,
        private readonly EntitlementService $entitlements,
        private readonly PermissionService $permissions,
    ) {}

    /** Products whose received units become Component Assets (tires are registered as Tire records). */
    public function eligible(Product $product): bool
    {
        return (bool) $product->track_serial_number && ! in_array($product->product_type, ['TIRE', 'CONSUMABLE'], true);
    }

    // ------------------------------------------------------------------ register (list)

    /**
     * @param  array{status?: ?string, kind?: ?string, search?: ?string, component_group_id?: ?string}  $filters
     */
    public function paginate(string $tenantId, User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $sources = $this->components($tenantId, $user);
        if (($filters['kind'] ?? null) !== self::KIND_COMPONENT && $this->includesTires($tenantId, $user)) {
            $sources = ($filters['kind'] ?? null) === self::KIND_TIRE ? $this->tireRows($tenantId, $user) : $sources->unionAll($this->tireRows($tenantId, $user));
        }

        $query = DB::query()->fromSub($sources, 'a')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('a.status', $status))
            ->when($filters['component_group_id'] ?? null, fn ($q, $group) => $q->whereRaw('? = ANY(a.component_group_ids)', [$group]))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w->where('a.serial_number', 'ilike', "%{$search}%")
                ->orWhere('a.asset_number', 'ilike', "%{$search}%")->orWhere('a.product_name', 'ilike', "%{$search}%")
                ->orWhere('a.registration_number', 'ilike', "%{$search}%")))
            ->orderByDesc('a.created_at')->orderBy('a.asset_number');

        $page = $query->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row)));

        return $page;
    }

    /** Tires are listed when the tenant has the Tire module and the user may see tires. */
    private function includesTires(string $tenantId, User $user): bool
    {
        return $this->entitlements->tenantHasModule($tenantId, 'TIRE') && $this->permissions->userHasPermission($user, 'tire.view', $tenantId);
    }

    /** Group names / ids of the product (Product → Component Groups), else the asset's own group. */
    private function groupColumns(string $productColumn, ?string $fallbackGroupColumn): array
    {
        $fallbackName = $fallbackGroupColumn ? "(SELECT g.name FROM component_groups g WHERE g.id = {$fallbackGroupColumn})" : 'NULL';
        $fallbackIds = $fallbackGroupColumn ? "CASE WHEN {$fallbackGroupColumn} IS NULL THEN ARRAY[]::uuid[] ELSE ARRAY[{$fallbackGroupColumn}] END" : 'ARRAY[]::uuid[]';

        return [
            DB::raw("COALESCE((SELECT string_agg(g.name, ', ' ORDER BY g.name) FROM product_component_groups pg JOIN component_groups g ON g.id = pg.component_group_id WHERE pg.product_id = {$productColumn}), {$fallbackName}) AS component_group"),
            DB::raw("COALESCE((SELECT array_agg(pg.component_group_id) FROM product_component_groups pg WHERE pg.product_id = {$productColumn}), {$fallbackIds}) AS component_group_ids"),
        ];
    }

    private function components(string $tenantId, User $user): Builder
    {
        $query = DB::table('component_assets as ca')
            ->leftJoin('products as p', 'p.id', '=', 'ca.product_id')
            ->leftJoin('component_installations as ci', fn ($j) => $j->on('ci.component_asset_id', '=', 'ca.id')->whereNull('ci.removed_at'))
            ->leftJoin('vehicles as v', 'v.id', '=', 'ci.vehicle_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ca.current_warehouse_id')
            ->where('ca.tenant_id', $tenantId)->whereNull('ca.deleted_at')
            ->select([
                DB::raw("'".self::KIND_COMPONENT."' AS kind"), 'ca.id', DB::raw('ca.current_status::text AS status'), 'ca.serial_number', 'ca.asset_number',
                'ca.product_id', 'p.name as product_name', ...$this->groupColumns('ca.product_id', 'ca.component_group_id'),
                'ci.vehicle_id', 'v.registration_number', 'ca.current_warehouse_id as warehouse_id', 'w.name as warehouse_name', 'ca.created_at',
            ]);

        $branches = $this->scope->allowedBranchIds($user, $tenantId);
        $warehouses = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($branches !== null || $warehouses !== null) {
            $query->where(fn ($q) => $q->whereIn('v.branch_id', $branches ?? [])->orWhereIn('ca.current_warehouse_id', $warehouses ?? []));
        }

        return $query;
    }

    private function tireRows(string $tenantId, User $user): Builder
    {
        $query = DB::table('tires')
            ->leftJoin('products as p', 'p.id', '=', 'tires.product_id')
            ->leftJoin('tire_installations as ti', fn ($j) => $j->on('ti.tire_id', '=', 'tires.id')->whereNull('ti.removed_at'))
            ->leftJoin('vehicles as v', 'v.id', '=', 'ti.vehicle_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'tires.current_warehouse_id')
            ->where('tires.tenant_id', $tenantId)->whereNull('tires.deleted_at')
            ->select([
                DB::raw("'".self::KIND_TIRE."' AS kind"), 'tires.id', DB::raw('tires.current_status::text AS status'), 'tires.serial_number', DB::raw('tires.serial_number AS asset_number'),
                'tires.product_id', 'p.name as product_name', ...$this->groupColumns('tires.product_id', null),
                'ti.vehicle_id', 'v.registration_number', 'tires.current_warehouse_id as warehouse_id', 'w.name as warehouse_name', 'tires.created_at',
            ]);

        return $this->tires->scopeToUser($query, $tenantId, $user);
    }

    /** One register row; the location follows the status rule and comes from the relations above. */
    public function present(object $row): array
    {
        $gone = in_array($row->status, ComponentAsset::NO_LOCATION, true);
        $onVehicle = ! $gone && $row->vehicle_id !== null;
        $inWarehouse = ! $gone && ! $onVehicle && $row->warehouse_id !== null;

        return [
            'kind' => $row->kind,
            'id' => $row->id,
            'status' => $row->status,
            'serial_number' => $row->serial_number,
            'asset_number' => $row->asset_number,
            'product_id' => $row->product_id,
            'product_name' => $row->product_name,
            'component_group' => $row->component_group,
            'location' => match (true) {
                $onVehicle => ['type' => 'VEHICLE', 'id' => $row->vehicle_id, 'label' => $row->registration_number],
                $inWarehouse => ['type' => 'WAREHOUSE', 'id' => $row->warehouse_id, 'label' => $row->warehouse_name],
                default => null,
            },
        ];
    }

    // ------------------------------------------------------------------ Goods Receipt

    /**
     * Generates one asset per unit received on a Goods Receipt line of an eligible product — the
     * accepted (received) quantity, never the ordered one. Runs inside the receipt's transaction;
     * calling it again for the same line generates nothing more.
     *
     * @return list<ComponentAsset>
     */
    public function generateFromReceipt(GoodsReceiptItem $line, Product $product, string $tenantId, string $warehouseId, ?string $receivedOn): array
    {
        if (! $this->eligible($product) || (float) $line->quantity_accepted <= 0) {
            return [];
        }
        $units = (float) $line->quantity_accepted;
        if (abs($units - round($units)) > 0.0001) {
            throw new ProcurementException("{$product->name} is tracked by serial number: receive it in whole units.");
        }

        // Serialize generation per receipt line (row lock), then continue after the units it already has.
        GoodsReceiptItem::query()->whereKey($line->id)->lockForUpdate()->first();
        $existing = (int) ComponentAsset::query()->withoutGlobalScopes()->where('goods_receipt_item_id', $line->id)->count();
        $assets = [];
        for ($sequence = $existing + 1; $sequence <= (int) round($units); $sequence++) {
            $assets[] = ComponentAsset::query()->create([
                'tenant_id' => $tenantId, 'product_id' => $product->id, 'asset_number' => $this->nextAssetNumber($tenantId),
                'purchase_date' => $receivedOn, 'purchase_cost' => $line->unit_cost, 'current_status' => 'IN_STOCK',
                'current_warehouse_id' => $warehouseId, 'goods_receipt_item_id' => $line->id, 'receipt_sequence' => $sequence,
            ]);
        }

        return $assets;
    }

    /** "AST-2026-000001": a per-tenant, per-year counter (row-locked), skipping any number already used. */
    public function nextAssetNumber(string $tenantId): string
    {
        $year = (int) now()->format('Y');
        do {
            $number = sprintf('AST-%d-%06d', $year, $this->sequences->next("component_asset:{$tenantId}", $year, 1));
        } while (ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('asset_number', $number)->exists());

        return $number;
    }

    // ------------------------------------------------------------------ Return to Vendor

    /** IN_STOCK assets generated from this PO line that are still in the given warehouse (returnable). */
    public function returnableForLine(PurchaseOrderItem $item, ?string $warehouseId): array
    {
        return $this->lineAssets($item->id)->where('current_status', 'IN_STOCK')
            ->when($warehouseId, fn ($q) => $q->where('current_warehouse_id', $warehouseId))
            ->orderBy('asset_number')->get(['id', 'asset_number', 'serial_number'])
            ->map(fn (ComponentAsset $a) => ['id' => $a->id, 'asset_number' => $a->asset_number, 'serial_number' => $a->serial_number])->all();
    }

    /**
     * Owner decision: the Return Order names the exact Asset# returned. For a line whose receipts
     * generated assets, the selection is required and must match the returned quantity; the
     * selected IN_STOCK assets become RETURNED_TO_VENDOR (no location; rows and history kept). A
     * redelivery is a new physical unit, so its Goods Receipt generates new assets.
     *
     * @param  list<string>  $assetIds
     */
    public function returnToVendor(PurchaseReturn $return, PurchaseOrderItem $item, float $quantity, array $assetIds): void
    {
        $hasAssets = $this->lineAssets($item->id)->exists();
        if (! $hasAssets) {
            if ($assetIds !== []) {
                throw new ProcurementException('This item has no Component Assets to select.');
            }

            return; // received before asset tracking / not a tracked product: quantity only
        }
        $assetIds = array_values(array_unique($assetIds));
        if (abs(count($assetIds) - $quantity) > 0.0001) {
            throw new ProcurementException('Select exactly '.rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.').' Asset# being returned for this item (selected '.count($assetIds).').');
        }
        $assets = $this->lineAssets($item->id)->whereIn('id', $assetIds)->lockForUpdate()->get();
        if ($assets->count() !== count($assetIds)) {
            throw new ProcurementException('A selected Asset# was not received on this Purchase Order item.');
        }
        foreach ($assets as $asset) {
            if ($asset->current_status !== 'IN_STOCK' || $asset->current_warehouse_id !== $return->warehouse_id) {
                throw new ProcurementException("Asset {$asset->asset_number} is {$asset->current_status} and not in stock at the delivery warehouse; it cannot be returned.");
            }
            $asset->update(['current_status' => 'RETURNED_TO_VENDOR', 'current_warehouse_id' => null, 'purchase_return_id' => $return->id]);
        }
    }

    private function lineAssets(string $purchaseOrderItemId)
    {
        return ComponentAsset::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('goods_receipt_item_id', DB::table('goods_receipt_items')->where('purchase_order_item_id', $purchaseOrderItemId)->select('id'));
    }
}
