<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vendor Detail → type-aware KPIs computed from real transactions over an explicit period.
 * Fixtures include noise that must NOT be counted: another partner, another tenant, and rows
 * outside the period.
 */
class VendorPerformanceTest extends TestCase
{
    private const FROM = '2026-01-01';

    private const TO = '2026-06-30';

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->makeTenant(['code' => 'VPF-'.Str::random(4)]);
        $this->grantModule($tenant, 'PARTNER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        [, $token] = $this->makeTenantUser($tenant, ['partner.view']);
        $this->ctx = compact('tenant', 'branch', 'workshop', 'vehicle', 'token');
    }

    private function kpis(string $partnerId, ?string $token = null, array $query = ['from' => self::FROM, 'to' => self::TO])
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/v1/app/partners/{$partnerId}/performance?".http_build_query($query), $this->authHeaders($token ?? $this->ctx['token']));
    }

    private function workOrder(?array $ctx = null): string
    {
        $ctx ??= $this->ctx;
        $id = (string) Str::uuid();
        DB::table('work_orders')->insert([
            'id' => $id, 'wo_number' => 'WO-'.Str::random(8), 'tenant_id' => $ctx['tenant']->id, 'branch_id' => $ctx['branch']->id,
            'workshop_id' => $ctx['workshop']->id, 'vehicle_id' => $ctx['vehicle']->id, 'maintenance_type' => 'CORRECTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function externalInvoice(string $partnerId, array $values, ?array $ctx = null): void
    {
        $ctx ??= $this->ctx;
        DB::table('work_order_external_invoices')->insert($values + [
            'id' => (string) Str::uuid(), 'tenant_id' => $ctx['tenant']->id, 'branch_id' => $ctx['branch']->id,
            'work_order_id' => $this->workOrder($ctx), 'wal_workshop_partner_id' => $partnerId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_external_workshop_kpis_come_from_work_order_lifecycle(): void
    {
        $partner = $this->makePartner($this->ctx['tenant'], ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $other = $this->makePartner($this->ctx['tenant'], ['partner_type' => 'EXTERNAL_WORKSHOP']);

        // Paid: delivered → acknowledged 4h later → completed 20h after ack.
        $this->externalInvoice($partner->id, [
            'status' => 'PAID', 'wal_generated_at' => '2026-02-01 08:00:00', 'delivered_at' => '2026-02-01 09:00:00',
            'acknowledged_at' => '2026-02-01 13:00:00', 'completed_at' => '2026-02-02 09:00:00',
            'vendor_invoice_amount' => '1500000.0000', 'paid_amount' => '1500000.0000', 'payment_date' => '2026-02-10',
        ]);
        // Billed, not yet paid: ack 2h, completion 40h.
        $this->externalInvoice($partner->id, [
            'status' => 'BILLED', 'wal_generated_at' => '2026-03-01 08:00:00', 'delivered_at' => '2026-03-01 08:00:00',
            'acknowledged_at' => '2026-03-01 10:00:00', 'completed_at' => '2026-03-03 02:00:00', 'vendor_invoice_amount' => '250000.5050',
        ]);
        // Delivered then cancelled; in progress (acknowledged only).
        $this->externalInvoice($partner->id, ['status' => 'CANCELLED', 'wal_generated_at' => '2026-04-01 08:00:00', 'delivered_at' => '2026-04-01 09:00:00', 'cancelled_at' => '2026-04-02 08:00:00']);
        $this->externalInvoice($partner->id, ['status' => 'IN_PROGRESS', 'wal_generated_at' => '2026-05-01 08:00:00', 'delivered_at' => '2026-05-01 08:00:00', 'acknowledged_at' => '2026-05-01 14:00:00']);
        // Noise: outside the period, another partner.
        $this->externalInvoice($partner->id, ['status' => 'PAID', 'wal_generated_at' => '2025-12-31 23:00:00', 'vendor_invoice_amount' => '999', 'paid_amount' => '999', 'completed_at' => '2026-01-02 00:00:00']);
        $this->externalInvoice($other->id, ['status' => 'BILLED', 'wal_generated_at' => '2026-02-01 08:00:00', 'completed_at' => '2026-02-02 00:00:00', 'vendor_invoice_amount' => '777']);

        $data = $this->kpis($partner->id)->assertOk()->json('data');

        $this->assertSame('EXTERNAL_WORKSHOP', $data['category']);
        $this->assertSame(['from' => self::FROM, 'to' => self::TO], array_intersect_key($data['period'], ['from' => 1, 'to' => 1]));
        $k = $data['kpis'];
        // Rates/durations: JSON encodes 75.0 as 75, so compare numerically.
        $this->assertSame([4, 3, 2, 1, null], [$k['work_orders_assigned'], $k['acknowledged'], $k['completed'], $k['cancelled'], $k['rejected']]);
        $this->assertEquals([75.0, 50.0, 25.0], [$k['acknowledgement_rate'], $k['completion_rate'], $k['cancellation_rate']]);
        $this->assertEquals(4.0, $k['avg_acknowledgement_hours']); // (4 + 2 + 6) / 3
        $this->assertEquals(30.0, $k['avg_completion_hours']);     // (20 + 40) / 2
        $this->assertSame(['1750000.51', '1500000.00', '250000.51', 1], [$k['invoice_amount'], $k['paid_amount'], $k['outstanding_amount'], $k['invoices_paid']]);
    }

    public function test_supplier_kpis_come_from_purchase_orders_receipts_and_invoices(): void
    {
        $tenant = $this->ctx['tenant'];
        $partner = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER']);
        $warehouse = $this->makeWarehouse($tenant, $this->ctx['branch']);
        $product = $this->makeProduct($tenant);

        $po = function (string $status, string $orderDate, ?string $expected, string $total, ?string $partnerId = null) use ($tenant, $partner, $warehouse, $product) {
            $id = (string) Str::uuid();
            DB::table('purchase_orders')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'po_number' => 'PO-'.Str::random(8), 'partner_id' => $partnerId ?? $partner->id,
                'delivery_warehouse_id' => $warehouse->id, 'status' => $status, 'order_date' => $orderDate, 'expected_delivery_date' => $expected,
                'total' => $total, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $itemId = (string) Str::uuid();
            DB::table('purchase_order_items')->insert(['id' => $itemId, 'purchase_order_id' => $id, 'product_id' => $product->id, 'quantity_ordered' => 10, 'unit_price' => 1]);

            return [$id, $itemId];
        };
        $receipt = function (array $po, string $receivedAt, string $accepted, string $rejected, string $damaged = '0') use ($tenant, $partner, $warehouse, $product) {
            $id = (string) Str::uuid();
            DB::table('goods_receipts')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'gr_number' => 'GR-'.Str::random(8), 'purchase_order_id' => $po[0], 'warehouse_id' => $warehouse->id,
                'partner_id' => $partner->id, 'status' => 'POSTED', 'received_at' => $receivedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('goods_receipt_items')->insert([
                'id' => (string) Str::uuid(), 'goods_receipt_id' => $id, 'purchase_order_item_id' => $po[1], 'product_id' => $product->id,
                'quantity_accepted' => $accepted, 'quantity_rejected' => $rejected, 'quantity_damaged' => $damaged, 'unit_cost' => 1,
            ]);
        };
        $invoice = function (array $po, string $amount, bool $paid) use ($tenant, $partner) {
            $id = (string) Str::uuid();
            DB::table('vendor_invoice_references')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'partner_id' => $partner->id, 'purchase_order_id' => $po[0], 'vendor_invoice_number' => 'INV-'.Str::random(6),
                'vendor_invoice_date' => '2026-03-01', 'amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($paid) {
                DB::table('vendor_invoice_payments')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'vendor_invoice_reference_id' => $id, 'payment_date' => '2026-03-15',
                    'amount' => $amount, 'proof_disk' => 'local', 'proof_path' => 'x', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        };

        $onTime = $po('RECEIVED', '2026-02-01', '2026-02-10', '1000.0000');
        $receipt($onTime, '2026-02-08 10:00:00', '8', '2');            // first receipt on time (7 days lead)
        $receipt($onTime, '2026-02-20 10:00:00', '0', '0', '1');        // later partial receipt does not change on-time
        $invoice($onTime, '1000.0000', true);
        $late = $po('PARTIALLY_RECEIVED', '2026-03-01', '2026-03-05', '500.5050');
        $receipt($late, '2026-03-10 10:00:00', '5', '0');               // late (9 days lead)
        $invoice($late, '500.5050', false);
        $po('ISSUED', '2026-04-01', '2026-04-10', '300.0000');          // not yet delivered
        $po('CANCELLED', '2026-04-02', null, '9999.0000');               // cancelled, value excluded
        $po('RECEIVED', '2025-12-01', '2025-12-05', '7777.0000');        // outside the period
        $po('ISSUED', '2026-02-01', null, '5555.0000', $this->makePartner($tenant, ['partner_type' => 'SUPPLIER'])->id);

        $data = $this->kpis($partner->id)->assertOk()->json('data');
        $this->assertSame('SUPPLIER', $data['category']);
        $k = $data['kpis'];
        $this->assertSame([3, 1, 1, '1800.51'], [$k['purchase_orders_issued'], $k['purchase_orders_cancelled'], $k['purchase_orders_fully_received'], $k['purchase_order_value']]);
        $this->assertEquals([2, 1, 1, 50.0, 8.0], [$k['deliveries'], $k['deliveries_on_time'], $k['deliveries_late'], $k['on_time_rate'], $k['avg_lead_time_days']]);
        $this->assertEquals(['13', '2', '1', 13.3], [$k['quantity_accepted'], $k['quantity_rejected'], $k['quantity_damaged'], $k['rejection_rate']]);
        $this->assertSame([2, '1500.51', '1000.00', '500.51'], [$k['invoices'], $k['invoice_amount'], $k['paid_amount'], $k['outstanding_amount']]);
    }

    public function test_service_provider_kpis_come_from_external_services_and_workshop_invoices(): void
    {
        $tenant = $this->ctx['tenant'];
        [$user] = $this->makeTenantUser($tenant, []);
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);
        $service = function (string $status, string $requestedAt, ?string $completedAt, string $cost) use ($tenant, $partner) {
            $id = (string) Str::uuid();
            DB::table('work_order_external_services')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'work_order_id' => $this->workOrder(), 'partner_id' => $partner->id, 'description' => 'Tow',
                'status' => $status, 'requested_at' => $requestedAt, 'completed_at' => $completedAt, 'cost' => $cost, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        };
        $invoice = function (string $serviceId, string $amount, bool $paid) use ($tenant, $partner, $user) {
            $id = (string) Str::uuid();
            $number = 'TOW-'.Str::random(6);
            DB::table('workshop_invoices')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'work_order_external_service_id' => $serviceId,
                'work_order_id' => DB::table('work_order_external_services')->where('id', $serviceId)->value('work_order_id'),
                'partner_id' => $partner->id, 'external_invoice_number' => $number, 'external_invoice_number_normalized' => strtolower($number),
                'invoice_date' => '2026-03-01', 'total_amount' => $amount, 'status' => 'RECORDED', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($paid) {
                DB::table('workshop_invoice_payments')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'workshop_invoice_id' => $id, 'payment_date' => '2026-03-10', 'paid_amount' => $amount,
                    'evidence_url' => 'x', 'uploaded_by' => $user->id, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        };

        $invoice($service('PAID', '2026-02-01 08:00:00', '2026-02-01 12:00:00', '400000'), '400000', true);
        $invoice($service('BILLED', '2026-02-05 08:00:00', '2026-02-05 16:00:00', '600000'), '650000.1250', false);
        $service('CANCELLED', '2026-03-01 08:00:00', null, '100000');
        $service('PAID', '2025-11-01 08:00:00', '2025-11-01 09:00:00', '5000');   // outside the period

        $k = $this->kpis($partner->id)->assertOk()->json('data.kpis');
        $this->assertEquals([3, 2, 1, 66.7, 33.3, 6.0], [$k['services_requested'], $k['services_completed'], $k['services_cancelled'], $k['completion_rate'], $k['cancellation_rate'], $k['avg_completion_hours']]);
        $this->assertSame(['1000000.00', 2, '1050000.13', '400000.00', '650000.13'], [$k['estimated_cost'], $k['invoices'], $k['invoice_amount'], $k['paid_amount'], $k['outstanding_amount']]);
    }

    public function test_performance_is_tenant_scoped_permission_checked_and_period_validated(): void
    {
        $partner = $this->makePartner($this->ctx['tenant'], ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $default = $this->kpis($partner->id, null, [])->assertOk()->json('data');
        $this->assertSame(now()->toDateString(), $default['period']['to']);
        $this->assertSame(0, $default['kpis']['work_orders_assigned']);
        $this->assertNull($default['kpis']['acknowledgement_rate']);
        $this->assertSame('0.00', $default['kpis']['invoice_amount']);

        $this->kpis($partner->id, null, ['from' => '2026-06-30', 'to' => '2026-01-01'])->assertStatus(422);

        $other = $this->makeTenant(['code' => 'VPX-'.Str::random(4)]);
        $this->grantModule($other, 'PARTNER');
        [, $foreign] = $this->makeTenantUser($other, ['partner.view']);
        $this->kpis($partner->id, $foreign)->assertNotFound();

        [, $noView] = $this->makeTenantUser($this->ctx['tenant'], []);
        $this->kpis($partner->id, $noView)->assertForbidden();
    }
}
