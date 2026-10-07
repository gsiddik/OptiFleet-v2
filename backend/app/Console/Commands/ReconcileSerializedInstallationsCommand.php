<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Inventory\Services\SerializedStockReconciliationService;
use App\Domain\Inventory\Services\StockReconciliationAdjustmentService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class ReconcileSerializedInstallationsCommand extends Command
{
    protected $signature = 'inventory:reconcile-serialized-installations
        {--tenant= : Tenant id (default: every tenant)}
        {--json= : Write the full plan (every installation with its evidence) to this file}
        {--propose : Create PENDING_APPROVAL adjustment proposals for the PROVABLE_UNDEDUCTED installations (no stock moves)}
        {--user= : Maker (user id) recorded on the proposals; required with --propose}
        {--reason= : Reason recorded on the proposals; required with --propose}';

    protected $description = 'Dry-run (default) of the reconciliation of serialized units installed before installations settled with the warehouse ledger. --propose only files proposals for approval; stock is changed solely by an approved adjustment applied through the API.';

    public function handle(SerializedStockReconciliationService $service, StockReconciliationAdjustmentService $adjustments, TenantContext $context): int
    {
        $tenant = $this->option('tenant') ?: null;
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

        if (! $this->option('propose')) {
            $this->line('To file proposals for approval: --propose --user=<maker id> --reason="..."');

            return self::SUCCESS;
        }
        if (! $this->option('user') || ! trim((string) $this->option('reason'))) {
            $this->error('--propose needs --user and --reason.');

            return self::FAILURE;
        }
        $filed = 0;
        foreach ($plan['tenants'] as $t) {
            $context->setTenantId($t['tenant_id']);
            foreach (array_filter($t['candidates'], fn ($c) => $c['category'] === SerializedStockReconciliationService::UNDEDUCTED) as $c) {
                try {
                    $adjustments->propose($t['tenant_id'], $c['installation_id'], (string) $this->option('reason'), (string) $this->option('user'));
                    $filed++;
                } catch (InventoryException $e) {
                    $this->warn("  skipped {$c['installation_id']}: {$e->getMessage()}");
                }
            }
        }
        $this->info("Proposals filed (pending approval, stock untouched): {$filed}");

        return self::SUCCESS;
    }
}
