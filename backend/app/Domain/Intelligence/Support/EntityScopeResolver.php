<?php

namespace App\Domain\Intelligence\Support;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Models\User;

/**
 * Phase 7 Section 60: intelligence documents carry vehicle_id/entity_id,
 * not branch_id directly, so branch-scope enforcement (Section 6/42)
 * resolves through the Vehicle table — a branch-restricted user's
 * allowed vehicle set — rather than re-implementing DataScopeService.
 */
class EntityScopeResolver
{
    public function __construct(private readonly DataScopeService $scope) {}

    /** @return string[]|null null = unrestricted (TENANT scope) */
    public function allowedVehicleIds(User $user, string $tenantId): ?array
    {
        $allowedBranches = $this->scope->allowedBranchIds($user, $tenantId);
        if ($allowedBranches === null) {
            return null;
        }

        return Vehicle::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('branch_id', $allowedBranches)
            ->pluck('id')->all();
    }

    public function canAccessVehicle(User $user, string $tenantId, string $vehicleId): bool
    {
        $allowed = $this->allowedVehicleIds($user, $tenantId);

        return $allowed === null || in_array($vehicleId, $allowed, true);
    }
}
