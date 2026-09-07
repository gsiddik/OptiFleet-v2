<?php

namespace App\Domain\Payment\Services;

use App\Domain\Invoice\Models\Invoice;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProof;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant-facing payment submission and proof upload. File storage never
 * trusts the client filename (Section 52/33): a fresh UUID-based path is
 * generated, the original name is kept only as display metadata, and files
 * are written to the private `local` disk — retrieval always goes through
 * an authenticated, ownership-checked controller action, never a public URL.
 */
class PaymentSubmissionService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

    public function submit(Invoice $invoice, array $attributes, string $submittedByUserId): Payment
    {
        if (in_array($invoice->status, ['VOID', 'PAID'], true)) {
            throw new PaymentException("Cannot submit a payment against an invoice with status {$invoice->status}.");
        }

        return Payment::query()->create(array_merge($attributes, [
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'status' => 'SUBMITTED',
            'submitted_by' => $submittedByUserId,
        ]));
    }

    public function attachProof(Payment $payment, UploadedFile $file, string $uploadedByUserId): PaymentProof
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new PaymentException('Unsupported file type. Only JPEG, PNG, WEBP images or PDF are accepted.');
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new PaymentException('File exceeds the 5MB maximum size.');
        }

        return DB::transaction(function () use ($payment, $file, $uploadedByUserId) {
            $extension = $file->guessExtension() ?: 'bin';
            $path = $file->storeAs(
                "payment-proofs/{$payment->tenant_id}",
                Str::uuid().'.'.$extension,
                ['disk' => 'local']
            );

            return PaymentProof::query()->create([
                'payment_id' => $payment->id,
                'disk' => 'local',
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $uploadedByUserId,
            ]);
        });
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
