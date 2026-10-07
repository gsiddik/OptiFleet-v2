<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Inventory\Services\SerializedStockReconciliationService;
use Illuminate\Console\Command;

class ReconcileSerializedInstallationsCommand extends Command
{
    protected $signature = 'inventory:reconcile-serialized-installations
        {--tenant= : Tenant id (default: every tenant)}
        {--json= : Write the full plan (every installation with its evidence) to this file}
        {--apply : Book the missing issue for the PROVABLE_UNDEDUCTED installations of a reviewed plan}
        {--plan-hash= : The plan hash printed by the dry-run (required with --apply)}';

    protected $description = 'Dry-run (default) or apply the reconciliation of serialized units installed before installations settled with the warehouse ledger. Applying needs the hash of a reviewed plan; it is idempotent.';

    public function handle(SerializedStockReconciliationService $service): int
    {
        $tenant = $this->option('tenant') ?: null;

        if ($this->option('apply')) {
            if (! $this->option('plan-hash')) {
                $this->error('--apply needs --plan-hash from a reviewed dry-run.');

                return self::FAILURE;
            }
            try {
                $result = $service->apply((string) $this->option('plan-hash'), $tenant);
            } catch (InventoryException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Applied: {$result['applied']}; already done: {$result['already_done']}; skipped: ".count($result['skipped']));
            foreach ($result['skipped'] as $skipped) {
                $this->warn("  skipped {$skipped['installation_id']}: {$skipped['reason']}");
            }

            return self::SUCCESS;
        }

        $plan = $service->plan($tenant);
        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line("Full plan written to {$this->option('json')}");
        }
        $this->info('DRY-RUN — nothing was changed.');
        foreach ($plan['tenants'] as $t) {
            $this->line("Tenant {$t['tenant_code']}");
            $this->table(['category', 'installations'], collect($t['summary'])->except('installations')->map(fn ($n, $k) => [$k, $n])->values()->all());
            foreach ($t['balance'] as $b) {
                $this->line("  ledger vs registered units — {$b['warehouse']} / {$b['product']}: ledger {$b['ledger_on_hand']}, units {$b['registered_in_stock_units']}, difference {$b['difference']} (provable undeducted: {$b['explained_by_provable_undeducted']})");
            }
        }
        $this->line('Plan hash: '.$plan['plan_hash']);
        $this->line('Apply only after review: php artisan inventory:reconcile-serialized-installations --apply --plan-hash='.$plan['plan_hash']);

        return self::SUCCESS;
    }
}
