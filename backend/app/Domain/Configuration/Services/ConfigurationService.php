<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Section 1/2: generic DRAFT -> PUBLISHED -> ARCHIVED lifecycle shared by
 * numbering/template/workflow/notification configuration. Publishing never
 * overwrites a prior published version's row — it archives the old one and
 * inserts a new version number, so history is preserved and any in-flight
 * transaction that captured an old configuration_version_id keeps working
 * unchanged.
 */
class ConfigurationService
{
    public function __construct(private readonly ConfigurationCacheService $cache) {}

    public function findOrCreateSet(
        ?string $tenantId,
        string $type,
        string $code,
        string $scopeType,
        ?string $scopeResourceId,
        string $name,
        bool $isSystem = false,
    ): ConfigurationSet {
        $existing = ConfigurationSet::query()
            ->withoutGlobalScopes()
            ->where('type', $type)->where('code', $code)
            ->where('scope_type', $scopeType)
            ->where(fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q->whereNull('tenant_id'))
            ->when($scopeResourceId, fn ($q) => $q->where('scope_resource_id', $scopeResourceId), fn ($q) => $q->whereNull('scope_resource_id'))
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return ConfigurationSet::query()->create([
                'tenant_id' => $tenantId,
                'type' => $type,
                'code' => $code,
                'scope_type' => $scopeType,
                'scope_resource_id' => $scopeResourceId,
                'name' => $name,
                'is_system' => $isSystem,
            ]);
        } catch (QueryException $e) {
            throw new ConfigurationException("A configuration of type {$type}/{$code} already exists for this scope.");
        }
    }

    public function createDraft(ConfigurationSet $set, array $payload, ?string $userId, ?string $changeSummary = null): ConfigurationVersion
    {
        return DB::transaction(function () use ($set, $payload, $userId, $changeSummary) {
            $locked = ConfigurationSet::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($set->id);
            $nextVersion = (int) ConfigurationVersion::query()->where('configuration_set_id', $locked->id)->max('version_number') + 1;

            return ConfigurationVersion::query()->create([
                'configuration_set_id' => $locked->id,
                'version_number' => $nextVersion,
                'status' => 'DRAFT',
                'payload' => $payload,
                'change_summary' => $changeSummary,
                'created_by' => $userId,
            ]);
        });
    }

    public function updateDraft(ConfigurationVersion $version, array $payload, ?string $changeSummary = null): ConfigurationVersion
    {
        $locked = ConfigurationVersion::query()->lockForUpdate()->findOrFail($version->id);
        if ($locked->status !== 'DRAFT') {
            throw new ConfigurationException('Only a DRAFT version can be edited.');
        }
        $locked->update(['payload' => $payload, 'change_summary' => $changeSummary ?? $locked->change_summary]);

        return $locked->fresh();
    }

    public function publish(ConfigurationVersion $version, ?string $userId, ?callable $validator = null): ConfigurationVersion
    {
        return DB::transaction(function () use ($version, $userId, $validator) {
            $locked = ConfigurationVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'DRAFT') {
                throw new ConfigurationException('Only a DRAFT version can be published.');
            }
            if ($validator) {
                $validator($locked->payload);
            }

            $set = ConfigurationSet::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->configuration_set_id);
            $currentlyPublished = ConfigurationVersion::query()
                ->where('configuration_set_id', $set->id)->where('status', 'PUBLISHED')->lockForUpdate()->first();
            $currentlyPublished?->update(['status' => 'ARCHIVED', 'archived_at' => now()]);

            $locked->update(['status' => 'PUBLISHED', 'published_by' => $userId, 'published_at' => now()]);
            $this->cache->forget($set->type, $set->code);

            return $locked->fresh();
        });
    }

    public function archive(ConfigurationVersion $version, ?string $userId): ConfigurationVersion
    {
        return DB::transaction(function () use ($version) {
            $locked = ConfigurationVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'PUBLISHED') {
                throw new ConfigurationException('Only a PUBLISHED version can be archived.');
            }
            $locked->update(['status' => 'ARCHIVED', 'archived_at' => now()]);

            $set = ConfigurationSet::query()->withoutGlobalScopes()->find($locked->configuration_set_id);
            if ($set) {
                $this->cache->forget($set->type, $set->code);
            }

            return $locked->fresh();
        });
    }
}
