<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryProcurementVendorAnalyticsTest extends TestCase
{
    private const BUSINESS_DATE = '2026-09-06';

    protected function tearDown(): void
    {
        foreach ([
            'daily_inventory_metrics', 'daily_procurement_metrics', 'daily_vendor_metrics', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function at(string $time): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse(self::BUSINESS_DATE.' '.$time, 'UTC');
    }

    public function test_inventory_value_uses_existing_weighted_average_cost(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $warehouse = $this->makeWarehouse($tenant);
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        $productA = $this->makeProduct($tenant, $category, $uom);
        $productB = $this->makeProduct($tenant, $category, $uom);

        DB::table('warehouse_stocks')->insert([
            ['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $productA->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 2, 'reorder_point' => 5, 'average_unit_cost' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $productB->id, 'quantity_on_hand' => 0, 'quantity_reserved' => 0, 'reorder_point' => 5, 'average_unit_cost' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'inventory_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_inventory_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'warehouse_id' => $warehouse->id]);

        $this->assertEqualsWithDelta(1000.0, $doc->inventory_value, 0.01); // 10 * 100 + 0 * 50
        $this->assertSame(1, $doc->out_of_stock_count);
        $this->assertSame(0, $doc->low_stock_count);
    }

    public function test_procurement_late_receipt_and_lead_time(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $partner = $this->makePartner($tenant);

        $poId = (string) Str::uuid();
        DB::table('purchase_orders')->insert([
            'id' => $poId, 'tenant_id' => $tenant->id, 'po_number' => 'PO-1', 'partner_id' => $partner->id,
            'delivery_warehouse_id' => $warehouse->id, 'status' => 'RECEIVED',
            'order_date' => '2026-09-01', 'expected_delivery_date' => '2026-09-04',
            'created_at' => $this->at('08:00:00'), 'updated_at' => $this->at('08:00:00'),
        ]);
        DB::table('goods_receipts')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'gr_number' => 'GR-1', 'purchase_order_id' => $poId,
            'warehouse_id' => $warehouse->id, 'partner_id' => $partner->id, 'status' => 'POSTED',
            'received_at' => $this->at('09:00:00'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'procurement_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_procurement_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => $branch->id]);

        $this->assertSame(1, $doc->late_receipt); // received 09-06, expected 09-04
        // order_date 09-01 00:00 -> received_at 09-06 09:00 = 5 days 9 hours.
        $this->assertEqualsWithDelta(5.375, $doc->procurement_lead_time_days, 0.05);
    }

    public function test_vendor_fulfillment_and_price_variance(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $warehouse = $this->makeWarehouse($tenant);
        $partner = $this->makePartner($tenant);
        $category = $this->makeProductCategory();
        $uom = $this->makeUom();
        $product = $this->makeProduct($tenant, $category, $uom);

        $rfqId = (string) Str::uuid();
        DB::table('rfqs')->insert(['id' => $rfqId, 'tenant_id' => $tenant->id, 'rfq_number' => 'RFQ-1', 'warehouse_id' => $warehouse->id, 'status' => 'CLOSED', 'created_at' => now(), 'updated_at' => now()]);
        $quotationId = (string) Str::uuid();
        DB::table('vendor_quotations')->insert(['id' => $quotationId, 'tenant_id' => $tenant->id, 'rfq_id' => $rfqId, 'partner_id' => $partner->id, 'status' => 'SELECTED', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vendor_quotation_items')->insert(['id' => Str::uuid(), 'vendor_quotation_id' => $quotationId, 'product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $poId = (string) Str::uuid();
        DB::table('purchase_orders')->insert([
            'id' => $poId, 'tenant_id' => $tenant->id, 'po_number' => 'PO-2', 'partner_id' => $partner->id,
            'vendor_quotation_id' => $quotationId, 'delivery_warehouse_id' => $warehouse->id, 'status' => 'RECEIVED',
            'created_at' => $this->at('08:00:00'), 'updated_at' => $this->at('10:00:00'),
        ]);
        DB::table('purchase_order_items')->insert([
            'id' => Str::uuid(), 'purchase_order_id' => $poId, 'product_id' => $product->id,
            'quantity_ordered' => 10, 'quantity_received' => 8, 'unit_price' => 110,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'vendor_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_vendor_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'vendor_id' => $partner->id]);

        $this->assertEqualsWithDelta(80.0, $doc->fulfillment_rate_percentage, 0.01); // 8/10
        $this->assertEqualsWithDelta(10.0, $doc->avg_price_variance_percentage, 0.01); // (110-100)/100
    }
}
