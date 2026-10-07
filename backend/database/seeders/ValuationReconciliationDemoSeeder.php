<?php

namespace Database\Seeders;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Models\StockReconciliationAdjustment;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\StockOpnameService;
use App\Domain\Inventory\Services\StockReconciliationAdjustmentService;
use App\Domain\Inventory\Services\StockValuationService;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderPartService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DEMO layer (not production): the valuation-status and reconciliation scenarios of tenant ALPHA, built through the real
 * services and state transitions (goods receipt, opname, valuation review, proposal -> approval -> apply) — never by
 * writing balances, movements or adjustments directly. Idempotent: the "VR Demo" products are the marker; a rerun is a
 * no-op, so no movement or adjustment is ever duplicated. Only the *legacy* installations (rows that predate the stock
 * exit rule) are written directly, because that history genuinely cannot be produced by today's code.
 *
 * Scenarios (warehouses: Denpasar = DPS, Surabaya = SBY):
 *   reconciliation  provable undeducted (left for review) / pending / approved-not-applied / applied / rejected adjustment;
 *                   installation covered by a Work Order issue; component without a receipt link (ambiguous);
 *                   ambiguous tire with posted opname evidence (variance −1); installation settled by a later opname;
 *                   an opname without variance
 *   valuation       verified (priced receipt) / free goods without a basis (unverified) / verified zero with evidence /
 *                   mixed sources (opening balance + priced receipt) / opening balance at no cost (unverified)
 */
class ValuationReconciliationDemoSeeder extends Seeder
{
    private const MARKER = 'VR Demo';

    private Tenant $tenant;

    private User $maker;

    private ?User $checker;

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'ALPHA')->first();
        $maker = User::query()->where('email', 'alpha.admin@optifleet.test')->first();
        if (! $tenant || ! $maker) {
            return;
        }
        $this->tenant = $tenant;
        $this->maker = $maker;
        $this->checker = User::query()->where('email', 'alpha.branchadmin@optifleet.test')->first();
        $context = app(TenantContext::class);
        $previous = $context->tenantId();
        $context->setTenantId($tenant->id);
        $context->setUser($maker);
        try {
            if (Product::query()->where('tenant_id', $tenant->id)->where('name', 'like', self::MARKER.'%')->exists()) {
                return; // already seeded: no second set of movements or adjustments
            }
            $dps = $this->warehouse('ALPHA-DPS-WH1');
            $sby = $this->warehouse('ALPHA-SBY-WH1');
            $vehicle = $this->vehicle();
            $this->reconciliation($dps, $sby, $vehicle);
            $this->valuation($dps);
        } finally {
            $context->setUser(null);
            $context->setTenantId($previous);
        }
    }

    // ---------------------------------------------------------------- reconciliation

    private function reconciliation(Warehouse $dps, Warehouse $sby, Vehicle $vehicle): void
    {
        $adjustments = app(StockReconciliationAdjustmentService::class);

        // Denpasar: six units received on a posted Goods Receipt, installed long ago without a stock exit.
        $alternator = $this->serialProduct('VR Demo Alternator');
        $assets = $this->receiveSerial($dps, $alternator, 6, 1200000);
        $installs = [];
        foreach ($assets as $i => $asset) {
            $installs[$i] = $this->legacyInstall($asset, $vehicle, now()->subDays(60 - $i));
        }
        // #5 was issued by a Work Order part request at the time (ledger already reduced) -> COVERED_BY_WO_ISSUE.
        $this->issueOnWorkOrder($dps, $alternator, $vehicle, $installs[5]);

        if ($this->checker) {
            $reason = 'Old installation never issued from the ledger (demo).';
            $pending = $adjustments->propose($this->tenant->id, $installs[1]->id, $reason, $this->maker->id);
            $approved = $adjustments->propose($this->tenant->id, $installs[2]->id, $reason, $this->maker->id);
            $adjustments->decide($this->tenant->id, $approved->id, 'APPROVE', $this->checker->id, 'Matches the receipt (demo).');
            $applied = $adjustments->propose($this->tenant->id, $installs[3]->id, $reason, $this->maker->id);
            $adjustments->decide($this->tenant->id, $applied->id, 'APPROVE', $this->checker->id, 'Matches the receipt (demo).');
            $adjustments->apply($this->tenant->id, $applied->id, $this->maker->id);
            $rejected = $adjustments->propose($this->tenant->id, $installs[4]->id, $reason, $this->maker->id);
            $adjustments->decide($this->tenant->id, $rejected->id, 'REJECT', $this->checker->id, 'Unit was scrapped, not issued (demo).');
            unset($pending);
        }
        // #0 stays PROVABLE_UNDEDUCTED: the dry-run candidate waiting for a proposal.

        // A component registered without a Goods Receipt link: its origin cannot be proven -> AMBIGUOUS_NO_RECEIPT.
        $legacy = ComponentAsset::query()->create([
            'tenant_id' => $this->tenant->id, 'product_id' => $alternator->id, 'serial_number' => 'VR-LEGACY-1', 'asset_number' => app(\App\Domain\ComponentAsset\Services\ComponentAssetRegisterService::class)->nextAssetNumber($this->tenant->id),
            'component_group_id' => $alternator->componentGroups()->value('component_groups.id') ?? ComponentGroup::query()->whereNull('tenant_id')->value('id'),
            'current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id,
        ]);
        ComponentInstallation::query()->create(['tenant_id' => $this->tenant->id, 'component_asset_id' => $legacy->id, 'vehicle_id' => $vehicle->id, 'position_location' => 'B', 'installed_at' => now()->subDays(90)]);

        // Surabaya: a tire without a receipt link and a starter settled by a later posted opname.
        $starter = $this->serialProduct('VR Demo Starter');
        $starterAssets = $this->receiveSerial($sby, $starter, 2, 900000);
        $this->legacyInstall($starterAssets[0], $vehicle, now()->subDays(50));
        $tireProduct = $this->product('VR Demo Tire 295/80R22.5', 'TIRE', serial: true);
        app(InventoryService::class)->receive($sby, $tireProduct, 4, 0, 'OPENING', null, null, $this->maker->id, 'VR demo: opening balance of the tire stock (no cost recorded).');
        $tire = Tire::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $tireProduct->id, 'serial_number' => 'VR-TIRE-01', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id]);
        TireInstallation::query()->create(['tenant_id' => $this->tenant->id, 'tire_id' => $tire->id, 'vehicle_id' => $vehicle->id, 'wheel_position' => 'FL', 'installed_at' => now()->subDays(70)]);

        // Physical count of Surabaya: the tire stock is one short and the starter stock is one short (variance −1 each).
        $opnames = app(StockOpnameService::class);
        $opname = $opnames->create($sby, $this->maker->id);
        foreach ($opname->items as $item) {
            if (in_array($item->product_id, [$tireProduct->id, $starter->id], true)) {
                $opnames->recordCount($item, (float) $item->system_quantity - 1, 'Physical count (demo).');
            }
        }
        $opname = $opnames->transition($opnames->transition($opname, 'COUNTING'), 'SUBMITTED');
        $opnames->post($opnames->approve($opname, $this->checker?->id ?? $this->maker->id), $this->maker->id);

        // Denpasar: a physical count with NO variance (an ordinary gasket stock).
        $gasket = $this->product('VR Demo Gasket Set');
        app(InventoryService::class)->receive($dps, $gasket, 12, 25000, 'OPENING', null, null, $this->maker->id, 'VR demo: gasket opening balance.');
        $opname = $opnames->create($dps, $this->maker->id);
        foreach ($opname->items as $item) {
            if ($item->product_id === $gasket->id) {
                $opnames->recordCount($item, (float) $item->system_quantity, 'Count equals the system quantity (demo).');
            }
        }
        $opname = $opnames->transition($opnames->transition($opname, 'COUNTING'), 'SUBMITTED');
        $opnames->post($opnames->approve($opname, $this->checker?->id ?? $this->maker->id), $this->maker->id);
    }

    // ---------------------------------------------------------------- valuation

    private function valuation(Warehouse $dps): void
    {
        $inventory = app(InventoryService::class);
        // Verified: a priced Goods Receipt.
        $this->receivePurchase($dps, $this->product('VR Demo Priced Hose'), 20, 35000);
        // Free goods without a valuation basis: the purchase price is 0, the inventory valuation stays UNVERIFIED.
        $this->receivePurchase($dps, $this->product('VR Demo Free Sample Oil'), 10, 0);
        // Free goods WITH a documented basis: reviewed -> verified zero, with evidence.
        $donated = $this->product('VR Demo Donated Filter');
        $this->receivePurchase($dps, $donated, 6, 0);
        $stock = WarehouseStock::query()->where('warehouse_id', $dps->id)->where('product_id', $donated->id)->firstOrFail();
        app(StockValuationService::class)->review($this->tenant->id, $stock->id, 'VERIFIED_ZERO', 'DONATION_DOCUMENTED', 'Donated by the supplier; no cost basis exists.', 'DON-2026-014', false, $this->checker?->id ?? $this->maker->id);
        // Mixed sources: an unreviewed opening balance plus a priced receipt in the same balance.
        $bolt = $this->product('VR Demo Mixed Bolt');
        $inventory->receive($dps, $bolt, 10, 1000, 'OPENING', null, null, $this->maker->id, 'VR demo: opening balance (not reviewed).');
        $this->receivePurchase($dps, $bolt, 10, 1200);
    }

    // ---------------------------------------------------------------- helpers

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::query()->where('tenant_id', $this->tenant->id)->orderBy('registration_number')->firstOrFail();
    }

    private function product(string $name, string $type = 'SPARE_PART', bool $serial = false): Product
    {
        return Product::query()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'PRD-'.strtoupper(substr(md5($name), 0, 6)), 'sku' => 'SKU-'.strtoupper(substr(md5('sku'.$name), 0, 6)), 'name' => $name,
            'product_category_id' => ProductCategory::query()->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id))->value('id'),
            'product_type' => $type, 'uom_id' => Uom::query()->where('code', 'PCS')->value('id') ?? Uom::query()->value('id'), 'status' => 'ACTIVE', 'track_serial_number' => $serial,
        ]);
    }

    private function serialProduct(string $name): Product
    {
        $product = $this->product($name, 'SPARE_PART', serial: true);
        DB::table('product_component_groups')->insert(['product_id' => $product->id, 'component_group_id' => ComponentGroup::query()->whereNull('tenant_id')->value('id'), 'created_at' => now(), 'updated_at' => now()]);

        return $product;
    }

    /** A posted Goods Receipt (the real path: generates the serial component assets and the priced ledger receipt). */
    private function receivePurchase(Warehouse $warehouse, Product $product, int $quantity, float $price): PurchaseOrder
    {
        $vendor = Partner::query()->where('tenant_id', $this->tenant->id)->where('code', 'VND-SINAR')->first() ?? Partner::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
        $orders = app(PurchaseOrderService::class);
        $po = $orders->create($vendor, $warehouse, ['expected_delivery_date' => now()->toDateString(), 'notes' => self::MARKER], [['product_id' => $product->id, 'quantity_ordered' => $quantity, 'unit_price' => $price]], $this->maker->id);
        $po = $orders->transition($orders->approve($orders->transition($po, 'SUBMITTED'), $this->maker->id), 'ISSUED');
        $po = PurchaseOrder::query()->with('items')->findOrFail($po->id);
        $number = 'VR-INV-'.strtoupper(substr(md5($product->name), 0, 6));
        app(GoodsReceiptService::class)->post($po, $warehouse, [['purchase_order_item_id' => $po->items->first()->id, 'quantity_accepted' => $quantity, 'quantity_rejected' => 0]], $this->maker->id, null, [
            'mode' => 'NEW', 'vendor_invoice_number' => $number, 'vendor_invoice_date' => now()->toDateString(), 'amount' => (string) max(1, $quantity * $price), 'terms_of_payment_days' => 30,
            'document' => DemoQuotationDocument::make($number, 'Valuation demo document', $number.'.pdf'),
        ]);

        return $po;
    }

    /** @return list<ComponentAsset> */
    private function receiveSerial(Warehouse $warehouse, Product $product, int $quantity, float $price): array
    {
        $this->receivePurchase($warehouse, $product, $quantity, $price);

        return ComponentAsset::query()->where('tenant_id', $this->tenant->id)->where('product_id', $product->id)->orderBy('asset_number')->get()->all();
    }

    /** The unit is on a vehicle but its installation never produced a stock exit: history written before the exit rule existed. */
    private function legacyInstall(ComponentAsset $asset, Vehicle $vehicle, $at): ComponentInstallation
    {
        $installation = ComponentInstallation::query()->create(['tenant_id' => $this->tenant->id, 'component_asset_id' => $asset->id, 'vehicle_id' => $vehicle->id, 'position_location' => 'A', 'installed_at' => $at]);
        $asset->update(['current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id, 'current_warehouse_id' => null]);

        return $installation;
    }

    /** A Work Order part request line issued for the product (the ledger unit left through it), then the installation recorded on that WO. */
    private function issueOnWorkOrder(Warehouse $warehouse, Product $product, Vehicle $vehicle, ComponentInstallation $installation): void
    {
        $workshopId = $warehouse->workshop_id ?? $vehicle->default_workshop_id;
        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshopId, 'maintenance_type' => 'CORRECTIVE'], null);
        WorkOrder::query()->where('id', $wo->id)->update(['status' => 'IN_PROGRESS']);
        $part = WorkOrderPlannedPart::query()->create(['tenant_id' => $this->tenant->id, 'work_order_id' => $wo->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'description' => 'VR demo alternator', 'quantity' => 1, 'planned_quantity' => 1, 'stock_condition' => 'NEW', 'status' => 'PLANNED']);
        app(WorkOrderPartService::class)->issue($part->fresh(), null, $this->maker->id);
        $installation->update(['work_order_id' => $wo->id]);
    }
}
