<?php

namespace App\Console\Commands;

use App\Domain\Governance\GovernanceModeService;
use Illuminate\Console\Command;

class GovernanceInventoryCommand extends Command
{
    protected $signature = 'optinexus:governance:inventory {--tenant= : only this tenant (code or id)} {--json : machine readable output}';

    protected $description = 'Read-only inventory of how each tenant is governed (LOCAL legacy or CENTRAL) and what linking it to OptiNexus would change.';

    public function handle(GovernanceModeService $modes): int
    {
        $rows = $modes->tenants($this->option('tenant'))->map(fn ($tenant) => $modes->inventory($tenant))->values();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->warn('No tenant matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['code', 'status', 'mode', 'linked', 'members', 'linked', 'local-only', 'local roles', 'scopes', 'local modules'],
            $rows->map(fn (array $r) => [$r['code'], $r['status'], $r['mode'], $r['linked_to_optinexus'] ? 'yes' : 'no', $r['members_active'], $r['members_linked'],
                $r['members_unlinked'], $r['local_role_assignments'], $r['local_data_scopes'], $r['local_module_entitlements']])->all(),
        );
        $this->line('LOCAL tenants are legacy installs. Next steps per tenant: docs/integration/CENTRAL_GOVERNANCE.md (linking and migration plan), then `optinexus:governance:enable <tenant> --check`.');

        return self::SUCCESS;
    }
}
