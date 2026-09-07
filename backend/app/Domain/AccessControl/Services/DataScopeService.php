<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\DataScopeAssignment;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves "to which data can this user act" independently from
 * "what can this user do" (permissions). A TENANT scope assignment grants
 * unrestricted access within the tenant. BRANCH/WORKSHOP scope assignments
 * cascade down the organization hierarchy (branch -> its workshops -> their
 * warehouses). WAREHOUSE scope grants access to that single warehouse only.
 */
class DataScopeService
{
    public function assignments(User $user, string $tenantId): Collection
    {
        return DataScopeAssignment::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->get();
    }

    public function hasTenantScope(User $user, string $tenantId): bool
    {
        return $this->assignments($user, $tenantId)->contains(fn ($a) => $a->scope_type === 'TENANT');
    }

    public function allowedBranchIds(User $user, string $tenantId): ?array
    {
        $assignments = $this->assignments($user, $tenantId);

        if ($assignments->contains(fn ($a) => $a->scope_type === 'TENANT')) {
            return null; // unrestricted
        }

        return $assignments->where('scope_type', 'BRANCH')->pluck('scope_resource_id')->all();
    }

    public function allowedWorkshopIds(User $user, string $tenantId): ?array
    {
        $assignments = $this->assignments($user, $tenantId);

        if ($assignments->contains(fn ($a) => $a->scope_type === 'TENANT')) {
            return null;
        }

        $direct = $assignments->where('scope_type', 'WORKSHOP')->pluck('scope_resource_id')->all();

        $branchIds = $assignments->where('scope_type', 'BRANCH')->pluck('scope_resource_id')->all();
        $viaBranch = empty($branchIds) ? [] : Workshop::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($direct, $viaBranch)));
    }

    public function allowedWarehouseIds(User $user, string $tenantId): ?array
    {
        $assignments = $this->assignments($user, $tenantId);

        if ($assignments->contains(fn ($a) => $a->scope_type === 'TENANT')) {
            return null;
        }

        $direct = $assignments->where('scope_type', 'WAREHOUSE')->pluck('scope_resource_id')->all();

        $workshopIds = $this->allowedWorkshopIds($user, $tenantId) ?? [];
        $branchIds = $assignments->where('scope_type', 'BRANCH')->pluck('scope_resource_id')->all();

        $viaHierarchy = (empty($workshopIds) && empty($branchIds)) ? [] : Warehouse::query()
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $q) use ($workshopIds, $branchIds) {
                $q->whereIn('workshop_id', $workshopIds)
                    ->orWhereIn('branch_id', $branchIds);
            })
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($direct, $viaHierarchy)));
    }

    public function canAccessBranch(User $user, string $tenantId, string $branchId): bool
    {
        $allowed = $this->allowedBranchIds($user, $tenantId);

        return $allowed === null || in_array($branchId, $allowed, true);
    }

    public function canAccessWorkshop(User $user, string $tenantId, string $workshopId): bool
    {
        $allowed = $this->allowedWorkshopIds($user, $tenantId);

        return $allowed === null || in_array($workshopId, $allowed, true);
    }

    public function canAccessWarehouse(User $user, string $tenantId, string $warehouseId): bool
    {
        $allowed = $this->allowedWarehouseIds($user, $tenantId);

        return $allowed === null || in_array($warehouseId, $allowed, true);
    }

    public function applyBranchScope(Builder $query, User $user, string $tenantId): Builder
    {
        $allowed = $this->allowedBranchIds($user, $tenantId);

        return $allowed === null ? $query : $query->whereIn('id', $allowed);
    }

    public function applyWorkshopScope(Builder $query, User $user, string $tenantId): Builder
    {
        $allowed = $this->allowedWorkshopIds($user, $tenantId);

        return $allowed === null ? $query : $query->whereIn('id', $allowed);
    }

    public function applyWarehouseScope(Builder $query, User $user, string $tenantId): Builder
    {
        $allowed = $this->allowedWarehouseIds($user, $tenantId);

        return $allowed === null ? $query : $query->whereIn('id', $allowed);
    }
}
