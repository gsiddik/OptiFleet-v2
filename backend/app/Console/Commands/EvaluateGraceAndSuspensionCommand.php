<?php

namespace App\Console\Commands;

use App\Domain\Subscription\Services\DunningService;
use Illuminate\Console\Command;

class EvaluateGraceAndSuspensionCommand extends Command
{
    protected $signature = 'subscriptions:evaluate-grace-period';

    protected $description = 'Transition PAST_DUE subscriptions into GRACE_PERIOD, and suspend those whose grace period has expired. Idempotent.';

    public function handle(DunningService $dunning): int
    {
        $result = $dunning->evaluateGraceAndSuspension();
        $this->info("{$result['entered_grace_period']} subscription(s) entered grace period, {$result['suspended']} suspended.");

        return self::SUCCESS;
    }
}
