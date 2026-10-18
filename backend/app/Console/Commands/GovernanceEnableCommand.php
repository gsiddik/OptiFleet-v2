<?php

namespace App\Console\Commands;

use App\Domain\Governance\GovernanceModeService;
use App\Models\User;
use Illuminate\Console\Command;

class GovernanceEnableCommand extends Command
{
    protected $signature = 'optinexus:governance:enable {tenant : tenant code or id} {--check : report readiness only, change nothing} {--by= : e-mail of the platform operator taking responsibility}';

    protected $description = 'Switch a tenant to CENTRAL governance (OptiNexus is the only authority). Nothing is migrated automatically; run with --check first.';

    public function handle(GovernanceModeService $modes): int
    {
        $tenant = $modes->tenants((string) $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        $readiness = $modes->readiness($tenant);
        foreach ($readiness['blockers'] as $line) {
            $this->error('BLOCKER  '.$line);
        }
        foreach ($readiness['warnings'] as $line) {
            $this->warn('WARNING  '.$line);
        }
        $this->line(sprintf('Sampled %d linked members, %d hold an optifleet permission in OptiNexus.', $readiness['sampled'], $readiness['sampled_with_access']));

        if ($this->option('check')) {
            $this->line($readiness['ready'] ? 'READY: no blocker.' : 'NOT READY.');

            return $readiness['ready'] ? self::SUCCESS : self::FAILURE;
        }
        if (! $readiness['ready']) {
            return self::FAILURE;
        }

        $operator = $this->operator();
        if (! $operator) {
            $this->error('--by=<e-mail of an active platform user> is required: the switch is attributed to a person.');

            return self::FAILURE;
        }

        $result = $modes->enable($tenant, $operator);
        $this->info("Tenant {$tenant->code} is now governed by OptiNexus. Password sessions revoked: {$result['revoked_sessions']}.");

        return self::SUCCESS;
    }

    private function operator(): ?User
    {
        $email = (string) $this->option('by');

        return $email === '' ? null : User::query()->where('email', $email)->where('user_type', 'platform')->where('status', 'active')->first();
    }
}
