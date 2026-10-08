<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Optinexus\OptinexusSyncService;
use Illuminate\Console\Command;

class OptinexusSyncCommand extends Command
{
    protected $signature = 'optinexus:sync {--tenant= : Only this tenant id}';

    protected $description = 'Publish vehicles to the OptiNexus gateway and pull telematics odometer readings';

    public function handle(OptinexusSyncService $sync): int
    {
        if (! config('optinexus.enabled')) {
            $this->warn('OptiNexus integration is disabled (OPTINEXUS_ENABLED=false).');

            return self::SUCCESS;
        }

        $failed = 0;

        Tenant::query()->whereNotNull('optinexus_tenant_id')->where('status', 'ACTIVE')
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('code')
            ->each(function (Tenant $tenant) use ($sync, &$failed) {
                try {
                    $r = $sync->syncTenant($tenant);
                    $this->info("{$tenant->code}: published {$r['published']}, applied {$r['applied']}, held {$r['held']}, stored {$r['stored']}, unknown {$r['unknown']}.");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$tenant->code}: {$e->getMessage()}");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
