<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Audit\Services\AuditService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintains a Role's permission set (tenant and platform portals share this).
 *
 *  - Only permissions of the role's own scope are accepted; anything else is
 *    rejected (422), never silently dropped.
 *  - The platform superadmin role (platform + is_system) is re-granted every
 *    platform permission by PlatformSuperadminRoleSeeder, so it stays locked.
 *    Tenant roles — including the seeded default ones — are fully manageable.
 *  - An administrator cannot remove their own ability to manage role
 *    permissions (self lock-out guard).
 *  - The sync, audit entry and cache flush happen atomically; every affected
 *    user's cached permission set is invalidated, so the change applies on
 *    their next request without re-login.
 */
class RolePermissionService
{
    public const MANAGE_PERMISSION = 'role.assign_permission';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
    ) {}

    public function assertEditable(Role $role): void
    {
        if ($role->scope === 'platform' && $role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'The platform superadmin role always holds every platform permission and cannot be edited.',
            ]);
        }
    }

    /** @param array<int, string> $permissionIds */
    public function sync(Role $role, array $permissionIds, ?User $actor, ?string $actorTenantId): Role
    {
        $this->assertEditable($role);
        $permissionIds = array_values(array_unique($permissionIds));

        $valid = Permission::query()->where('scope', $role->scope)->whereIn('id', $permissionIds)->pluck('name', 'id');
        $invalid = array_values(array_diff($permissionIds, $valid->keys()->all()));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'permission_ids' => count($invalid).' selected permission(s) do not exist or do not belong to the '.$role->scope.' scope.',
            ]);
        }

        return DB::transaction(function () use ($role, $valid, $actor, $actorTenantId) {
            $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = $role->permissions()->pluck('name')->sort()->values()->all();
            $after = $valid->values()->sort()->values()->all();

            if ($actor !== null && ! in_array(self::MANAGE_PERMISSION, $after, true) && $this->wouldLockOut($actor, $role, $actorTenantId)) {
                throw ValidationException::withMessages([
                    'permission_ids' => 'You cannot remove "'.self::MANAGE_PERMISSION.'" from a role that is your only source of it — you would lose access to role management.',
                ]);
            }

            $role->permissions()->sync($valid->keys()->all());

            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added !== [] || $removed !== []) {
                $this->audit->log('Role', $role->id, 'permissions_changed', ['permissions' => $removed], ['permissions' => $added], $role->tenant_id);
            }

            // Flush now and again after commit, so no request can re-cache the old set in between.
            $this->permissions->flushAll();
            DB::afterCommit(fn () => $this->permissions->flushAll());

            return $role;
        });
    }

    /** True when the actor holds this role and no other assigned role grants role management. */
    private function wouldLockOut(User $actor, Role $role, ?string $tenantId): bool
    {
        $assignments = RoleAssignment::query()
            ->where('user_id', $actor->id)
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->pluck('role_id');

        if (! $assignments->contains($role->id)) {
            return false;
        }

        return ! DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->whereIn('role_permissions.role_id', $assignments->reject(fn ($id) => $id === $role->id))
            ->where('permissions.name', self::MANAGE_PERMISSION)
            ->where('permissions.scope', $role->scope)
            ->exists();
    }
}
