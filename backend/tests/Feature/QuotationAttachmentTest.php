<?php

namespace Tests\Feature;

use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Models\VendorQuotation;
use Database\Seeders\DemoQuotationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Record Quotation requires the vendor's quotation document (PDF / DOC / DOCX, type checked from
 * the file content), which authorized users can then view or download. A vendor already in
 * Quotation Comparison cannot be recorded again (owner decision), and only invited vendors quote.
 */
class QuotationAttachmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function setUpRfq(): array
    {
        $tenant = $this->makeTenant(['code' => 'QAT-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Brake Pad Set']);
        [, $token] = $this->makeTenantUser($tenant, ['rfq.view', 'rfq.manage', 'quotation.view', 'quotation.manage']);
        $headers = $this->authHeaders($token);
        $rfq = Rfq::query()->findOrFail($this->postJson('/api/v1/app/rfqs', ['warehouse_id' => $warehouse->id, 'items' => [['product_id' => $product->id, 'quantity' => 10]]], $headers)->json('data.id'));
        $vendorA = $this->makePartner($tenant, ['name' => 'Vendor A']);
        $vendorB = $this->makePartner($tenant, ['name' => 'Vendor B', 'partner_type' => 'SUPPLIER']);
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$vendorA->id, $vendorB->id]], $headers)->assertOk();

        return [$tenant, $warehouse, $rfq, $product, $vendorA, $vendorB, $headers];
    }

    private function record(Rfq $rfq, string $partnerId, string $productId, ?UploadedFile $file, array $headers, float $unitPrice = 12)
    {
        $payload = ['partner_id' => $partnerId, 'lead_time_days' => 7, 'items' => [['rfq_item_id' => $rfq->items()->first()->id, 'product_id' => $productId, 'quantity' => 10, 'unit_price' => $unitPrice]]];

        return $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", $file ? $payload + ['attachment' => $file] : $payload, $headers);
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qdoc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function docx(bool $wordPackage = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString($wordPackage ? 'word/document.xml' : 'data/readme.txt', '<w:document/>');
        $zip->close();

        return new UploadedFile($path, 'quotation.docx', null, null, true);
    }

    public function test_quotation_document_is_required(): void
    {
        [, , $rfq, $product, $vendorA, , $headers] = $this->setUpRfq();

        $this->record($rfq, $vendorA->id, $product->id, null, $headers)->assertStatus(422)->assertJsonValidationErrors('attachment');
        $this->assertSame(0, VendorQuotation::query()->count());
    }

    public function test_pdf_doc_and_docx_are_accepted_and_viewable_or_downloadable(): void
    {
        [$tenant, $warehouse, $rfq, $product, $vendorA, $vendorB, $headers] = $this->setUpRfq();

        $pdf = $this->record($rfq, $vendorA->id, $product->id, DemoQuotationDocument::make('Vendor A'), $headers)->assertCreated();
        $this->assertTrue($pdf->json('data.has_attachment'));
        $this->assertArrayNotHasKey('attachment_path', $pdf->json('data'), 'Storage path never exposed.');
        $this->assertSame('demo-quotation.pdf', $pdf->json('data.attachment_original_filename'));
        Storage::disk('local')->assertExists(VendorQuotation::query()->findOrFail($pdf->json('data.id'))->attachment_path);

        $this->record($rfq, $vendorB->id, $product->id, $this->docx(), $headers)->assertCreated()->assertJsonPath('data.attachment_mime_type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $vendorC = $this->makePartner($tenant, ['name' => 'Vendor C']);
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$vendorC->id]], $headers)->assertOk();
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 1024);
        $this->record($rfq, $vendorC->id, $product->id, $this->realFile('quotation.doc', $ole), $headers)->assertCreated()->assertJsonPath('data.attachment_mime_type', 'application/msword');

        $this->app['auth']->forgetGuards();
        $inline = $this->get("/api/v1/app/quotations/{$pdf->json('data.id')}/attachment", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->app['auth']->forgetGuards();
        $download = $this->get("/api/v1/app/quotations/{$pdf->json('data.id')}/attachment?download=1", $headers)->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));

        $compare = collect($this->getJson("/api/v1/app/rfqs/{$rfq->id}/compare", $headers)->assertOk()->json('data'));
        $this->assertTrue($compare->every(fn ($row) => $row['has_attachment'] === true));
    }

    public function test_unsupported_or_disguised_documents_are_rejected(): void
    {
        [, , $rfq, $product, $vendorA, , $headers] = $this->setUpRfq();
        $cases = [
            $this->realFile('quotation.pdf', 'plain text pretending to be a PDF'),
            $this->realFile('quotation.exe', "MZ\x90\x00binary"),
            $this->docx(wordPackage: false),
            UploadedFile::fake()->image('quotation.png'),
            UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ];
        foreach ($cases as $file) {
            $this->record($rfq, $vendorA->id, $product->id, $file, $headers)->assertStatus(422)->assertJsonValidationErrors('attachment');
        }
        $this->assertSame(0, VendorQuotation::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Rejected uploads leave no file behind.');
    }

    public function test_submitted_vendor_cannot_be_recorded_again_and_only_invited_vendors_quote(): void
    {
        [$tenant, , $rfq, $product, $vendorA, , $headers] = $this->setUpRfq();

        $this->record($rfq, $vendorA->id, $product->id, DemoQuotationDocument::make('A'), $headers, 12)->assertCreated();
        $this->record($rfq, $vendorA->id, $product->id, DemoQuotationDocument::make('A again'), $headers, 9)
            ->assertStatus(422)->assertJsonPath('errors.partner_id.0', 'Vendor A has already submitted a quotation for this RFQ.');
        $this->assertSame('120.0000', VendorQuotation::query()->where('partner_id', $vendorA->id)->value('total'), 'The first quotation is never overwritten.');
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $uninvited = $this->makePartner($tenant);
        $this->record($rfq, $uninvited->id, $product->id, DemoQuotationDocument::make('X'), $headers)->assertStatus(422)->assertJsonValidationErrors('partner_id');

        $otherProduct = $this->makeProduct($tenant);
        $vendorB = $rfq->vendors()->where('partners.id', '!=', $vendorA->id)->first();
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendorB->id, 'attachment' => DemoQuotationDocument::make('B'),
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1, 'unit_price' => 5]],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/close", [], $headers)->assertOk();
        $this->record($rfq, $vendorB->id, $product->id, DemoQuotationDocument::make('late'), $headers)->assertStatus(422);
    }

    public function test_attachment_access_is_authorized(): void
    {
        [$tenant, $warehouse, $rfq, $product, $vendorA, , $headers] = $this->setUpRfq();
        $id = $this->record($rfq, $vendorA->id, $product->id, DemoQuotationDocument::make('A'), $headers)->assertCreated()->json('data.id');
        $open = function (array $h) use ($id) {
            $this->app['auth']->forgetGuards();

            return $this->get("/api/v1/app/quotations/{$id}/attachment", $h + ['Accept' => 'application/json']);
        };

        [, $noPerm] = $this->makeTenantUser($tenant, ['rfq.view']);
        $open($this->authHeaders($noPerm))->assertForbidden();
        $scopedWarehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        [, $outOfScope] = $this->makeTenantUser($tenant, ['quotation.view'], ['WAREHOUSE' => $scopedWarehouse->id]);
        $open($this->authHeaders($outOfScope))->assertForbidden();

        $other = $this->makeTenant(['code' => 'QATX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, ['quotation.view']);
        $open($this->authHeaders($foreign))->assertNotFound();

        $legacy = VendorQuotation::query()->withoutGlobalScopes()->findOrFail($id);
        $legacy->update(['attachment_path' => null]);
        $open($headers)->assertNotFound();
    }
}
