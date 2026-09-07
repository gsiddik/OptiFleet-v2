<?php

namespace App\Domain\Invoice\Services;

use App\Domain\Billing\Models\Billing;
use App\Domain\Invoice\Models\Invoice;
use App\Domain\Invoice\Models\InvoiceItem;
use App\Domain\Pricing\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private readonly InvoiceNumberService $numbers) {}

    /**
     * Idempotent like BillingGenerationService::generateForSubscription():
     * the pre-check below is a fast path only, not the source of safety —
     * the unique index invoices_billing_id_unique is — and the catch below
     * turns a concurrent duplicate attempt into a lookup of the row the
     * other process just created.
     */
    public function generateFromBilling(Billing $billing): Invoice
    {
        $existing = Invoice::query()->where('billing_id', $billing->id)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return $this->createFromBilling($billing);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'invoices_billing_id_unique')) {
                return Invoice::query()->where('billing_id', $billing->id)->firstOrFail();
            }
            throw $e;
        }
    }

    private function createFromBilling(Billing $billing): Invoice
    {
        return DB::transaction(function () use ($billing) {
            $invoice = Invoice::query()->create([
                'invoice_number' => $this->numbers->generate(),
                'tenant_id' => $billing->tenant_id,
                'contract_id' => $billing->contract_id,
                'subscription_id' => $billing->subscription_id,
                'billing_id' => $billing->id,
                'invoice_date' => $billing->invoice_date,
                'due_date' => $billing->due_date,
                'currency' => $billing->contract->currency,
                'subtotal' => $billing->subtotal,
                'discount' => $billing->discount,
                'tax' => $billing->tax,
                'adjustment' => 0,
                'total' => $billing->total,
                'paid_amount' => 0,
                'outstanding_amount' => $billing->total,
                'status' => 'DRAFT',
            ]);

            foreach ($billing->items as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_type' => $item->product_type,
                    'product_reference' => $item->product_reference,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'tax' => $item->tax,
                    'amount' => $item->amount,
                ]);
            }

            $billing->update(['status' => 'INVOICED']);

            return $this->issue($invoice->fresh('items'));
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'DRAFT') {
            return $invoice;
        }

        $invoice->update([
            'status' => 'OUTSTANDING',
            'issued_at' => now(),
        ]);

        return $invoice->fresh();
    }

    /**
     * Invoices are immutable once issued (Section 28). A correction is a
     * VOID + replacement invoice, never an in-place edit.
     */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'VOID') {
            return $invoice;
        }
        if ($invoice->status === 'PAID') {
            throw new InvoiceException('A fully paid invoice cannot be voided; reverse the payment first.');
        }

        $invoice->update([
            'status' => 'VOID',
            'voided_at' => now(),
            'void_reason' => $reason,
        ]);

        return $invoice->fresh();
    }

    /**
     * Recomputes paid_amount/outstanding_amount/status from verified
     * payments. Called transactionally from PaymentVerificationService.
     */
    public function recalculatePaymentState(Invoice $invoice): Invoice
    {
        $paid = $invoice->payments()->where('status', 'VERIFIED')->sum('amount');
        $outstanding = Money::subtract((string) $invoice->total, (string) $paid);

        $status = match (true) {
            $invoice->status === 'VOID' => 'VOID',
            Money::isZeroOrLess($outstanding) => 'PAID',
            Money::isGreaterThan((string) $paid, '0') => 'PARTIALLY_PAID',
            $invoice->due_date->isPast() => 'OVERDUE',
            default => 'OUTSTANDING',
        };

        $invoice->update([
            'paid_amount' => $paid,
            'outstanding_amount' => Money::max($outstanding, '0'),
            'status' => $status,
            'paid_at' => $status === 'PAID' ? ($invoice->paid_at ?? now()) : $invoice->paid_at,
        ]);

        return $invoice->fresh();
    }
}
