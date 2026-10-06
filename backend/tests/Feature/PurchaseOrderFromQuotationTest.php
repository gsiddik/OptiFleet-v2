<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Procurement\Services\RfqService;
use Database\Seeders\DemoQuotationDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Create Purchase Order from the selected quotation: goods, quantities and vendor unit prices come
 * from the quotation; the Order Date is mandatory and persisted; Expected Receipt Date =
 * Order Date + the quotation's Lead Days (calendar days), computed by the backend.
 */
class PurchaseOrderFromQuotationTest extends TestCase
{
    private function setUpSelectedQuotation(?int $leadDays = 7, bool $select = true): array
    {
        Storage::fake('local');
        $tenant = $this->makeTenant(['code' => 'POQ-'.Str::random(4)]);
        $this->grantModule($tenant, 'PROCUREMENT');
        $this->grantModule($tenant, 'INVENTORY');
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $pads = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        $filter = $this->makeProduct($tenant, null, null, ['name' => 'Oil Filter']);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Sinar Suku Cadang']);
        $service = app(RfqService::class);
        $rfq = $service->create($warehouse, [], [['product_id' => $pads->id, 'quantity' => 4], ['product_id' => $filter->id, 'quantity' => 10]]);
        $service->inviteVendors($rfq, [$vendor->id]);
        $quotation = $service->submitQuotation($rfq->fresh(), $vendor, ['lead_time_days' => $leadDays], [
            ['product_id' => $pads->id, 'quantity' => 4, 'unit_price' => 250000.5],
            ['product_id' => $filter->id, 'quantity' => 10, 'unit_price' => 85000],
        ], DemoQuotationDocument::make('PT Sinar Suku Cadang'), null);
        if ($select) {
            $service->selectVendor($quotation);
        }
        [, $token] = $this->makeTenantUser($tenant, ['purchase_order.create', 'purchase_order.view', 'quotation.view']);

        return [$tenant, $warehouse, $quotation->fresh(), $this->authHeaders($token), $pads, $filter];
    }

    private function createPo(VendorQuotation $quotation, array $payload, array $headers)
    {
        return $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", $payload, $headers);
    }

    public function test_order_date_is_persisted_and_expected_receipt_is_order_date_plus_lead_days(): void
    {
        [, $warehouse, $quotation, $headers] = $this->setUpSelectedQuotation(7);

        $response = $this->createPo($quotation, [
            'delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01',
            'expected_delivery_date' => '2027-01-01', // client value is ignored: the date is derived
        ], $headers)->assertCreated();

        $po = PurchaseOrder::query()->findOrFail($response->json('data.id'));
        $this->assertSame('2026-10-01', $po->order_date->toDateString());
        $this->assertSame('2026-10-08', $po->expected_delivery_date->toDateString());

        // Printed dates follow the document locale (i18n D3); the stored dates above stay ISO.
        $context = DocumentTemplateContextBuilder::forPurchaseOrder($po);
        $this->assertSame(['October 1, 2026', 'October 8, 2026'], [$context['purchase_order']['order_date'], $context['purchase_order']['expected_delivery_date']]);
        $context = DocumentTemplateContextBuilder::forPurchaseOrder($po, 'id');
        $this->assertSame(['1 Oktober 2026', '8 Oktober 2026'], [$context['purchase_order']['order_date'], $context['purchase_order']['expected_delivery_date']]);
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/app/purchase-orders/{$po->id}/print", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_expected_receipt_date_uses_calendar_days_and_is_empty_without_lead_time(): void
    {
        $this->assertSame('2026-11-04', PurchaseOrderService::expectedReceiptDate('2026-10-28', 7), 'Across a month end.');
        $this->assertSame('2027-01-02', PurchaseOrderService::expectedReceiptDate('2026-12-31', 2), 'Across a year end.');
        $this->assertSame('2026-10-03', PurchaseOrderService::expectedReceiptDate('2026-10-03', 0));
        $this->assertNull(PurchaseOrderService::expectedReceiptDate('2026-10-03', null));

        [, $warehouse, $quotation, $headers] = $this->setUpSelectedQuotation(null);
        $id = $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'], $headers)->assertCreated()->json('data.id');
        $this->assertNull(PurchaseOrder::query()->findOrFail($id)->expected_delivery_date);
    }

    public function test_order_date_is_required_and_must_be_a_real_date(): void
    {
        [, $warehouse, $quotation, $headers] = $this->setUpSelectedQuotation();

        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id], $headers)->assertStatus(422)->assertJsonValidationErrors('order_date');
        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-02-30'], $headers)->assertStatus(422)->assertJsonValidationErrors('order_date');
        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '01/10/2026'], $headers)->assertStatus(422)->assertJsonValidationErrors('order_date');
        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_po_lines_come_from_the_selected_quotation_not_the_client(): void
    {
        [, $warehouse, $quotation, $headers, $pads, $filter] = $this->setUpSelectedQuotation();

        $id = $this->createPo($quotation, [
            'delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01',
            'items' => [['product_id' => $pads->id, 'quantity_ordered' => 999, 'unit_price' => 1]], // ignored
        ], $headers)->assertCreated()->json('data.id');

        $lines = PurchaseOrder::query()->findOrFail($id)->items()->get()->keyBy('product_id');
        $this->assertCount(2, $lines);
        $this->assertSame(['4.0000', '250000.5000'], [$lines[$pads->id]->quantity_ordered, $lines[$pads->id]->unit_price]);
        $this->assertSame(['10.0000', '85000.0000'], [$lines[$filter->id]->quantity_ordered, $lines[$filter->id]->unit_price]);
        $this->assertSame('1850002.0000', PurchaseOrder::query()->findOrFail($id)->total);

        // The quotation the page displays (items, quantities, vendor prices) is the same data.
        $shown = $this->getJson("/api/v1/app/quotations/{$quotation->id}", $headers)->assertOk()->json('data.items');
        $this->assertEqualsCanonicalizing(['Brake Pad Set', 'Oil Filter'], array_column(array_column($shown, 'product'), 'name'));
    }

    public function test_only_a_selected_quotation_in_scope_can_become_a_po(): void
    {
        [$tenant, $warehouse, $quotation, $headers] = $this->setUpSelectedQuotation();
        [, $noPerm] = $this->makeTenantUser($tenant, ['purchase_order.view']);
        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'], $this->authHeaders($noPerm))->assertForbidden();

        $other = $this->makeTenant(['code' => 'POQX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, ['purchase_order.create']);
        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'], $this->authHeaders($foreign))->assertNotFound();

        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'], $headers)->assertCreated();
        $this->createPo($quotation, ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'], $headers)->assertStatus(422);
    }

    /** What every "Create PO" entry point reads: RFQ comparison, quotation detail and quotation list. */
    private function createPoState(VendorQuotation $quotation, array $headers): array
    {
        $this->app['auth']->forgetGuards();
        $compare = collect($this->getJson("/api/v1/app/rfqs/{$quotation->rfq_id}/compare", $headers)->assertOk()->json('data'))->firstWhere('quotation_id', $quotation->id);
        $show = $this->getJson("/api/v1/app/quotations/{$quotation->id}", $headers)->assertOk()->json('data');
        $listed = collect($this->getJson('/api/v1/app/quotations', $headers)->assertOk()->json('data'))->firstWhere('id', $quotation->id);

        return [
            'compare' => [$compare['can_create_purchase_order'], $compare['purchase_order']['po_number'] ?? null],
            'show' => [$show['can_create_purchase_order'], $show['purchase_order']['po_number'] ?? null],
            'list' => [$listed['can_create_purchase_order'], $listed['purchase_order']['po_number'] ?? null],
        ];
    }

    public function test_create_po_is_offered_and_accepted_only_while_the_quotation_is_selected_and_unconverted(): void
    {
        [$tenant, $warehouse, $quotation] = $this->setUpSelectedQuotation();
        [, $token] = $this->makeTenantUser($tenant, ['purchase_order.create', 'purchase_order.view', 'quotation.view']);
        $headers = $this->authHeaders($token);
        $payload = ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'];

        // Eligible: selected, no PO → offered everywhere, backend accepts.
        $this->assertSame(['compare' => [true, null], 'show' => [true, null], 'list' => [true, null]], $this->createPoState($quotation, $headers));
        $this->app['auth']->forgetGuards();
        $poNumber = $this->createPo($quotation, $payload, $headers)->assertCreated()->json('data.po_number');

        // Already converted → hidden everywhere (the PO is linked instead), direct API call rejected.
        $this->assertSame(['compare' => [false, $poNumber], 'show' => [false, $poNumber], 'list' => [false, $poNumber]], $this->createPoState($quotation, $headers));
        $this->app['auth']->forgetGuards();
        $this->createPo($quotation, $payload, $headers)->assertStatus(422)->assertJsonPath('message', 'This quotation has already been converted to a Purchase Order.');
        $this->assertSame(1, PurchaseOrder::query()->where('vendor_quotation_id', $quotation->id)->count());
    }

    public function test_quotations_of_a_closed_rfq_or_not_selected_cannot_become_a_po(): void
    {
        // A quotation's own status is SUBMITTED / SELECTED / REJECTED (DB check constraint); CLOSED is
        // the RFQ's status. An RFQ closed without choosing a vendor leaves its quotations SUBMITTED.
        [$tenant, $warehouse, $quotation] = $this->setUpSelectedQuotation(7, false);
        [, $token] = $this->makeTenantUser($tenant, ['purchase_order.create', 'purchase_order.view', 'quotation.view', 'quotation.select']);
        $headers = $this->authHeaders($token);
        $payload = ['delivery_warehouse_id' => $warehouse->id, 'order_date' => '2026-10-01'];

        app(RfqService::class)->close($quotation->rfq);
        $this->assertSame(['CLOSED', 'SUBMITTED'], [$quotation->rfq->fresh()->status, $quotation->fresh()->status]);
        $this->assertSame(['compare' => [false, null], 'show' => [false, null], 'list' => [false, null]], $this->createPoState($quotation, $headers));
        $this->app['auth']->forgetGuards();
        $this->createPo($quotation, $payload, $headers)->assertStatus(422)
            ->assertJsonPath('message', 'This quotation is SUBMITTED: only a selected quotation can be converted to a Purchase Order.');
        // …and it can no longer be selected on the closed RFQ (which would re-open the path to a PO).
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/app/quotations/{$quotation->id}/select", [], $headers)->assertStatus(422)
            ->assertJsonPath('message', 'This RFQ is CLOSED: a vendor can only be selected while it is ISSUED.');

        // A REJECTED quotation (another vendor was chosen) is never offered nor accepted.
        VendorQuotation::query()->whereKey($quotation->id)->update(['status' => 'REJECTED']);
        $this->assertSame(['compare' => [false, null], 'show' => [false, null], 'list' => [false, null]], $this->createPoState($quotation, $headers));
        $this->app['auth']->forgetGuards();
        $this->createPo($quotation, $payload, $headers)->assertStatus(422);
        $this->assertSame(0, PurchaseOrder::query()->where('vendor_quotation_id', $quotation->id)->count());
    }
}
