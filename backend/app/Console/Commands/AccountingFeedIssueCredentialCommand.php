<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AccountingFeedIssueCredentialCommand extends Command
{
    protected $signature = 'accounting-feed:issue-credential {tenant : Tenant id or code} {--name=OptiEntry : Label of the credential} {--expires-in-days= : Optional lifetime in days}';

    protected $description = 'Issue a read-only accounting feed credential (scope accounting.feed.read) for one tenant; the token is shown once';

    public function handle(): int
    {
        $ref = (string) $this->argument('tenant');
        $tenant = Str::isUuid($ref) ? Tenant::query()->find($ref) : Tenant::query()->where('code', $ref)->first();

        if (! $tenant) {
            $this->error("Tenant '{$ref}' not found.");

            return self::FAILURE;
        }

        $days = $this->option('expires-in-days');
        [$credential, $token] = AccountingFeedCredential::issue($tenant, (string) $this->option('name'), $days ? now()->addDays((int) $days) : null);

        $this->info("Credential {$credential->id} issued for tenant {$tenant->code} ({$tenant->id}). Copy the token now, it cannot be shown again:");
        $this->line($token);

        return self::SUCCESS;
    }
}
