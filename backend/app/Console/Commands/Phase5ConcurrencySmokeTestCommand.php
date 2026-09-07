<?php

namespace App\Console\Commands;

use App\Domain\Configuration\Services\ConfigurationService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * One-off release-gate concurrency probe for Phase 5 (Section 5/49):
 * spawns real, independent `php artisan` OS processes (never pcntl_fork,
 * which would duplicate this command's own already-live DB/Redis socket
 * file descriptors into "children" that then corrupt each other's
 * wire-protocol reads — confirmed while building this probe) to race
 * configurable document-number generation for the same tenant/scope.
 * Not wired into any schedule or route; builds its own throwaway
 * fixtures and cleans them up on exit.
 */
class Phase5ConcurrencySmokeTestCommand extends Command
{
    protected $signature = 'concurrency:smoke-test-phase5';
    protected $description = 'Spawn concurrent worker processes to probe Phase 5 configurable numbering race safety';

    public function handle(): int
    {
        $tenant = Tenant::query()->create([
            'code' => 'SMOKE5-'.Str::upper(Str::random(6)), 'name' => 'Phase5 Smoke Test Tenant', 'status' => 'ACTIVE',
        ]);

        $configService = app(ConfigurationService::class);
        $set = $configService->findOrCreateSet($tenant->id, 'NUMBERING', 'work_order', 'TENANT', null, 'Work Order Numbering');
        $configService->publish($configService->createDraft($set, ['format' => 'WO/{SEQ:6}', 'doc_code' => 'WO'], null), null);

        $this->info("\nRacing 30 independent worker processes each generating a Work Order number for the same tenant/scope");

        $artisanBinary = base_path('artisan');
        $processes = [];
        for ($i = 0; $i < 30; $i++) {
            $process = new Process(['php', $artisanBinary, 'numbering:generate-once', 'work_order', $tenant->id]);
            $process->start();
            $processes[] = $process;
        }

        $numbers = [];
        $errors = 0;
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                $errors++;
                continue;
            }
            $numbers[] = trim($process->getOutput());
        }

        $unique = array_unique($numbers);
        $ok = $errors === 0 && count($numbers) === 30 && count($unique) === 30;
        $this->line('generated: '.count($numbers).' (expect 30), unique: '.count($unique).' (expect 30), errors: '.$errors.' (expect 0)');
        $this->line($ok ? 'numbering concurrency: SAFE' : 'NUMBERING RACE DETECTED (lost update, duplicate, or worker error)');

        $this->cleanup($tenant, $set->id);

        if (! $ok) {
            $this->error('PHASE 5 CONCURRENCY SMOKE TEST: FAILED');

            return self::FAILURE;
        }
        $this->info('PHASE 5 CONCURRENCY SMOKE TEST: SAFE');

        return self::SUCCESS;
    }

    private function cleanup(Tenant $tenant, string $configurationSetId): void
    {
        DB::table('configuration_versions')->where('configuration_set_id', $configurationSetId)->delete();
        DB::table('configuration_sets')->where('tenant_id', $tenant->id)->delete();
        DB::table('commercial_number_sequences')->where('sequence_key', 'like', "%{$configurationSetId}%")->delete();
        DB::table('tenants')->where('id', $tenant->id)->delete();
        $this->info("\ncleaned up throwaway tenant {$tenant->id}");
    }
}
