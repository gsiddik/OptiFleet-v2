<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\WheelConfiguration;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which wheel positions currently apply to a (tenant, vehicle category), and the lock that keeps a
 * configuration save and a tire install/rotation from interleaving.
 *
 * - Once the tenant has saved a versioned configuration for the category, only the tenant's own
 *   ACTIVE positions apply (platform defaults no longer do).
 * - Before that, the legacy behavior is kept: the tenant's and the platform's ACTIVE positions.
 * RETIRED positions never apply; they only exist for history.
 *
 * Every query filters tenant_id explicitly instead of relying on the request's tenant context.
 */
class WheelPositionCatalog
{
    /**
     * Transaction-scoped Postgres advisory lock per (tenant, category): exclusive for a
     * configuration save, shared for installs/rotations — a save waits for in-flight installs
     * and blocks new ones until it commits. Must be called inside a transaction.
     */
    public function lock(string $tenantId, string $vehicleCategoryId, bool $exclusive): void
    {
        $function = $exclusive ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';
        DB::select("SELECT {$function}(hashtextextended(?, 0))", ["wheel-configuration:{$tenantId}:{$vehicleCategoryId}"]);
    }

    public function activeVersion(string $tenantId, string $vehicleCategoryId): ?WheelConfigurationVersion
    {
        return WheelConfigurationVersion::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('vehicle_category_id', $vehicleCategoryId)
            ->where('status', WheelConfigurationVersion::STATUS_ACTIVE)
            ->first();
    }

    /** @return Builder<WheelConfiguration> */
    public function currentPositions(string $tenantId, string $vehicleCategoryId): Builder
    {
        $versioned = $this->activeVersion($tenantId, $vehicleCategoryId) !== null;

        return WheelConfiguration::query()->withoutGlobalScopes()
            ->where('vehicle_category_id', $vehicleCategoryId)
            ->where('status', WheelConfiguration::STATUS_ACTIVE)
            ->where(fn ($q) => $versioned
                ? $q->where('tenant_id', $tenantId)
                : $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
