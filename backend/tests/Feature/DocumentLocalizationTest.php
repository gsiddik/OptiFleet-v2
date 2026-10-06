<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Services\TemplateValidator;
use App\Domain\DocumentGeneration\Models\DocumentGeneration;
use App\Domain\DocumentGeneration\Services\DocumentGenerationService;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Procurement\Models\PurchaseOrder;
use Database\Seeders\AddExternalWorkOrderPrintSectionSeeder;
use Database\Seeders\AddLocalizedDocumentTemplatesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * i18n (documents): printed documents follow their generation's locale — template labels, status values,
 * dates and numbers — while business data (names, numbers, notes) is printed as stored. A reprint keeps the
 * locale and template version it was generated with.
 */
class DocumentLocalizationTest extends TestCase
{
    private function platformTemplate(string $code): ?ConfigurationSet
    {
        return ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'TEMPLATE')->where('code', $code)->first();
    }

    public function test_every_platform_default_template_gets_an_indonesian_body_once(): void
    {
        $this->seed(AddExternalWorkOrderPrintSectionSeeder::class);
        $this->seed(AddLocalizedDocumentTemplatesSeeder::class);
        $validator = app(TemplateValidator::class);
        $versions = [];
        foreach (ConfigurationSet::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('type', 'TEMPLATE')->get() as $set) {
            $payload = $set->publishedVersion()->payload;
            $this->assertArrayHasKey('id', $payload['locales'] ?? [], "{$set->code} has an Indonesian body");
            $this->assertStringNotContainsString('.status}}', $payload['html'], "{$set->code} prints status labels");
            $validator->validate($set->code, $payload['locales']['id']['html']);
            $versions[$set->code] = $set->versions()->count();
        }
        $this->assertGreaterThanOrEqual(15, count($versions));

        $this->seed(AddLocalizedDocumentTemplatesSeeder::class);
        foreach ($versions as $code => $count) {
            $this->assertSame($count, $this->platformTemplate($code)->versions()->count(), "{$code}: idempotent");
        }
    }

    public function test_a_purchase_order_prints_in_its_generation_locale_and_a_reprint_keeps_it(): void
    {
        $this->seed(AddLocalizedDocumentTemplatesSeeder::class);
        $tenant = $this->makeTenant(['code' => 'DOCL-'.Str::random(4)]);
        foreach (['INVENTORY', 'PROCUREMENT', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant, overrides: ['name' => 'Kampas Rem Depan']);
        $vendor = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER', 'name' => 'PT Sumber Makmur']);
        [$user, $token] = $this->makeTenantUser($tenant, ['purchase_order.view', 'purchase_order.create']);
        $poId = $this->postJson('/api/v1/app/purchase-orders', [
            'partner_id' => $vendor->id, 'delivery_warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity_ordered' => 1250, 'unit_price' => '15000.50']],
        ], $this->authHeaders($token))->assertStatus(201)->json('data.id');
        $po = PurchaseOrder::query()->withoutGlobalScopes()->findOrFail($poId);
        $source = new DocumentSource('purchase_order', 'purchase_order', $po->id, $po->tenant_id, 'po.pdf',
            fn (string $locale) => DocumentTemplateContextBuilder::forPurchaseOrder($po, $locale), warehouseId: $po->delivery_warehouse_id);
        $service = app(DocumentGenerationService::class);

        $id = $service->generate($source, 'id', $user);
        $en = $service->generate($source, 'en', $user);
        $idHtml = $service->renderHtml($source, $id);
        $enHtml = $service->renderHtml($source, $en);

        // Labels and the status follow the locale …
        $this->assertStringContainsString('Disiapkan oleh', $idHtml);
        $this->assertStringContainsString('Prepared by', $enHtml);
        $this->assertStringContainsString('Status: Draf', $idHtml);
        $this->assertStringContainsString('Status: Draft', $enHtml);
        // … numbers too (D3) …
        $this->assertStringContainsString('15.000,50', $idHtml);
        $this->assertStringContainsString('15,000.50', $enHtml);
        // … business data is printed as stored, in both.
        foreach ([$idHtml, $enHtml] as $html) {
            $this->assertStringContainsString('PT Sumber Makmur', $html);
            $this->assertStringContainsString('Kampas Rem Depan', $html);
            $this->assertStringContainsString($po->po_number, $html);
        }

        // Reprint: a later language change of the user does not change an existing generation.
        $user->forceFill(['preferred_locale' => 'en'])->save();
        $again = $service->forPrint($source, $id->id, null, $user->fresh());
        $this->assertSame('id', $again->locale);
        $this->assertStringContainsString('Disiapkan oleh', $service->renderHtml($source, $again));
        $this->assertSame(2, DocumentGeneration::query()->where('source_entity_id', $po->id)->count());
    }

    public function test_the_platform_invoice_prints_in_the_document_locale(): void
    {
        $invoice = new \App\Domain\Invoice\Models\Invoice();
        $invoice->forceFill([
            'invoice_number' => 'INV/OPTIFLEET/2026/000123', 'status' => 'PAID', 'currency' => 'IDR',
            'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31',
            'subtotal' => '1500000.00', 'discount' => '0', 'tax' => '165000.00', 'adjustment' => '0',
            'total' => '1665000.00', 'paid_amount' => '1665000.00', 'outstanding_amount' => '0',
        ]);
        $invoice->setRelation('tenant', (new \App\Domain\Identity\Models\Tenant())->forceFill(['name' => 'PT Armada Jaya', 'code' => 'ARJ']));
        $invoice->setRelation('contract', (new \App\Domain\Contract\Models\Contract())->forceFill(['contract_number' => 'CTR/OPTIFLEET/2026/000001']));
        $invoice->setRelation('items', collect([(new \App\Domain\Invoice\Models\InvoiceItem())->forceFill(['description' => 'Langganan modul Pemeliharaan', 'quantity' => '1.0000', 'unit_price' => '1500000.00', 'discount' => '0', 'tax' => '165000.00', 'amount' => '1665000.00'])]));

        $id = view('invoices.pdf', ['invoice' => $invoice, 'locale' => 'id'])->render();
        $en = view('invoices.pdf', ['invoice' => $invoice, 'locale' => 'en'])->render();

        $this->assertStringContainsString(__('catalog.documents.platformInvoice.billTo', [], 'id'), $id);
        $this->assertStringContainsString('Bill To', $en);
        $this->assertStringContainsString('1.665.000,00', $id);
        $this->assertStringContainsString('1,665,000.00', $en);
        $this->assertStringContainsString(__('catalog.status.paid', [], 'id'), $id);
        foreach ([$id, $en] as $html) {
            $this->assertStringContainsString('PT Armada Jaya', $html);
            $this->assertStringContainsString('Langganan modul Pemeliharaan', $html);
            $this->assertStringContainsString('INV/OPTIFLEET/2026/000123', $html);
        }
    }
}
