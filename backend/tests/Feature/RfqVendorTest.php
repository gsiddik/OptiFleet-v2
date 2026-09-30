<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Configuration\Services\TemplateRenderer;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Support\RfqDocumentTemplate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RFQ Invited Vendors: only active Supplier / Spare Part Supplier / Tire Supplier partners of the
 * tenant can be invited; each invited vendor gets its own printed RFQ document.
 */
class RfqVendorTest extends TestCase
{
    private function setUpRfq(): array
    {
        $tenant = $this->makeTenant(['code' => 'RFV-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant), null, ['name' => 'Jakarta Central']);
        $product = $this->makeProduct($tenant, null, $this->makeUom(['code' => 'PCS-'.Str::random(3)]), ['name' => 'Brake Pad Set', 'sku' => 'SP-BRK-000001']);
        [$user, $token] = $this->makeTenantUser($tenant, ['rfq.view', 'rfq.manage']);
        $headers = $this->authHeaders($token);
        $rfqId = $this->postJson('/api/v1/app/rfqs', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $product->id, 'quantity' => 4]]], $headers)->assertCreated()->json('data.id');

        return [$tenant, Rfq::query()->findOrFail($rfqId), $headers, $user];
    }

    public function test_only_active_goods_supplier_types_of_the_tenant_can_be_invited(): void
    {
        [$tenant, $rfq, $headers] = $this->setUpRfq();
        $invite = fn (string $partnerId) => $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$partnerId]], $headers);

        foreach (['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER'] as $type) {
            $invite($this->makePartner($tenant, ['partner_type' => $type, 'name' => $type])->id)->assertOk();
        }
        $invite($this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP'])->id)->assertStatus(422)->assertJsonValidationErrors('partner_ids.0');
        $invite($this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER'])->id)->assertStatus(422);
        $invite($this->makePartner($tenant, ['status' => 'INACTIVE'])->id)->assertStatus(422);
        $invite($this->makePartner($this->makeTenant(['code' => 'RFVX-'.Str::random(4)]))->id)->assertStatus(422);

        $this->assertSame(3, $rfq->vendors()->count());
        $this->assertSame('ISSUED', $rfq->fresh()->status);

        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/cancel", [], $headers)->assertOk();
        $invite($this->makePartner($tenant)->id)->assertStatus(422);
    }

    public function test_each_invited_vendor_gets_its_own_rfq_document(): void
    {
        [$tenant, $rfq, $headers, $user] = $this->setUpRfq();
        $vendor = $this->makePartner($tenant, ['name' => 'PT Sinar Suku Cadang', 'address' => 'Jl. Industri 5']);
        $other = $this->makePartner($tenant, ['name' => 'PT Ban Prima', 'partner_type' => 'TIRE_SUPPLIER']);
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$vendor->id, $other->id]], $headers)->assertOk();

        $this->app['auth']->forgetGuards();
        $pdf = $this->get("/api/v1/app/rfqs/{$rfq->id}/vendors/{$vendor->id}/print", $headers)->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $html = app(TemplateRenderer::class)->render(RfqDocumentTemplate::html(), DocumentTemplateContextBuilder::forRfqVendor($rfq->fresh(), $vendor, $user->name) + ['template_version' => 1, 'generated_at' => 'now']);
        foreach ([$rfq->rfq_number, 'PT Sinar Suku Cadang', 'Jl. Industri 5', 'Jakarta Central', 'Brake Pad Set', 'SP-BRK-000001', 'unit price', 'lead time, in days) after you receive our Purchase Order', $user->name] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('<td style="text-align:right;">4</td>', $html, 'Quantity printed without decimals.');
        $this->assertStringNotContainsString('PT Ban Prima', $html, 'The document is addressed to one vendor only.');
    }

    public function test_print_requires_an_invited_vendor_permission_and_tenant(): void
    {
        [$tenant, $rfq, $headers] = $this->setUpRfq();
        $notInvited = $this->makePartner($tenant);
        $invited = $this->makePartner($tenant);
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$invited->id]], $headers)->assertOk();
        $print = function (string $partnerId, array $h) use ($rfq) {
            $this->app['auth']->forgetGuards();

            return $this->get("/api/v1/app/rfqs/{$rfq->id}/vendors/{$partnerId}/print", $h + ['Accept' => 'application/json']);
        };

        $print($notInvited->id, $headers)->assertNotFound();
        [, $noPerm] = $this->makeTenantUser($tenant, ['work_order.view']);
        $print($invited->id, $this->authHeaders($noPerm))->assertForbidden();

        $other = $this->makeTenant(['code' => 'RFVY-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, ['rfq.view']);
        $print($invited->id, $this->authHeaders($foreign))->assertNotFound();
    }
}
