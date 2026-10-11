<?php

namespace App\Console\Commands;

use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AccountingFeedRevokeCredentialCommand extends Command
{
    protected $signature = 'accounting-feed:revoke-credential {credential : Credential id}';

    protected $description = 'Revoke an accounting feed credential (takes effect on its next request)';

    public function handle(): int
    {
        $id = (string) $this->argument('credential');
        $credential = Str::isUuid($id) ? AccountingFeedCredential::query()->find($id) : null;

        if (! $credential) {
            $this->error("Credential '{$id}' not found.");

            return self::FAILURE;
        }

        $credential->revoked_at ??= now();
        $credential->save();
        $this->info("Credential {$credential->id} revoked.");

        return self::SUCCESS;
    }
}
