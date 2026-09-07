<?php

namespace App\Domain\Notification\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\WorkOrder\Models\WorkOrder;

/**
 * Section 32/51: the only place escalation reads a resource's live status
 * — a small, explicit allow-list of (resource_type -> model), tenant-
 * scoped and selecting only the 'status' column, never an unrestricted
 * lookup. The same "whitelist exactly what's exposed" discipline
 * DocumentTemplateContextBuilder uses for templates.
 */
class ResourceStatusLookup
{
    private const MODELS = [
        'breakdown' => Breakdown::class,
        'maintenance_request' => MaintenanceRequest::class,
        'work_order' => WorkOrder::class,
    ];

    public function currentStatus(string $resourceType, string $resourceId, string $tenantId): ?string
    {
        $modelClass = self::MODELS[$resourceType] ?? null;
        if (! $modelClass) {
            return null;
        }

        $row = $modelClass::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $resourceId)
            ->first(['status']);

        return $row?->status;
    }
}
