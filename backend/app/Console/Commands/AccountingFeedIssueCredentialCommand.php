<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Domain\Integration\AccountingFeed\FeedPairingGate;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AccountingFeedIssueCredentialCommand extends Command
{
    protected $signature = 'accounting-feed:issue-credential {tenant : Tenant id or code} {--name=OptiEntry : Label of the credential} {--expires-in-days= : Optional lifetime in days} {--by= : Who issues it (kept in the audit trail)}';

    protected $description = 'Issue a read-only accounting feed credential (scope accounting.feed.read) for one tenant, paired to its OptiNexus tenant; the token is shown once';

    public function handle(FeedPairingGate $gate, AuditService $audit): int
    {
        $ref = (string) $this->argument('tenant');
        $tenant = Str::isUuid($ref) ? Tenant::query()->find($ref) : Tenant::query()->where('code', $ref)->first();

        if (! $tenant) {
            $this->error("Tenant '{$ref}' not found.");

            return self::FAILURE;
        }
        if ($gate->enforced() && ! $tenant->optinexus_tenant_id) {
            $this->error("Tenant {$tenant->code} is not linked to an OptiNexus tenant; the feed is only served for a tenant OptiNexus pairs with the consumer.");

            return self::FAILURE;
        }

        $days = $this->option('expires-in-days');
        $by = trim((string) $this->option('by')) ?: null;
        [$credential, $token] = AccountingFeedCredential::issue($tenant, (string) $this->option('name'), $days ? now()->addDays((int) $days) : null, $by);
        $audit->log('accounting_feed_credential', $credential->id, 'accounting_feed.credential_issued', null, [
            'name' => $credential->name, 'prefix' => $credential->token_prefix, 'nexus_tenant_id' => $credential->nexus_tenant_id,
            'consumer_application' => $credential->consumer_application, 'expires_at' => $credential->expires_at?->toIso8601String(), 'by' => $by,
        ], $tenant->id);

        $this->info("Credential {$credential->id} issued for tenant {$tenant->code} ({$tenant->id}). Copy the token now, it cannot be shown again:");
        $this->line($token);

        $readiness = $gate->check($tenant, $credential);
        $this->line($readiness->allowed ? ($readiness->enforced ? 'Pairing: ready (both products are subscribed in OptiNexus).' : 'Pairing: not enforced on this installation.') : "Pairing: not ready yet ({$readiness->code}); the feed answers it until OptiNexus says otherwise.");

        return self::SUCCESS;
    }
}
