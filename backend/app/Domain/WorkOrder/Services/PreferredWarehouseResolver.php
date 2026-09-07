<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\WorkOrder\Models\WorkOrder;

/**
 * Section 43: resolves a default stock source without ever silently moving
 * stock between warehouses — Workshop's own warehouse first, then the
 * branch's warehouse, then any other warehouse the caller is scoped to.
 * Returning null (no candidate) means the caller must ask the user to pick
 * one explicitly rather than guessing.
 */
class PreferredWarehouseResolver
{
    public function resolve(WorkOrder $workOrder, ?array $allowedWarehouseIds = null): ?Warehouse
    {
        $byWorkshop = Warehouse::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('workshop_id', $workOrder->workshop_id)
            ->where('status', 'ACTIVE')
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('id', $allowedWarehouseIds))
            ->orderBy('created_at')
            ->first();
        if ($byWorkshop) {
            return $byWorkshop;
        }

        $byBranch = Warehouse::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('branch_id', $workOrder->branch_id)
            ->where('status', 'ACTIVE')
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('id', $allowedWarehouseIds))
            ->orderBy('created_at')
            ->first();
        if ($byBranch) {
            return $byBranch;
        }

        return Warehouse::query()
            ->where('tenant_id', $workOrder->tenant_id)
            ->where('status', 'ACTIVE')
            ->when($allowedWarehouseIds !== null, fn ($q) => $q->whereIn('id', $allowedWarehouseIds))
            ->orderBy('created_at')
            ->first();
    }
}
