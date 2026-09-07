<?php

namespace App\Console\Commands;

use App\Domain\Subscription\Services\DunningService;
use Illuminate\Console\Command;

class EvaluateExpiryCommand extends Command
{
    protected $signature = 'contracts:evaluate-expiry';

    protected $description = 'Transition contracts/subscriptions approaching or past their end date to EXPIRING/EXPIRED. Idempotent.';

    public function handle(DunningService $dunning): int
    {
        $result = $dunning->evaluateContractAndSubscriptionExpiry();
        foreach ($result as $key => $value) {
            $this->info("{$key}: {$value}");
        }

        return self::SUCCESS;
    }
}
