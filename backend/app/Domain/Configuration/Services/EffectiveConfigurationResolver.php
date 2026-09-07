<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;

/**
 * Section 2/44: the single place effective configuration is resolved for
 * numbering/template/workflow/notification, so no module duplicates this
 * inheritance logic. Resolution order (most specific wins):
 * Warehouse -> Workshop -> Branch -> Tenant default -> Platform default.
 */
class EffectiveConfigurationResolver
{
    public function __construct(private readonly ConfigurationCacheService $cache) {}

    public function resolve(
        string $type,
        string $code,
        string $tenantId,
        ?string $branchId = null,
        ?string $workshopId = null,
        ?string $warehouseId = null,
    ): ?ConfigurationVersion {
        $key = "config:resolved:{$type}:{$code}:{$tenantId}:{$branchId}:{$workshopId}:{$warehouseId}";

        return $this->cache->remember($tenantId, $type, $code, $key, function () use ($type, $code, $tenantId, $branchId, $workshopId, $warehouseId) {
            $candidates = [];
            if ($warehouseId) {
                $candidates[] = [ConfigurationSet::SCOPE_WAREHOUSE, $warehouseId];
            }
            if ($workshopId) {
                $candidates[] = [ConfigurationSet::SCOPE_WORKSHOP, $workshopId];
            }
            if ($branchId) {
                $candidates[] = [ConfigurationSet::SCOPE_BRANCH, $branchId];
            }
            $candidates[] = [ConfigurationSet::SCOPE_TENANT, null];

            foreach ($candidates as [$scopeType, $scopeResourceId]) {
                $version = $this->publishedVersionFor($type, $code, $tenantId, $scopeType, $scopeResourceId);
                if ($version) {
                    return $version;
                }
            }

            // Platform default fallback (tenant_id IS NULL, TENANT scope).
            return $this->publishedVersionFor($type, $code, null, ConfigurationSet::SCOPE_TENANT, null);
        });
    }

    private function publishedVersionFor(string $type, string $code, ?string $tenantId, string $scopeType, ?string $scopeResourceId): ?ConfigurationVersion
    {
        $set = ConfigurationSet::query()
            ->withoutGlobalScopes()
            ->where('type', $type)->where('code', $code)->where('scope_type', $scopeType)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'))
            ->when($scopeResourceId, fn ($q) => $q->where('scope_resource_id', $scopeResourceId), fn ($q) => $q->whereNull('scope_resource_id'))
            ->first();

        return $set?->versions()->where('status', 'PUBLISHED')->first();
    }
}
