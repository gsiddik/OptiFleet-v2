<?php

namespace App\Console\Commands;

use App\Domain\Governance\GovernanceModeService;
use App\Models\User;
use Illuminate\Console\Command;

class GovernanceDisableCommand extends Command
{
    protected $signature = 'optinexus:governance:disable {tenant : tenant code or id} {--by= : e-mail of the platform operator} {--reason= : why (kept in the audit trail)} {--confirm : acknowledge that OptiNexus revocations stop applying}';

    protected $description = 'BREAK-GLASS: return a tenant to LOCAL governance (for example OptiNexus is down for a long time). Audited; not an administration tool.';

    public function handle(GovernanceModeService $modes): int
    {
        $tenant = $modes->tenants((string) $this->argument('tenant'))->first();
        $operator = User::query()->where('email', (string) $this->option('by'))->where('user_type', 'platform')->where('status', 'active')->first();
        $reason = trim((string) $this->option('reason'));

        if (! $tenant || ! $operator || strlen($reason) < 10 || ! $this->option('confirm')) {
            $this->error('Needs a known tenant, --by=<active platform user e-mail>, --reason=<at least 10 characters> and --confirm.');

            return self::FAILURE;
        }
        if ($tenant->governance_mode !== 'CENTRAL') {
            $this->line('The tenant is already LOCAL.');

            return self::SUCCESS;
        }

        $modes->disable($tenant, $operator, $reason);
        $this->warn("Tenant {$tenant->code} is LOCAL again: local roles and entitlements apply, OptiNexus revocations do not. Re-enable with optinexus:governance:enable after the incident.");

        return self::SUCCESS;
    }
}
