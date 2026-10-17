<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AccountingFeedCredentialsCommand extends Command
{
    protected $signature = 'accounting-feed:credentials {tenant? : Tenant id or code}';

    protected $description = 'List accounting feed credentials with their state and OptiNexus pairing (never the token)';

    public function handle(): int
    {
        $ref = $this->argument('tenant');
        $tenant = $ref ? (Str::isUuid((string) $ref) ? Tenant::query()->find($ref) : Tenant::query()->where('code', $ref)->first()) : null;
        if ($ref && ! $tenant) {
            $this->error("Tenant '{$ref}' not found.");

            return self::FAILURE;
        }

        $rows = AccountingFeedCredential::query()->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))->orderBy('created_at')->get();
        $tenants = Tenant::query()->whereIn('id', $rows->pluck('tenant_id'))->get()->keyBy('id');

        $this->table(['Id', 'Tenant', 'Name', 'Prefix', 'State', 'Pairing', 'Expires', 'Revoked'], $rows->map(function (AccountingFeedCredential $c) use ($tenants): array {
            $linked = $tenants[$c->tenant_id]->optinexus_tenant_id ?? null;
            $pairing = match (true) {
                ! $c->nexus_tenant_id => 'UNPAIRED (rotate)',
                $linked === null || strtolower($linked) !== strtolower($c->nexus_tenant_id) => 'CHANGED (rotate)',
                default => 'paired',
            };

            return [$c->id, $tenants[$c->tenant_id]->code ?? $c->tenant_id, $c->name, $c->token_prefix, $c->isUsable() ? 'active' : ($c->revoked_at ? 'revoked' : 'expired'), $pairing, $c->expires_at?->toDateTimeString(), $c->revoked_at?->toDateTimeString()];
        })->all());

        return self::SUCCESS;
    }
}
