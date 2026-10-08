<?php

namespace App\Console\Commands;

use App\Domain\Integration\Optinexus\OptinexusEventRelay;
use Illuminate\Console\Command;

class OptinexusRelayEventsCommand extends Command
{
    protected $signature = 'optinexus:relay-events {--tenant= : Only this tenant id} {--retry-failed : Put FAILED events back in the queue first}';

    protected $description = 'Report integration outbox events (invoices, memos) to OptiNexus';

    public function handle(OptinexusEventRelay $relay): int
    {
        if (! config('optinexus.enabled') || ! config('optinexus.events.enabled')) {
            $this->warn('OptiNexus events are disabled (OPTINEXUS_ENABLED and OPTINEXUS_EVENTS_ENABLED must both be true).');

            return self::SUCCESS;
        }

        if ($this->option('retry-failed')) {
            $this->info('Re-queued '.$relay->retryFailed($this->option('tenant')).' failed event(s).');
        }

        $r = $relay->relay($this->option('tenant'));
        $this->info("Delivered {$r['delivered']}, retrying {$r['retrying']}, failed {$r['failed']}.");

        return $r['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
