<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProof;
use App\Domain\Shared\Services\PrivateDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * A document chosen in a form is saved together with its record: in the same request, stored
 * privately, recoverable afterwards — and a failed save leaves neither a half-saved record nor
 * an orphan file.
 */
class DocumentUploadPersistenceTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    /** A real file on disk, so the server sniffs its actual content (not the client name). */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function invoiceAndToken(): array
    {
        $tenant = $this->makeTenant();
        $contract = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => true]));
        [, $token] = $this->makeTenantUser($tenant, ['account.payment.submit', 'account.payment.view']);

        return [$contract->subscription->invoices()->first(), $this->authHeaders($token)];
    }

    private function paymentPayload($invoice, array $extra = []): array
    {
        return array_merge(['invoice_id' => $invoice->id, 'payment_date' => now()->toDateString(), 'amount' => $invoice->total, 'payment_method' => 'BANK_TRANSFER'], $extra);
    }

    public function test_payment_and_its_proof_are_saved_in_one_request_and_the_proof_is_retrievable(): void
    {
        [$invoice, $headers] = $this->invoiceAndToken();

        $id = $this->post('/api/v1/app/account/payments', $this->paymentPayload($invoice, ['file' => $this->realFile('transfer.png', self::PNG)]), $headers)
            ->assertCreated()->json('data.id');

        $proof = PaymentProof::query()->where('payment_id', $id)->sole();
        $this->assertSame(['transfer.png', 'image/png'], [$proof->original_filename, $proof->mime_type]);
        Storage::disk('local')->assertExists($proof->path);
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/app/account/payments/{$id}/proofs/{$proof->id}", $headers)->assertOk();
    }

    public function test_a_rejected_proof_saves_nothing_so_a_retry_cannot_duplicate_the_payment(): void
    {
        [$invoice, $headers] = $this->invoiceAndToken();

        $this->post('/api/v1/app/account/payments', $this->paymentPayload($invoice, ['file' => $this->realFile('proof.pdf', 'not really a pdf')]), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, Payment::query()->where('invoice_id', $invoice->id)->count(), 'No payment without its proof.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No stored file.');

        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/app/account/payments', $this->paymentPayload($invoice, ['file' => $this->realFile('proof.png', self::PNG)]), $headers)->assertCreated();
        $this->assertSame(1, Payment::query()->where('invoice_id', $invoice->id)->count(), 'The corrected retry creates exactly one payment.');
    }

    public function test_payment_without_a_proof_still_works(): void
    {
        [$invoice, $headers] = $this->invoiceAndToken();

        $id = $this->postJson('/api/v1/app/account/payments', $this->paymentPayload($invoice), $headers)->assertCreated()->json('data.id');
        $this->assertSame(0, PaymentProof::query()->where('payment_id', $id)->count());
    }

    public function test_storage_removes_the_file_when_the_record_cannot_be_saved(): void
    {
        $storage = app(PrivateDocumentStorage::class);
        $upload = ['file' => $this->realFile('doc.png', self::PNG), 'directory' => 'test-docs/t1', 'mimes' => ['image/png'], 'max_bytes' => 1024 * 1024, 'label' => 'document'];

        try {
            $storage->persist(['document' => $upload], function (array $stored) {
                Storage::disk('local')->assertExists($stored['document']['path']);
                throw new RuntimeException('database failure');
            });
            $this->fail('The failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('database failure', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No orphan file after a failed save.');

        $this->expectException(ValidationException::class);
        $storage->persist(['document' => array_merge($upload, ['file' => $this->realFile('doc.pdf', 'plain text')])], fn () => null);
    }
}
