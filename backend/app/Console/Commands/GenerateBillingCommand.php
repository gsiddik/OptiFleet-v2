<?php

namespace App\Console\Commands;

use App\Domain\Billing\Services\BillingGenerationService;
use App\Domain\Invoice\Services\InvoiceService;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Console\Command;

class GenerateBillingCommand extends Command
{
    protected $signature = 'billing:generate';

    protected $description = 'Generate the next billing period (and invoice) for every subscription whose next billing date has arrived. Idempotent.';

    public function handle(BillingGenerationService $billing, InvoiceService $invoices): int
    {
        $due = Subscription::query()
            ->whereIn('status', ['ACTIVE', 'EXPIRING'])
            ->where('next_billing_date', '<=', now()->toDateString())
            ->get();

        $count = 0;
        foreach ($due as $subscription) {
            $generated = $billing->generateForSubscription($subscription);
            $invoices->generateFromBilling($generated);
            $count++;
        }

        $this->info("Generated billing for {$count} subscription(s).");

        return self::SUCCESS;
    }
}
