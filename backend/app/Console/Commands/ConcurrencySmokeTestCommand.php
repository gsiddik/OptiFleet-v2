<?php

namespace App\Console\Commands;

use App\Domain\Billing\Services\BillingGenerationService;
use App\Domain\Invoice\Services\InvoiceService;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Services\PaymentVerificationService;
use App\Domain\Pricing\Support\Money;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off release-gate concurrency probe (Section 7 of the Phase 2 brief):
 * forks real OS processes (not just concurrent HTTP requests, which the
 * PHP built-in dev server serializes closely enough to never race) to
 * exercise the exact-same-period billing race, the invoice race, and the
 * duplicate-payment-verification race. Not wired into any schedule or
 * route; deleted after the release gate run.
 */
class ConcurrencySmokeTestCommand extends Command
{
    protected $signature = 'concurrency:smoke-test';
    protected $description = 'Fork concurrent workers to probe billing/invoice/payment race safety';

    public function handle(): int
    {
        $this->billingRace();
        $this->paymentVerifyRace();

        return self::SUCCESS;
    }

    private function fork(int $workers, callable $work): void
    {
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->error('fork failed');
                exit(1);
            }
            if ($pid === 0) {
                // Child: must not share the parent's DB connection.
                DB::purge();
                $work($i);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    private function billingRace(): void
    {
        $subscription = Subscription::query()->where('status', 'ACTIVE')->firstOrFail();
        $periodStart = Carbon::parse($subscription->next_billing_date);

        $billingService = app(BillingGenerationService::class);
        $invoiceService = app(InvoiceService::class);
        $periodEnd = $billingService->periodEndFor($periodStart, $subscription->contract->billing_cycle);

        $this->info("Racing 12 workers on subscription {$subscription->id}, period {$periodStart->toDateString()}..{$periodEnd->toDateString()}");

        $this->fork(12, function () use ($subscription, $periodStart, $periodEnd, $billingService, $invoiceService) {
            $sub = Subscription::find($subscription->id);
            $billing = $billingService->generateForSubscription($sub, $periodStart->copy(), $periodEnd->copy());
            $invoiceService->generateFromBilling($billing);
        });

        $billingCount = DB::table('billings')
            ->where('subscription_id', $subscription->id)
            ->where('billing_period_start', $periodStart->toDateString())
            ->where('billing_period_end', $periodEnd->toDateString())
            ->count();

        $invoiceCount = DB::table('invoices')
            ->whereIn('billing_id', function ($q) use ($subscription, $periodStart, $periodEnd) {
                $q->select('id')->from('billings')
                    ->where('subscription_id', $subscription->id)
                    ->where('billing_period_start', $periodStart->toDateString())
                    ->where('billing_period_end', $periodEnd->toDateString());
            })
            ->count();

        $distinctInvoiceNumbers = DB::table('invoices')->distinct('invoice_number')->count('invoice_number');
        $totalInvoices = DB::table('invoices')->count();

        $this->line("billings for exact period: {$billingCount} (expect 1)");
        $this->line("invoices for that billing: {$invoiceCount} (expect 1)");
        $this->line("global invoice_number uniqueness: {$distinctInvoiceNumbers} distinct / {$totalInvoices} total (expect equal)");

        if ($billingCount !== 1 || $invoiceCount !== 1 || $distinctInvoiceNumbers !== $totalInvoices) {
            $this->error('BILLING/INVOICE CONCURRENCY RACE DETECTED');
            exit(1);
        }
        $this->info('billing + invoice concurrency: SAFE');
    }

    private function paymentVerifyRace(): void
    {
        $payment = Payment::query()->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW'])->first();
        if (! $payment) {
            $this->warn('no SUBMITTED/UNDER_REVIEW payment available to race verify() against — skipping');
            return;
        }

        $verifierId = DB::table('users')->where('user_type', 'platform')->value('id');
        $invoiceId = $payment->invoice_id;
        $invoiceTotal = DB::table('invoices')->where('id', $invoiceId)->value('total');

        $this->info("Racing 10 workers verifying payment {$payment->id} against invoice {$invoiceId}");

        $this->fork(10, function () use ($payment, $verifierId) {
            try {
                app(PaymentVerificationService::class)->verify(Payment::find($payment->id), $verifierId);
            } catch (\Throwable $e) {
                // Expected for every loser of the race.
            }
        });

        $paidAmount = DB::table('invoices')->where('id', $invoiceId)->value('paid_amount');
        $verifiedCount = DB::table('payments')->where('id', $payment->id)->where('status', 'VERIFIED')->count();

        $this->line("payment status VERIFIED: {$verifiedCount} (expect 1)");
        $this->line("invoice paid_amount: {$paidAmount} vs invoice total: {$invoiceTotal} (must not exceed total)");

        if ($verifiedCount !== 1 || Money::isGreaterThan((string) $paidAmount, (string) $invoiceTotal)) {
            $this->error('PAYMENT VERIFICATION CONCURRENCY RACE DETECTED (over-credit)');
            exit(1);
        }
        $this->info('payment verification concurrency: SAFE (no over-credit)');
    }
}
