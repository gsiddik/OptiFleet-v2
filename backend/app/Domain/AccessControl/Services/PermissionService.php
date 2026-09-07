<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    private const CACHE_TAG = 'permissions';

    /**
     * Effective permission names for a user in a given context.
     * Platform users resolve platform-scope role assignments (tenant_id null).
     * Tenant users resolve role assignments for the active tenant only.
     */
    public function permissionsFor(User $user, ?string $tenantId): Collection
    {
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
