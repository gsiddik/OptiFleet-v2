<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Billing\Services\BillingGenerationService;
use App\Domain\Contract\Models\Contract;
use App\Domain\Invoice\Services\InvoiceService;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(
        private readonly BillingGenerationService $billing,
        private readonly InvoiceService $invoices,
        private readonly EntitlementProvisioningService $entitlements,
    ) {}

    /**
     * Section 13: Contract Approved -> Subscription Created -> Initial
     * Invoice Generated -> (Payment Verified ->) Subscription Activated ->
     * Entitlements Activated. Tenants configured with
     * activation_requires_payment = false skip straight to activation.
     */
    public function provisionForContract(Contract $contract): Subscription
    {
        return DB::transaction(function () use ($contract) {
            $subscription = Subscription::query()->firstOrCreate(
                ['contract_id' => $contract->id],
                [
                    'tenant_id' => $contract->tenant_id,
                    'start_date' => $contract->start_date,
                    'end_date' => $contract->end_date,
                    'next_billing_date' => $contract->start_date,
                    'status' => 'PENDING',
                ]
            );

            $firstBilling = $this->billing->generateForSubscription($subscription->fresh());
            $this->invoices->generateFromBilling($firstBilling);

            if (! $contract->activation_requires_payment) {
                $this->activate($subscription->fresh());
            }

            return $subscription->fresh();
        });
    }

    public function activate(Subscription $subscription): Subscription
    {
        if (in_array($subscription->status, ['CANCELLED', 'EXPIRED'], true)) {
            throw new SubscriptionException("Cannot activate a subscription in status {$subscription->status}.");
        }

        return DB::transaction(function () use ($subscription) {
            $contract = $subscription->contract;

            $subscription->update([
                'status' => 'ACTIVE',
                'activated_at' => $subscription->activated_at ?? now(),
                'suspended_at' => null,
                'grace_period_end' => null,
            ]);

            $this->entitlements->provisionFromContract($contract);

            if ($contract->status !== 'ACTIVE') {
                $contract->update([
                    'status' => 'ACTIVE',
                    'activated_at' => $contract->activated_at ?? now(),
                ]);
            }

            return $subscription->fresh();
        });
    }

    public function suspend(Subscription $subscription, ?string $reason = null): Subscription
    {
        $subscription->update([
            'status' => 'SUSPENDED',
            'suspended_at' => now(),
        ]);

        return $subscription->fresh();
    }

    public function reactivate(Subscription $subscription): Subscription
    {
        if ($subscription->status !== 'SUSPENDED') {
            throw new SubscriptionException('Only a suspended subscription can be reactivated.');
        }

        return $this->activate($subscription);
    }

    public function markPastDue(Subscription $subscription): Subscription
    {
        if ($subscription->status !== 'ACTIVE' && $subscription->status !== 'EXPIRING') {
            return $subscription;
        }

        $subscription->update(['status' => 'PAST_DUE']);

        return $subscription->fresh();
    }

    public function enterGracePeriod(Subscription $subscription, \DateTimeInterface|string $graceEnd): Subscription
    {
        $subscription->update([
            'status' => 'GRACE_PERIOD',
            'grace_period_end' => $graceEnd,
        ]);

        return $subscription->fresh();
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        return $subscription->fresh();
    }
}
