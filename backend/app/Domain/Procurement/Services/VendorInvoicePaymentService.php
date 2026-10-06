<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Pricing\Support\Money;
use App\Domain\Procurement\Models\VendorInvoicePayment;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use App\Domain\Procurement\Support\VendorInvoiceStatus;
use App\Domain\Shared\Services\PrivateDocumentStorage;
use App\Domain\Shared\Support\Messages;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;

/**
 * Records the payment of a vendor invoice. Scope: full settlement only — the platform has no
 * partial-payment model for vendor invoices, so the amount must equal the invoice amount and an
 * invoice is paid at most once. The payment belongs to the invoice: an invoice shared by several
 * Goods Receipts is paid once and every one of those rows shows PAID.
 *
 * One transaction: invoice row lock → not already paid → payment + proof. The proof file is
 * removed again if the transaction fails; the unique key on the invoice id stops a concurrent
 * second payment.
 */
class VendorInvoicePaymentService
{
    public const PROOF_MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public const PROOF_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly PrivateDocumentStorage $storage) {}

    public function pay(VendorInvoiceReference $invoice, string $paymentDate, string $amount, UploadedFile $proof, ?string $userId): VendorInvoicePayment
    {
        $today = VendorInvoiceStatus::today(Tenant::query()->whereKey($invoice->tenant_id)->value('timezone'));
        if (CarbonImmutable::parse($paymentDate)->startOfDay()->greaterThan($today)) {
            throw new ProcurementException('The payment date cannot be in the future.');
        }
        if (Money::compare($amount, (string) $invoice->amount) !== 0) {
            throw new ProcurementException(Messages::text('errors.procurement.paymentMustEqualInvoice', ['amount' => Money::of((string) $invoice->amount)->toScale(2)]));
        }

        $upload = ['file' => $proof, 'directory' => "vendor-invoice-payments/{$invoice->tenant_id}", 'mimes' => self::PROOF_MIMES, 'max_bytes' => self::PROOF_MAX_BYTES, 'label' => 'payment proof'];

        try {
            return $this->storage->persist(['payment_proof' => $upload], function (array $stored) use ($invoice, $paymentDate, $amount, $userId) {
                $locked = VendorInvoiceReference::query()->lockForUpdate()->findOrFail($invoice->id);
                if ($locked->payment()->exists()) {
                    throw new ProcurementException("Invoice {$locked->vendor_invoice_number} is already paid.");
                }
                $document = $stored['payment_proof'];

                return VendorInvoicePayment::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'vendor_invoice_reference_id' => $locked->id,
                    'payment_date' => CarbonImmutable::parse($paymentDate)->toDateString(),
                    'amount' => $amount,
                    'proof_disk' => $document['disk'],
                    'proof_path' => $document['path'],
                    'proof_original_name' => $document['original_name'],
                    'proof_mime_type' => $document['mime_type'],
                    'proof_size' => $document['size'],
                    'paid_by' => $userId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ProcurementException("Invoice {$invoice->vendor_invoice_number} is already paid.");
        }
    }
}
