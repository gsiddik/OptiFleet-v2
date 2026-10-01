<?php

namespace App\Domain\Procurement\Services;

use App\Domain\MaintenancePolicy\Services\WorkingDayService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Vendor Invoice References are recorded while posting a Goods Receipt — the only entry point.
 * Procurement traceability only, separate from the tenant's SaaS billing invoices.
 *
 * A receipt either records a NEW invoice (vendor = the PO's vendor, number unique per vendor,
 * optional PDF stored privately) or reuses an invoice already received against an earlier
 * receipt of the same Purchase Order and vendor that is NOT yet paid (no duplicate row, no
 * duplicate file — the earlier receipt's attachment is never touched). A PAID invoice is
 * closed: it can never be attached to a later receipt.
 *
 * The invoice amount is not matched against the receipt value — one invoice may cover several
 * receipts; formal PO ↔ GR ↔ invoice three-way matching is a separate future improvement.
 *
 * Due date = invoice date + terms of payment in working days (Mon–Fri), computed once and
 * stored; the NEW / DUE_SOON / LATE status is derived from it at read time (never stored).
 */
class VendorInvoiceReferenceService
{
    public const DOCUMENT_MIMES = ['application/pdf'];

    public const DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly WorkingDayService $workingDays) {}

    /**
     * Runs inside the Goods Receipt transaction.
     *
     * @param  array{mode: string, vendor_invoice_reference_id?: ?string, vendor_invoice_number?: ?string, vendor_invoice_date?: ?string, amount?: ?string, terms_of_payment_days?: int|string|null}  $invoice
     * @param  ?array{disk: string, path: string, original_name: string, mime_type: string, size: int}  $document
     */
    public function resolveForReceipt(PurchaseOrder $po, GoodsReceipt $receipt, array $invoice, ?array $document, ?string $userId): VendorInvoiceReference
    {
        if ($invoice['mode'] === 'EXISTING') {
            // Locked: a concurrent payment of the same invoice waits for (or blocks) this receipt.
            $reference = VendorInvoiceReference::query()->where('tenant_id', $po->tenant_id)->lockForUpdate()->find($invoice['vendor_invoice_reference_id'] ?? null);
            $usedOnThisPo = $reference
                && $reference->purchase_order_id === $po->id
                && $reference->partner_id === $po->partner_id
                && GoodsReceipt::query()
                    ->where('purchase_order_id', $po->id)
                    ->where('vendor_invoice_reference_id', $reference->id)
                    ->whereKeyNot($receipt->id)
                    ->exists();
            if (! $usedOnThisPo) {
                throw new ProcurementException('The selected invoice was not received against an earlier Goods Receipt of this Purchase Order.');
            }
            // A paid invoice's financial lifecycle is complete: later receipts must not extend it.
            if ($reference->payment()->exists()) {
                throw new ProcurementException("Invoice {$reference->vendor_invoice_number} is already paid and cannot be used for another Goods Receipt. Record a new invoice instead.");
            }

            return $reference;
        }

        $number = trim((string) ($invoice['vendor_invoice_number'] ?? ''));
        if ($number === '') {
            throw new ProcurementException('The invoice number is required.');
        }
        $existing = VendorInvoiceReference::query()
            ->where('tenant_id', $po->tenant_id)
            ->where('partner_id', $po->partner_id)
            ->where('origin', 'GOODS_RECEIPT')
            ->whereRaw('UPPER(vendor_invoice_number) = ?', [mb_strtoupper($number)])
            ->exists();
        if ($existing) {
            throw new ProcurementException($this->duplicateMessage($number));
        }

        $invoiceDate = CarbonImmutable::parse((string) $invoice['vendor_invoice_date']);
        $terms = (int) $invoice['terms_of_payment_days'];

        try {
            return VendorInvoiceReference::query()->create([
                'tenant_id' => $po->tenant_id,
                'partner_id' => $po->partner_id,
                'purchase_order_id' => $po->id,
                'goods_receipt_id' => $receipt->id,
                'vendor_invoice_number' => $number,
                'vendor_invoice_date' => $invoiceDate->toDateString(),
                'amount' => (string) $invoice['amount'],
                'terms_of_payment_days' => $terms,
                'due_date' => $this->dueDate($invoiceDate, $terms)->toDateString(),
                'attachment_path' => $document['path'] ?? null,
                'attachment_disk' => $document['disk'] ?? null,
                'attachment_original_name' => $document['original_name'] ?? null,
                'attachment_mime_type' => $document['mime_type'] ?? null,
                'attachment_size' => $document['size'] ?? null,
                'status' => 'RECEIVED',
                'origin' => 'GOODS_RECEIPT',
                'created_by' => $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent receipt recorded the same vendor invoice first.
            throw new ProcurementException($this->duplicateMessage($number));
        }
    }

    public function dueDate(CarbonImmutable $invoiceDate, int $termsOfPaymentDays): CarbonImmutable
    {
        return $this->workingDays->addBusinessDays($invoiceDate, $termsOfPaymentDays);
    }

    private function duplicateMessage(string $number): string
    {
        return "Invoice {$number} from this vendor is already recorded. To receive more goods against it, use the same invoice as the previous Goods Receipt.";
    }
}
