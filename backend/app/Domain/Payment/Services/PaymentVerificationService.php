<?php

namespace App\Domain\Payment\Services;

use App\Domain\Billing\Models\Billing;
use App\Domain\Invoice\Services\InvoiceService;
use App\Domain\Payment\Models\Payment;
use App\Domain\Subscription\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;

/**
 * Section 35: payment verification is transactional and must never leave
 * partial state — Payment -> VERIFIED, invoice paid/outstanding
 * recalculated, billing status synced, subscription (re)activated when the
 * payment threshold is met (default: full invoice payment, Section 36),
 * entitlements restored, all inside one DB transaction.
 */
class PaymentVerificationService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function verify(Payment $payment, string $verifierUserId, ?string $note = null): Payment
    {
        if (! in_array($payment->status, ['SUBMITTED', 'UNDER_REVIEW'], true)) {
            throw new PaymentException('Only a submitted payment under review can be verified.');
        }

        return DB::transaction(function () use ($payment, $verifierUserId, $note) {
            $payment->update([
                'status' => 'VERIFIED',
                'verified_by' => $verifierUserId,
                'verified_at' => now(),
                'verification_note' => $note,
            ]);

            $invoice = $this->invoices->recalculatePaymentState($payment->invoice);

            if ($invoice->billing_id) {
                $billing = Billing::query()->find($invoice->billing_id);
                $billing?->update(['status' => $invoice->status === 'PAID' ? 'PAID' : 'PARTIALLY_PAID']);
            }

            // Default activation threshold: full invoice payment (Section 36).
            if ($invoice->status === 'PAID') {
                $subscription = $invoice->subscription;
                if ($subscription && in_array($subscription->status, ['PENDING', 'SUSPENDED', 'PAST_DUE', 'GRACE_PERIOD'], true)) {
                    $this->subscriptions->activate($subscription);
                }
            }

            return $payment->fresh();
        });
    }

    public function reject(Payment $payment, string $verifierUserId, string $note): Payment
    {
        if (! in_array($payment->status, ['SUBMITTED', 'UNDER_REVIEW'], true)) {
            throw new PaymentException('Only a submitted payment under review can be rejected.');
        }

        $payment->update([
            'status' => 'REJECTED',
            'verified_by' => $verifierUserId,
            'verified_at' => now(),
            'verification_note' => $note,
        ]);

        return $payment->fresh();
    }
}
