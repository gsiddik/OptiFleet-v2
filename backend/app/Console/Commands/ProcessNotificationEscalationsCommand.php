<?php

namespace App\Console\Commands;

use App\Domain\Notification\Services\EscalationProcessor;
use Illuminate\Console\Command;

class ProcessNotificationEscalationsCommand extends Command
{
    protected $signature = 'notifications:process-escalations';

    protected $description = 'Section 32: notifies escalation recipients for SENT notifications whose rule declares escalation and whose wait has elapsed while the resource is still unresolved.';

    public function handle(EscalationProcessor $processor): int
    {
        $count = $processor->run();
        $this->info("Escalated {$count} notification(s).");

        return self::SUCCESS;
    }
}
