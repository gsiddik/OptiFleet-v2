<?php

namespace App\Domain\Configuration\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Section 45: published-configuration cache. Keys are always tenant- and
 * scope-aware so no tenant can ever read another tenant's resolved value.
 * Every read is tagged by both a tenant tag and a type+code tag so a
 * publish/archive can invalidate precisely (flushing the type+code tag
 * only ever removes cache entries for that configuration across tenants —
 * over-invalidation, never leakage).
 */
class ConfigurationCacheService
{
    private const TTL_SECONDS = 60;

    public function remember(string $tenantId, string $type, string $code, string $key, \Closure $resolver): mixed
    {
        return Cache::tags($this->tags($tenantId, $type, $code))->remember($key, self::TTL_SECONDS, $resolver);
    }

    public function forget(string $type, string $code): void
    {
        Cache::tags(["config-type:{$type}:{$code}"])->flush();
    }

    private function tags(string $tenantId, string $type, string $code): array
    {
        return ["config-tenant:{$tenantId}", "config-type:{$type}:{$code}"];
    }
}
