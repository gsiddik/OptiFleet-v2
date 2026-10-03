<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\PurchaseRequestService;
use App\Domain\Procurement\Services\RfqService;
use App\Models\User;

/**
 * Inventory prerequisites (Section 37): Partners (incl. the External
 * Workshop the External WO/WAL scenarios need), a full PR -> RFQ ->
 * Quotation -> PO -> Goods Receipt procurement chain (covering a normal,
 * a serialized, and a batch-tracked line item in one receipt) through the
 * real services — mirroring SupplyChainSeeder's own pattern — plus simple
 * opening stock via `InventoryService::receive()` for the remaining
 * products the Work Order scenarios need to reserve/issue/consume.
 *
 * @return object{warehouse: Warehouse, partners: array<string, Partner>}
 */
class FunctionalTestInventorySeeder
{
    public function run(Tenant $tenant, object $ops, object $products): object
    {
        $warehouse = Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'FTEST-MAIN-WH1')->firstOrFail();
        $warehouseManagerId = User::query()->where('email', 'ft.warehousemanager@optifleet.test')->value('id');

        $sparePartSupplier = Partner::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-VND-SPARE'],
            ['name' => '[TEST] Sparepart Supplier', 'partner_type' => 'SPARE_PART_SUPPLIER', 'contact_name' => 'Rina Wulandari', 'contact_phone' => '021-5550001', 'payment_terms' => 'NET_30', 'status' => 'ACTIVE']
        );
        $externalWorkshop = Partner::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'TEST-EXT-WS-A'],
            ['name' => '[TEST] External Workshop A', 'partner_type' => 'EXTERNAL_WORKSHOP', 'contact_name' => 'Agus Setiawan', 'contact_phone' => '021-5550002', 'payment_terms' => 'NET_14', 'status' => 'ACTIVE']
        );

        $bySku = $products->bySku;
        $this->seedProcurementChain($tenant, $warehouse, $sparePartSupplier, $warehouseManagerId, $bySku);

        $inventory = app(InventoryService::class);
        foreach ([
            'TEST-SP-003' => [10, 45000],
            'TEST-SP-004' => [5, 1250000],
            'TEST-CS-001' => [50, 65000],
            'TEST-CS-002' => [1, 3200000],
            'TEST-CS-003' => [20, 55000],
            'TEST-CS-004' => [15, 38000],
            'TEST-TIRE-CAR-001' => [8, 850000],
            // Rim, Tool and Equipment stock so both Warehouse Stock tabs
            // (Parts & Supplies / Tools & Equipment) have functional-test data.
            'TEST-RIM-001' => [4, 650000],
            'TEST-TOOL-001' => [3, 450000],
            'TEST-EQP-001' => [1, 12500000],
        ] as $sku => [$qty, $cost]) {
            $product = $bySku[$sku];
            if (! StockMovement::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->exists()) {
                $inventory->receive($warehouse, $product, $qty, $cost, 'OPENING', null, null, $warehouseManagerId);
            }
        }

        return (object) [
            'warehouse' => $warehouse,
            'partners' => ['SPARE_PART_SUPPLIER' => $sparePartSupplier, 'EXTERNAL_WORKSHOP' => $externalWorkshop],
        ];
    }

    private function seedProcurementChain(Tenant $tenant, Warehouse $warehouse, Partner $vendor, ?string $userId, array $bySku): void
    {
        if (PurchaseOrder::query()->where('tenant_id', $tenant->id)->where('notes', 'like', '[FT-PROCUREMENT]%')->exists()) {
            return;
        }

        $brakePad = $bySku['TEST-SP-001'];
        $alternator = $bySku['TEST-SP-002'];
        $grease = $bySku['TEST-CS-005'];
        $truckTire = $bySku['TEST-TIRE-TRUCK-001'];

        $lines = [
            ['product' => $brakePad, 'qty' => 20, 'price' => 250000],
            ['product' => $alternator, 'qty' => 3, 'price' => 1800000],
            ['product' => $grease, 'qty' => 10, 'price' => 95000],
            ['product' => $truckTire, 'qty' => 4, 'price' => 4200000],
        ];

        $prService = app(PurchaseRequestService::class);
        $pr = $prService->create($warehouse, [
            'source_type' => 'MANUAL', 'priority' => 'MEDIUM', 'notes' => '[FT-PROCUREMENT] Functional test restock.',
        ], array_map(fn ($l) => ['product_id' => $l['product']->id, 'requested_quantity' => $l['qty'], 'estimated_unit_price' => $l['price']], $lines), $userId);
        $pr = $prService->transition($pr, 'SUBMITTED');
        $pr = $prService->transition($pr, 'UNDER_REVIEW');
        $pr = $prService->transition($pr, 'APPROVED');

        $rfqService = app(RfqService::class);
        $rfq = $rfqService->create($warehouse, [], array_map(fn ($l) => ['product_id' => $l['product']->id, 'quantity' => $l['qty']], $lines), $pr);
        $rfq = $rfqService->inviteVendors($rfq, [$vendor->id]);
        $quotation = $rfqService->submitQuotation($rfq, $vendor, [
            'lead_time_days' => 7, 'payment_terms' => 'NET_30',
        ], array_map(fn ($l) => ['product_id' => $l['product']->id, 'quantity' => $l['qty'], 'unit_price' => $l['price'], 'tax_percent' => 11], $lines), DemoQuotationDocument::make('[TEST] Sparepart Supplier'), $userId);
        $quotation = $rfqService->selectVendor($quotation);

        $poService = app(PurchaseOrderService::class);
        $po = $poService->createFromQuotation($quotation, $warehouse, ['order_date' => now()->toDateString()], $userId);
        $po = $poService->transition($po, 'SUBMITTED');
        $po = $poService->approve($po, $userId);
        $po = $poService->transition($po, 'ISSUED');
        $po->update(['notes' => '[FT-PROCUREMENT] Functional test restock.']);

        $items = $po->items()->get()->keyBy('product_id');
        $receiptLines = [
            ['purchase_order_item_id' => $items[$brakePad->id]->id, 'quantity_accepted' => 20],
            ['purchase_order_item_id' => $items[$alternator->id]->id, 'quantity_accepted' => 3, 'serial_numbers' => ['FTALT-SN-001', 'FTALT-SN-002', 'FTALT-SN-003']],
            ['purchase_order_item_id' => $items[$grease->id]->id, 'quantity_accepted' => 10, 'batch_number' => 'FT-BATCH-2026-01'],
            ['purchase_order_item_id' => $items[$truckTire->id]->id, 'quantity_accepted' => 4, 'serial_numbers' => array_map(fn ($n) => DemoSerial::make("FTEST|TIRE|{$n}"), [1, 2, 3, 4])],
        ];
        app(GoodsReceiptService::class)->post($po, $warehouse, $receiptLines, $userId, '[FT-PROCUREMENT] Full receipt against issued PO.', [
            'mode' => 'NEW', 'vendor_invoice_number' => 'FT-INV-2026-0001', 'vendor_invoice_date' => now()->toDateString(),
            'amount' => (string) $po->total, 'terms_of_payment_days' => 14,
            'document' => DemoQuotationDocument::make('FT-INV-2026-0001', 'Demo vendor invoice', 'FT-INV-2026-0001.pdf'),
        ]);
    }
}
