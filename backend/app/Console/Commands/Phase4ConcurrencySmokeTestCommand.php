<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\RfqService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Domain\ProductMaster\Models\Uom;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off release-gate concurrency probe for Phase 4 (Section 51/52 of the
 * brief): forks real OS processes to race stock reservation, stock issue,
 * duplicate goods-receipt posting, and duplicate quotation-to-PO
 * conversion. Not wired into any schedule or route; builds its own
 * throwaway fixtures so it never depends on prior seed-run state, and
 * cleans them up on exit.
 */
class Phase4ConcurrencySmokeTestCommand extends Command
{
    protected $signature = 'concurrency:smoke-test-phase4';
    protected $description = 'Fork concurrent workers to probe Phase 4 (reservation/issue/goods-receipt/PO-conversion) race safety';

    private ?Tenant $tenant = null;

    public function handle(): int
    {
        [$tenant, $warehouse, $product] = $this->makeFixtures();
        $this->tenant = $tenant;

        $ok = true;
        $ok = $this->overReservationRace($warehouse, $product) && $ok;
        $ok = $this->concurrentIssueRace($warehouse, $product) && $ok;
        $ok = $this->duplicateGoodsReceiptRace($warehouse, $product) && $ok;
        $ok = $this->duplicatePoConversionRace($warehouse, $product) && $ok;

        $this->cleanup();

        if (! $ok) {
            $this->error('PHASE 4 CONCURRENCY SMOKE TEST: ONE OR MORE RACES FAILED');
            return self::FAILURE;
        }

        $this->info('PHASE 4 CONCURRENCY SMOKE TEST: ALL RACES SAFE');
        return self::SUCCESS;
    }

    private function fork(int $workers, callable $work): void
    {
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->error('fork failed');
                exit(1);
            }
            if ($pid === 0) {
                DB::purge();
                try {
                    $work($i);
                } catch (\Throwable $e) {
                    // Expected for every loser of a race.
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    private function makeFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'code' => 'SMOKE4-'.Str::upper(Str::random(6)),
            'name' => 'Phase4 Smoke Test Tenant',
            'status' => 'ACTIVE',
        ]);
        $warehouse = Warehouse::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'SMK4-WH', 'name' => 'Smoke Warehouse', 'warehouse_type' => 'BRANCH', 'status' => 'ACTIVE',
        ]);
        $category = ProductCategory::query()->create(['tenant_id' => $tenant->id, 'code' => 'SMK4-PC', 'name' => 'Smoke Category', 'is_system' => false, 'status' => 'ACTIVE']);
        $uom = Uom::query()->create(['tenant_id' => $tenant->id, 'code' => 'SMK4-UOM', 'name' => 'Smoke Unit', 'is_system' => false, 'status' => 'ACTIVE']);
        $product = Product::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'SMK4-PROD', 'sku' => 'SMK4-SKU', 'name' => 'Smoke Product',
            'product_category_id' => $category->id, 'product_type' => 'SPARE_PART', 'uom_id' => $uom->id, 'status' => 'ACTIVE',
        ]);

        return [$tenant, $warehouse, $product];
    }

    private function overReservationRace(Warehouse $warehouse, Product $product): bool
    {
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);

        $this->info("\n[1/4] Racing 20 workers each reserving 2 units against 10 on hand");
        $this->fork(20, function () use ($warehouse, $product) {
            app(InventoryService::class)->reserve($warehouse, $product, 2, null, null, null);
        });

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $onHand = (float) $stock->quantity_on_hand;
        $reserved = (float) $stock->quantity_reserved;
        $available = $stock->quantityAvailable();

        $this->line("on_hand={$onHand} reserved={$reserved} available={$available} (expect reserved<=10, available>=0)");
        $ok = $reserved <= $onHand && $available >= 0;
        $this->line($ok ? 'over-reservation race: SAFE' : 'OVER-RESERVATION RACE DETECTED (reserved exceeded on_hand or available went negative)');

        return $ok;
    }

    private function concurrentIssueRace(Warehouse $warehouse, Product $product): bool
    {
        // Fresh product instance so this race starts from a clean, known balance.
        $product2 = Product::query()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'SMK4-PROD2', 'sku' => 'SMK4-SKU2', 'name' => 'Smoke Product 2',
            'product_category_id' => $product->product_category_id, 'product_type' => 'SPARE_PART', 'uom_id' => $product->uom_id, 'status' => 'ACTIVE',
        ]);
        app(InventoryService::class)->receive($warehouse, $product2, 10, 5, 'OPENING', null, null, null);

        $this->info("\n[2/4] Racing 20 workers each issuing 2 units against 10 on hand");
        $this->fork(20, function () use ($warehouse, $product2) {
            app(InventoryService::class)->issue($warehouse, $product2, 2, null, null, null);
        });

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product2->id)->first();
        $onHand = (float) $stock->quantity_on_hand;
        $this->line("final on_hand={$onHand} (expect exactly 0 — 5 issues of 2 succeed, the rest reject)");
        $ok = $onHand === 0.0;
        $this->line($ok ? 'concurrent issue race: SAFE' : 'OVER-ISSUE RACE DETECTED (on_hand went negative or was miscounted)');

        return $ok;
    }

    private function duplicateGoodsReceiptRace(Warehouse $warehouse, Product $product): bool
    {
        $partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'SMK4-VND', 'name' => 'Smoke Vendor', 'partner_type' => 'SPARE_PART_SUPPLIER', 'status' => 'ACTIVE',
        ]);
        $poService = app(PurchaseOrderService::class);
        $po = $poService->create($partner, $warehouse, [], [
            ['product_id' => $product->id, 'quantity_ordered' => 10, 'unit_price' => 10],
        ], null);
        $po = $poService->transition($po, 'SUBMITTED');
        $po = $poService->approve($po, null);
        $poService->transition($po, 'ISSUED');
        $item = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->first();

        $this->info("\n[3/4] Racing 10 workers each posting a full 10-unit goods receipt against the same PO");
        $this->fork(10, function () use ($po, $warehouse, $item) {
            app(GoodsReceiptService::class)->post(PurchaseOrder::find($po->id), $warehouse, [
                ['purchase_order_item_id' => $item->id, 'quantity_accepted' => 10],
            ], null);
        });

        $item->refresh();
        $received = (float) $item->quantity_received;
        $this->line("quantity_received={$received} (expect exactly 10, never double-posted)");
        $ok = $received === 10.0;
        $this->line($ok ? 'duplicate goods receipt race: SAFE' : 'DUPLICATE GOODS RECEIPT DETECTED (over-received)');

        return $ok;
    }

    private function duplicatePoConversionRace(Warehouse $warehouse, Product $product): bool
    {
        $partner = Partner::query()->create([
            'tenant_id' => $this->tenant->id, 'code' => 'SMK4-VND2', 'name' => 'Smoke Vendor 2', 'partner_type' => 'SPARE_PART_SUPPLIER', 'status' => 'ACTIVE',
        ]);
        $rfqService = app(RfqService::class);
        $rfq = $rfqService->create($warehouse, [], [['product_id' => $product->id, 'quantity' => 5]]);
        $rfq = $rfqService->inviteVendors($rfq, [$partner->id]);
        $quotation = $rfqService->submitQuotation($rfq, $partner, [], [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20],
        ]);
        $quotation = $rfqService->selectVendor($quotation);

        $this->info("\n[4/4] Racing 10 workers each converting the SAME selected quotation to a Purchase Order");
        $poService = app(PurchaseOrderService::class);
        $this->fork(10, function () use ($poService, $quotation, $warehouse) {
            $poService->createFromQuotation($quotation, $warehouse, [], null);
        });

        $count = PurchaseOrder::query()->where('vendor_quotation_id', $quotation->id)->count();
        $this->line("Purchase Orders created from this quotation: {$count} (expect 1)");
        $ok = $count === 1;
        $this->line($ok ? 'quotation-to-PO conversion concurrency: SAFE' : 'DUPLICATE QUOTATION-TO-PO CONVERSION DETECTED');

        return $ok;
    }

    private function cleanup(): void
    {
        if (! $this->tenant) {
            return;
        }
        DB::table('goods_receipt_items')->whereIn('goods_receipt_id', function ($q) {
            $q->select('id')->from('goods_receipts')->where('tenant_id', $this->tenant->id);
        })->delete();
        DB::table('goods_receipts')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('purchase_order_items')->whereIn('purchase_order_id', function ($q) {
            $q->select('id')->from('purchase_orders')->where('tenant_id', $this->tenant->id);
        })->delete();
        DB::table('purchase_orders')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('vendor_quotation_items')->whereIn('vendor_quotation_id', function ($q) {
            $q->select('id')->from('vendor_quotations')->where('tenant_id', $this->tenant->id);
        })->delete();
        DB::table('vendor_quotations')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('rfq_vendors')->whereIn('rfq_id', function ($q) {
            $q->select('id')->from('rfqs')->where('tenant_id', $this->tenant->id);
        })->delete();
        DB::table('rfq_items')->whereIn('rfq_id', function ($q) {
            $q->select('id')->from('rfqs')->where('tenant_id', $this->tenant->id);
        })->delete();
        DB::table('rfqs')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('partners')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('warehouse_stocks')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('products')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('uoms')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('product_categories')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('warehouses')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('tenants')->where('id', $this->tenant->id)->delete();
        $this->info("\ncleaned up throwaway tenant {$this->tenant->id}");
    }
}
