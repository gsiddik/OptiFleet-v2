<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Contract\Models\Contract;
use App\Domain\Invoice\Models\Invoice;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Section 37/38: automatic past-due, grace-period, and suspension
 * transitions. Every method here is idempotent — safe to run the scheduled
 * command as many times as the schedule fires without double-transitioning
 * a subscription that's already in the target state.
 */
class DunningService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly AuditService $audit,
    ) {}

    /**
     * Invoices past their due date with a remaining balance become OVERDUE,
     * and their subscription (if currently ACTIVE/EXPIRING) becomes
     * PAST_DUE. Returns how many invoices/subscriptions were transitioned.
     */
    public function evaluateOverdueInvoices(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $overdueCount = 0;
        $pastDueCount = 0;

        Invoice::query()
            ->whereIn('status', ['OUTSTANDING', 'PARTIALLY_PAID'])
            ->where('due_date', '<', $asOf->toDateString())
            ->where('outstanding_amount', '>', 0)
            ->with('subscription')
            ->chunkById(100, function ($invoices) use (&$overdueCount, &$pastDueCount) {
                foreach ($invoices as $invoice) {
                    DB::transaction(function () use ($invoice, &$overdueCount, &$pastDueCount) {
                        if ($invoice->status !== 'OVERDUE') {
                            $invoice->update(['status' => 'OVERDUE']);
                            $overdueCount++;
                        }

                        $subscription = $invoice->subscription;
                        if ($subscription && in_array($subscription->status, ['ACTIVE', 'EXPIRING'], true)) {
                            $this->subscriptions->markPastDue($subscription);
                            $pastDueCount++;
                            $this->audit->log('Subscription', $subscription->id, 'past_due', null, ['invoice_id' => $invoice->id], $subscription->tenant_id);
                        }
                    });
                }
            });

        return ['invoices_marked_overdue' => $overdueCount, 'subscriptions_marked_past_due' => $pastDueCount];
    }

    /**
     * PAST_DUE subscriptions enter GRACE_PERIOD (Section 38) using the
     * contract's configured grace_period_days measured from the oldest
     * unpaid invoice's due date. Once "today" passes grace_period_end, the
     * subscription is automatically SUSPENDED and restricted to
     * billing-only access (enforced by the RestrictSuspendedTenant
     * middleware, not by revoking entitlements — see Section 20/39).
     */
    public function evaluateGraceAndSuspension(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $graceCount = 0;
        $suspendedCount = 0;

        Subscription::query()
            ->whereIn('status', ['PAST_DUE', 'GRACE_PERIOD'])
            ->with('contract')
            ->chunkById(100, function ($subscriptions) use ($asOf, &$graceCount, &$suspendedCount) {
                foreach ($subscriptions as $subscription) {
                    DB::transaction(function () use ($subscription, $asOf, &$graceCount, &$suspendedCount) {
                        $oldestUnpaidDueDate = $subscription->invoices()
                            ->whereIn('status', ['OUTSTANDING', 'PARTIALLY_PAID', 'OVERDUE'])
                            ->min('due_date');

                        if (! $oldestUnpaidDueDate) {
                            return; // nothing actually unpaid — leave as-is, verified payment flow will reactivate
                        }

                        /** @var Contract $contract */
                        $contract = $subscription->contract;
                        $graceEnd = Carbon::parse($oldestUnpaidDueDate)->addDays($contract->grace_period_days);

                        if ($asOf->toDateString() > $graceEnd->toDateString()) {
                            if ($subscription->status !== 'SUSPENDED') {
                                $this->subscriptions->suspend($subscription, 'Grace period expired');
                                $suspendedCount++;
                                $this->audit->log('Subscription', $subscription->id, 'suspended', null, ['grace_period_end' => $graceEnd->toDateString()], $subscription->tenant_id);
                            }
                        } elseif ($subscription->status === 'PAST_DUE') {
                            $this->subscriptions->enterGracePeriod($subscription, $graceEnd);
                            $graceCount++;
                            $this->audit->log('Subscription', $subscription->id, 'grace_period', null, ['grace_period_end' => $graceEnd->toDateString()], $subscription->tenant_id);
                        }
                    });
                }
            });

        return ['entered_grace_period' => $graceCount, 'suspended' => $suspendedCount];
    }

    public function evaluateContractAndSubscriptionExpiry(?Carbon $asOf = null, int $expiringWindowDays = 30): array
    {
        $asOf ??= now();
        $expiringThreshold = $asOf->copy()->addDays($expiringWindowDays)->toDateString();
        $counts = ['contracts_expiring' => 0, 'contracts_expired' => 0, 'subscriptions_expiring' => 0, 'subscriptions_expired' => 0];

        Contract::query()->where('status', 'ACTIVE')
            ->where('end_date', '<=', $expiringThreshold)
            ->where('end_date', '>=', $asOf->toDateString())
            ->update(['status' => 'EXPIRING']);
        $counts['contracts_expiring'] = Contract::query()->where('status', 'EXPIRING')->count();

        $counts['contracts_expired'] = Contract::query()
            ->whereIn('status', ['ACTIVE', 'EXPIRING'])
            ->where('end_date', '<', $asOf->toDateString())
            ->update(['status' => 'EXPIRED']);

        Subscription::query()->where('status', 'ACTIVE')
            ->where('end_date', '<=', $expiringThreshold)
            ->where('end_date', '>=', $asOf->toDateString())
            ->update(['status' => 'EXPIRING']);
        $counts['subscriptions_expiring'] = Subscription::query()->where('status', 'EXPIRING')->count();

        $counts['subscriptions_expired'] = Subscription::query()
            ->whereIn('status', ['ACTIVE', 'EXPIRING'])
            ->where('end_date', '<', $asOf->toDateString())
            ->update(['status' => 'EXPIRED']);

        return $counts;
    }
}
