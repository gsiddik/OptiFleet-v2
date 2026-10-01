<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Work Order → Documents for an External Workshop Work Order: acknowledged WAL (only once
 * acknowledged, with its upload time), External Workshop Invoice (with the invoice's own date)
 * and Payment Proof (with the payment date). Each file is served with its real content type.
 */
class WorkOrderDocumentsTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";

    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    private const JPG = "\xFF\xD8\xFF\xE0\0\x10JFIF\0\x01\x01\0\0\x01\0\x01\0\0\xFF\xD9";

    private const PERMISSIONS = [
        'work_order.view', 'work_order.create', 'work_order.prepare_external', 'work_order.finalize_external',
        'external_work_order_invoice.view', 'external_work_order_invoice.generate_authorization',
        'external_work_order_invoice.deliver', 'external_work_order_invoice.acknowledge',
        'external_work_order_invoice.complete', 'external_work_order_invoice.settle',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wod');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOD-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        [, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);

        return [$tenant, $workshop, $vehicle, $this->authHeaders($token)];
    }

    private function send(string $method, string $uri, array $headers, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        $headers += ['Accept' => 'application/json'];

        return in_array($method, ['get', 'getJson'], true) ? $this->{$method}($uri, $headers) : $this->{$method}($uri, $data, $headers);
    }

    private function documents(string $workOrderId, array $headers): array
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson("/api/v1/app/work-orders/{$workOrderId}/documents", $headers)->assertOk()->json('data');
    }

    private function externalWorkOrder($tenant, $workshop, $vehicle, array $headers): array
    {
        $id = $this->send('postJson', '/api/v1/app/work-orders', $headers, [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ])->assertCreated()->json('data.id');
        $this->send('postJson', "/api/v1/app/work-orders/{$id}/execution-mode/external", $headers)->assertOk();
        $this->send('postJson', "/api/v1/app/work-orders/{$id}/external-findings", $headers, ['severity' => 'HIGH', 'description' => 'Cracked head.'])->assertCreated();
        $this->send('postJson', "/api/v1/app/work-orders/{$id}/external", $headers)->assertOk();
        $invoiceId = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->value('id');
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->send('postJson', "/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", $headers, ['partner_id' => $partner->id])->assertOk();

        return [$id, $invoiceId];
    }

    public function test_documents_follow_the_external_workshop_lifecycle_and_open_with_their_real_type(): void
    {
        [$tenant, $workshop, $vehicle, $headers] = $this->scenario();
        [$woId, $invoiceId] = $this->externalWorkOrder($tenant, $workshop, $vehicle, $headers);

        // Generated + delivered, not yet acknowledged → no WAL document.
        $this->send('postJson', "/api/v1/app/external-work-order-invoices/{$invoiceId}/deliver", $headers)->assertOk();
        $this->assertSame([], $this->documents($woId, $headers)['documents']);
        $this->assertSame('EXTERNAL', $this->documents($woId, $headers)['execution_mode']);

        // Acknowledged (signed WAL uploaded) → WAL listed with its upload time.
        $this->send('post', "/api/v1/app/external-work-order-invoices/{$invoiceId}/acknowledge", $headers, ['file' => $this->realFile('signed-wal.pdf', self::PDF)])->assertOk();
        $docs = $this->documents($woId, $headers)['documents'];
        $this->assertSame(['WORK_AUTHORIZATION_LETTER'], array_column($docs, 'type'));
        $this->assertSame(['ACKNOWLEDGED', 'UPLOADED_AT', 'signed-wal.pdf', 'application/pdf'], [$docs[0]['status'], $docs[0]['date_kind'], $docs[0]['file']['name'], $docs[0]['file']['mime_type']]);
        $this->assertNotNull($docs[0]['date']);
        $this->assertSame("/app/external-work-order-invoices/{$invoiceId}/acknowledgement", $docs[0]['path']);
        $this->assertStringContainsString('application/pdf', $this->send('get', '/api/v1'.$docs[0]['path'], $headers)->assertOk()->headers->get('Content-Type'));

        // Completed: the invoice date is the invoice's own date, not the upload time; PNG stays PNG.
        $this->send('post', "/api/v1/app/external-work-order-invoices/{$invoiceId}/complete", $headers, [
            'completed_work_order_file' => $this->realFile('completed.pdf', self::PDF),
            'vendor_invoice_file' => $this->realFile('workshop-invoice.png', self::PNG),
            'vendor_invoice_date' => '2026-09-15', 'vendor_invoice_amount' => '1500000', 'payment_term' => 'NET 30',
        ])->assertOk();
        $invoice = collect($this->documents($woId, $headers)['documents'])->firstWhere('type', 'EXTERNAL_WORKSHOP_INVOICE');
        $this->assertSame(['2026-09-15', 'INVOICE_DATE', '1500000.0000', 'image/png'], [$invoice['date'], $invoice['date_kind'], $invoice['amount'], $invoice['file']['mime_type']]);
        $served = $this->send('get', '/api/v1'.$invoice['path'], $headers)->assertOk();
        $this->assertStringContainsString('image/png', $served->headers->get('Content-Type'));
        $this->assertSame(self::PNG, $served->streamedContent());

        // Settled → payment proof with the payment date; JPG stays JPG.
        $this->send('post', "/api/v1/app/external-work-order-invoices/{$invoiceId}/settle", $headers, [
            'payment_proof_file' => $this->realFile('transfer.jpg', self::JPG), 'payment_date' => '2026-09-20', 'paid_amount' => '1500000',
        ])->assertOk();
        $docs = $this->documents($woId, $headers)['documents'];
        $this->assertSame(['WORK_AUTHORIZATION_LETTER', 'EXTERNAL_WORKSHOP_INVOICE', 'PAYMENT_PROOF'], array_column($docs, 'type'));
        $proof = $docs[2];
        $this->assertSame(['2026-09-20', 'PAYMENT_DATE', 'image/jpeg'], [$proof['date'], $proof['date_kind'], $proof['file']['mime_type']]);
        $this->assertStringContainsString('image/jpeg', $this->send('get', '/api/v1'.$proof['path'], $headers)->assertOk()->headers->get('Content-Type'));
    }

    public function test_internal_work_orders_have_no_external_documents_and_access_is_scoped(): void
    {
        [$tenant, $workshop, $vehicle, $headers] = $this->scenario();
        $internalId = $this->send('postJson', '/api/v1/app/work-orders', $headers, [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ])->assertCreated()->json('data.id');
        $this->assertSame(['execution_mode' => 'INTERNAL', 'external_invoice_id' => null, 'documents' => []], $this->documents($internalId, $headers));

        [$externalId] = $this->externalWorkOrder($tenant, $workshop, $vehicle, $headers);
        $other = $this->makeTenant(['code' => 'WOX-'.Str::random(4)]);
        $this->grantModule($other, 'WORK_ORDER');
        [, $foreign] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->send('getJson', "/api/v1/app/work-orders/{$externalId}/documents", $this->authHeaders($foreign))->assertNotFound();
        [, $noView] = $this->makeTenantUser($tenant, ['external_work_order_invoice.view']);
        $this->send('getJson', "/api/v1/app/work-orders/{$externalId}/documents", $this->authHeaders($noView))->assertForbidden();
    }
}
