<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Optinexus\OptinexusSyncService;
use App\Domain\Integration\Optinexus\TelematicsEligibility;
use Illuminate\Console\Command;

class OptinexusSyncCommand extends Command
{
    protected $signature = 'optinexus:sync {--tenant= : Only this tenant id}';

    protected $description = 'Publish vehicles to the OptiNexus gateway and pull telematics odometer readings (only tenants that switched the feed on and are subscribed)';

    public function handle(OptinexusSyncService $sync): int
    {
        if (! config('optinexus.enabled')) {
            $this->warn('OptiNexus integration is disabled (OPTINEXUS_ENABLED=false).');

            return self::SUCCESS;
        }

        $failed = 0;

        Tenant::query()->whereNotNull('optinexus_tenant_id')->where('status', 'ACTIVE')
            // A named tenant is always tried, so the reason it is skipped is printed; a scheduled run touches only tenants that switched the feed on.
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id), fn ($q) => $q->whereNotNull('telematics_activated_at'))
            ->orderBy('code')
            ->each(function (Tenant $tenant) use ($sync, &$failed) {
                try {
                    $r = $sync->syncTenant($tenant);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$tenant->code}: {$e->getMessage()}");

                    return;
                }

                if ($r['state'] === TelematicsEligibility::PROVIDER_UNAVAILABLE) {
                    $failed++;
                    $this->error("{$tenant->code}: OptiNexus could not confirm the subscription (will retry).");
                } elseif ($r['state'] !== TelematicsEligibility::ELIGIBLE) {
                    $this->line("{$tenant->code}: skipped ({$r['state']}).");
                } else {
                    $this->info("{$tenant->code}: published {$r['published']}, applied {$r['applied']}, held {$r['held']}, stored {$r['stored']}, late {$r['late']}, quarantined {$r['quarantined']}, rejected {$r['rejected']}, unknown {$r['unknown']}.");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
