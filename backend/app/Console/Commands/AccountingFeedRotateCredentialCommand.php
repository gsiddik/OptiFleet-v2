<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingFeedRotateCredentialCommand extends Command
{
    protected $signature = 'accounting-feed:rotate-credential {credential : Credential id} {--grace-minutes=60 : How long the old credential keeps working so the consumer can swap secrets (0 = end it now)} {--expires-in-days= : Lifetime of the new credential (default: the old one\'s lifetime, else none)} {--by= : Who rotates it}';

    protected $description = 'Replace an accounting feed credential: issue a new one for the tenant\'s CURRENT OptiNexus pairing and end the old one after a grace period; the new token is shown once';

    public function handle(AuditService $audit): int
    {
        $id = (string) $this->argument('credential');
        $old = Str::isUuid($id) ? AccountingFeedCredential::query()->find($id) : null;
        $tenant = $old ? Tenant::query()->find($old->tenant_id) : null;

        if (! $old || ! $tenant) {
            $this->error("Credential '{$id}' not found.");

            return self::FAILURE;
        }

        $grace = max(0, (int) $this->option('grace-minutes'));
        $by = trim((string) $this->option('by')) ?: null;
        $days = $this->option('expires-in-days');
        $lifetime = $old->expires_at ? max(1, $old->created_at->diffInDays($old->expires_at)) : null;

        [$new, $token] = DB::transaction(function () use ($old, $tenant, $grace, $days, $lifetime, $by, $audit): array {
            [$new, $token] = AccountingFeedCredential::issue($tenant, $old->name, ($days ?: $lifetime) ? now()->addDays((int) ($days ?: $lifetime)) : null, $by, $old);
            $old->end('rotated', $grace > 0 ? now()->addMinutes($grace) : now());
            $audit->log('accounting_feed_credential', $new->id, 'accounting_feed.credential_rotated', null, [
                'replaces' => $old->id, 'old_prefix' => $old->token_prefix, 'new_prefix' => $new->token_prefix, 'nexus_tenant_id' => $new->nexus_tenant_id,
                'grace_minutes' => $grace, 'by' => $by,
            ], $tenant->id);

            return [$new, $token];
        });

        $this->info("Credential {$new->id} replaces {$old->id} for tenant {$tenant->code}. The old one ".($grace > 0 ? "stops working in {$grace} minutes" : 'stopped working now').'. Copy the new token now, it cannot be shown again:');
        $this->line($token);

        return self::SUCCESS;
    }
}
