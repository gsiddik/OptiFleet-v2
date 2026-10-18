<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Governance\CentralGovernance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    private const CACHE_TAG = 'permissions';

    public function __construct(private readonly CentralGovernance $central) {}

    /**
     * Effective permission names for a user in a given context.
     * Platform users resolve platform-scope role assignments (tenant_id null).
     * Tenant users resolve role assignments for the active tenant only.
     *
     * In a tenant governed centrally (audit A02/R05) OptiNexus is the only source of grants: the answer is what it grants,
     * narrowed by the user's local roles if they have any. Local roles never add, and nothing is read from them when OptiNexus
     * cannot answer (GovernanceUnavailableException: the caller denies).
     */
    public function permissionsFor(User $user, ?string $tenantId): Collection
    {
        if ($tenantId !== null && ! $user->isPlatformUser() && $this->central->governs($tenantId)) {
            return $this->central->permissionsFor($user, $tenantId, $this->localRestriction($user, $tenantId));
        }

        $cacheKey = "permissions:user:{$user->id}:tenant:".($tenantId ?? 'platform');

        return Cache::tags([self::CACHE_TAG])->remember($cacheKey, 60, function () use ($user, $tenantId) {
            $query = RoleAssignment::query()->where('user_id', $user->id);

            if ($tenantId === null) {
                $query->whereNull('tenant_id');
            } else {
                $query->where('tenant_id', $tenantId);
            }

            return $query->with('role.permissions')
                ->get()
                ->flatMap(fn (RoleAssignment $assignment) => $assignment->role->permissions->pluck('name'))
                ->unique()
                ->values();
        });
    }

    public function userHasPermission(User $user, string $permission, ?string $tenantId): bool
    {
        return $this->permissionsFor($user, $tenantId)->contains($permission);
    }

    public function forgetCache(User $user, ?string $tenantId): void
    {
        Cache::tags([self::CACHE_TAG])->forget("permissions:user:{$user->id}:tenant:".($tenantId ?? 'platform'));
        if ($tenantId !== null) {
            Cache::tags([self::CACHE_TAG])->forget("permissions:local:user:{$user->id}:tenant:{$tenantId}");
        }
    }

    /**
     * The permissions the user's local roles in this tenant allow (their union), or null when the user has no local role
     * there. Used only as a restriction on central grants.
     *
     * @return ?Collection<int, string>
     */
    public function localRestriction(User $user, string $tenantId): ?Collection
    {
        $stored = Cache::tags([self::CACHE_TAG])->remember("permissions:local:user:{$user->id}:tenant:{$tenantId}", 60, function () use ($user, $tenantId) {
            $assignments = RoleAssignment::query()->where('user_id', $user->id)->where('tenant_id', $tenantId)->with('role.permissions')->get();

            return [
                'assigned' => $assignments->isNotEmpty(),
                'permissions' => $assignments->flatMap(fn (RoleAssignment $a) => $a->role->permissions->pluck('name'))->unique()->values()->all(),
            ];
        });

        return $stored['assigned'] ? collect($stored['permissions']) : null;
    }

    /**
     * Flushes every cached permission set. Used whenever a role's
     * permission list changes, since that can affect many users at once.
     */
    public function flushAll(): void
    {
        Cache::tags([self::CACHE_TAG])->flush();
    }
}
