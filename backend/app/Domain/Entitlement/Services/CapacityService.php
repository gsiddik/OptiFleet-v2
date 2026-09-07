<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;

/**
 * Enforces per-resource capacity limits for a tenant. A resource counts
 * against its limit unless it has reached the terminal CLOSED state (or,
 * for users, been removed from the tenant) — DRAFT/ACTIVE/INACTIVE records
 * still occupy a slot since they represent provisioned capacity.
 */
class CapacityService
{
    public function limitFor(string $tenantId, string $resourceType): ?int
    {
        return TenantCapacityLimit::query()
            ->where('tenant_id', $tenantId)
            ->where('resource_type', $resourceType)
            ->value('max_count');
    }

    public function currentCount(string $tenantId, string $resourceType): int
    {
        return match ($resourceType) {
            'branch' => Branch::query()->where('tenant_id', $tenantId)->where('status', '!=', 'CLOSED')->count(),
            'workshop' => Workshop::query()->where('tenant_id', $tenantId)->where('status', '!=', 'CLOSED')->count(),
            'warehouse' => Warehouse::query()->where('tenant_id', $tenantId)->where('status', '!=', 'CLOSED')->count(),
            'user' => TenantUser::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'vehicle' => 0, // Vehicle module is out of scope for Phase 1
            default => 0,
        };
    }

    public function canCreate(string $tenantId, string $resourceType): bool
    {
        $limit = $this->limitFor($tenantId, $resourceType);

        if ($limit === null) {
            return true; // no limit configured = unrestricted
        }

        return $this->currentCount($tenantId, $resourceType) < $limit;
    }

    public function assertCanCreate(string $tenantId, string $resourceType): void
    {
        if (! $this->canCreate($tenantId, $resourceType)) {
            $limit = $this->limitFor($tenantId, $resourceType);
            throw new CapacityExceededException(
                "Capacity limit reached for {$resourceType}: maximum of {$limit} allowed."
            );
        }
    }
}
