<?php

namespace App\Domain\Workflow\Services;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Workflow\Models\WorkflowApprovalStep;

/**
 * Section 22/51: resolves an approval step's approver_type/approver_identifier
 * into the set of eligible user ids, strictly within one tenant — reusing
 * the existing RoleAssignment/Role/Permission RBAC tables rather than a
 * hardcoded role name, so "who can approve this" always tracks whatever
 * role/permission assignments are true right now. EXPLICIT_USER is
 * validated against active TenantUser membership before being trusted —
 * an approver_identifier is tenant-supplied configuration data, so
 * resolving it into a user id must never be able to name a user outside
 * that tenant (cross-tenant approval-authority leakage).
 */
class ApprovalResolver
{
    public function resolveUserIds(string $tenantId, string $approverType, string $approverIdentifier): array
    {
        return match ($approverType) {
            'EXPLICIT_USER' => $this->isTenantMember($tenantId, $approverIdentifier) ? [$approverIdentifier] : [],
            'PERMISSION' => RoleAssignment::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('role.permissions', fn ($q) => $q->where('name', $approverIdentifier))
                ->pluck('user_id')->unique()->values()->all(),
            'ROLE' => RoleAssignment::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('role', fn ($q) => $q->where('name', $approverIdentifier))
                ->pluck('user_id')->unique()->values()->all(),
            default => [],
        };
    }

    public function userMatchesStep(WorkflowApprovalStep $step, string $tenantId, string $userId): bool
    {
        return in_array($userId, $this->resolveUserIds($tenantId, $step->approver_type, $step->approver_identifier), true);
    }

    private function isTenantMember(string $tenantId, string $userId): bool
    {
        return TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists();
    }
}
