<?php

namespace App\Console\Commands;

use App\Domain\Subscription\Services\DunningService;
use Illuminate\Console\Command;

class EvaluateOverdueInvoicesCommand extends Command
{
    protected $signature = 'invoices:evaluate-overdue';

    protected $description = 'Mark past-due invoices OVERDUE and transition their subscription to PAST_DUE. Idempotent.';

    public function handle(DunningService $dunning): int
    {
        $result = $dunning->evaluateOverdueInvoices();
        $this->info("Marked {$result['invoices_marked_overdue']} invoice(s) overdue, {$result['subscriptions_marked_past_due']} subscription(s) past due.");

        return self::SUCCESS;
    }
}
