<?php

namespace App\Domain\Payment\Services;

use App\Domain\Invoice\Models\Invoice;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProof;
use App\Domain\Shared\Services\PrivateDocumentStorage;
use Illuminate\Http\UploadedFile;

/**
 * Tenant-facing payment submission and proof upload. File storage never
 * trusts the client filename (Section 52/33): a fresh UUID-based path is
 * generated, the original name is kept only as display metadata, and files
 * are written to the private `local` disk — retrieval always goes through
 * an authenticated, ownership-checked controller action, never a public URL.
 *
 * A proof sent with the submission is saved in the same operation as the payment (one
 * transaction, file removed again if it fails), so a rejected proof never leaves a payment
 * behind that a retry would duplicate.
 */
class PaymentSubmissionService
{
    public function __construct(private readonly PrivateDocumentStorage $storage) {}

    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

    public function submit(Invoice $invoice, array $attributes, string $submittedByUserId, ?UploadedFile $proof = null): Payment
    {
        if (in_array($invoice->status, ['VOID', 'PAID'], true)) {
            throw new PaymentException("Cannot submit a payment against an invoice with status {$invoice->status}.");
        }

        return $this->storage->persist(['file' => $this->proofUpload($proof, $invoice->tenant_id)], function (array $stored) use ($invoice, $attributes, $submittedByUserId) {
            $payment = Payment::query()->create(array_merge($attributes, [
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'status' => 'SUBMITTED',
                'submitted_by' => $submittedByUserId,
            ]));
            if ($stored['file']) {
                $this->recordProof($payment, $stored['file'], $submittedByUserId);
            }

            return $payment;
        });
    }

    public function attachProof(Payment $payment, UploadedFile $file, string $uploadedByUserId): PaymentProof
    {
        return $this->storage->persist(['file' => $this->proofUpload($file, $payment->tenant_id)], fn (array $stored) => $this->recordProof($payment, $stored['file'], $uploadedByUserId));
    }

    private function proofUpload(?UploadedFile $file, string $tenantId): array
    {
        return ['file' => $file, 'directory' => "payment-proofs/{$tenantId}", 'mimes' => self::ALLOWED_MIME_TYPES, 'max_bytes' => self::MAX_SIZE_BYTES, 'label' => 'proof of payment'];
    }

    private function recordProof(Payment $payment, array $document, string $uploadedByUserId): PaymentProof
    {
        return PaymentProof::query()->create([
            'payment_id' => $payment->id,
            'disk' => $document['disk'],
            'path' => $document['path'],
            'original_filename' => $document['original_name'],
            'mime_type' => $document['mime_type'],
            'size' => $document['size'],
            'uploaded_by' => $uploadedByUserId,
        ]);
    }

    public function markUnderReview(Payment $payment): Payment
    {
        if ($payment->status === 'SUBMITTED') {
            $payment->update(['status' => 'UNDER_REVIEW']);
        }

        return $payment->fresh();
    }

    /**
     * A rejected payment can be resubmitted by the tenant (Section 24):
     * creates a fresh payment record rather than mutating the rejected one,
     * preserving the rejection in history.
     */
    public function resubmit(Payment $rejectedPayment, array $attributes, string $submittedByUserId): Payment
    {
        if ($rejectedPayment->status !== 'REJECTED') {
            throw new PaymentException('Only a rejected payment can be resubmitted.');
        }

        return $this->submit($rejectedPayment->invoice, $attributes, $submittedByUserId);
    }
}
