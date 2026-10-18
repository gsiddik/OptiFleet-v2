<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AccountingFeedRevokeCredentialCommand extends Command
{
    protected $signature = 'accounting-feed:revoke-credential {credential : Credential id} {--reason= : Why} {--by= : Who revokes it}';

    protected $description = 'Revoke an accounting feed credential (takes effect on its next request; Fleet keeps recording and projecting facts)';

    public function handle(AuditService $audit): int
    {
        $id = (string) $this->argument('credential');
        $credential = Str::isUuid($id) ? AccountingFeedCredential::query()->find($id) : null;

        if (! $credential) {
            $this->error("Credential '{$id}' not found.");

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason')) ?: null;
        $credential->end($reason);
        $audit->log('accounting_feed_credential', $credential->id, 'accounting_feed.credential_revoked', null, ['name' => $credential->name, 'prefix' => $credential->token_prefix, 'reason' => $reason, 'by' => trim((string) $this->option('by')) ?: null], $credential->tenant_id);
        $this->info("Credential {$credential->id} revoked.");

        return self::SUCCESS;
    }
}
