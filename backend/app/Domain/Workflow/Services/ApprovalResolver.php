<?php

namespace App\Domain\Workflow\Services;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Governance\CentralGovernance;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Workflow\Models\WorkflowApprovalStep;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
 *
 * Central governance (audit A02/R05): in a tenant governed by OptiNexus a person is an eligible approver only while
 * OptiNexus lets them in, and a PERMISSION step is satisfied by the permission OptiNexus grants (local roles never add).
 * Resolving a list of approvers (notifications) degrades to "nobody" when OptiNexus cannot answer; deciding whether one
 * person may approve (userMatchesStep) does not guess: the unavailability reaches the caller, which denies.
 */
class ApprovalResolver
{
    public function __construct(
        private readonly CentralGovernance $central,
        private readonly PermissionService $permissions,
    ) {}

    public function resolveUserIds(string $tenantId, string $approverType, string $approverIdentifier): array
    {
        if ($this->central->governs($tenantId)) {
            try {
                return $this->resolveCentral($tenantId, $approverType, $approverIdentifier);
            } catch (GovernanceUnavailableException|GovernanceContractException $e) {
                Log::warning('Approvers could not be resolved because OptiNexus did not answer.', ['tenant_id' => $tenantId, 'approver_type' => $approverType]);

                return [];
            }
        }

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
        if ($this->central->governs($tenantId)) {
            // No try/catch: if OptiNexus cannot answer, the approval is refused with the 503 of the governance outage.
            return in_array($userId, $this->resolveCentral($tenantId, $step->approver_type, $step->approver_identifier, onlyUserId: $userId), true);
        }

        return in_array($userId, $this->resolveUserIds($tenantId, $step->approver_type, $step->approver_identifier), true);
    }

    /**
     * @return array<int, string>
     *
     * @throws GovernanceUnavailableException
     */
    private function resolveCentral(string $tenantId, string $approverType, string $identifier, ?string $onlyUserId = null): array
    {
        $candidates = match ($approverType) {
            'EXPLICIT_USER' => $this->isTenantMember($tenantId, $identifier) ? [$identifier] : [],
            // A PERMISSION step is open to every member OptiNexus grants the permission to; the scan is bounded.
            'PERMISSION' => TenantUser::query()->where('tenant_id', $tenantId)->where('status', 'active')
                ->whereHas('user', fn ($q) => $q->whereNotNull('optinexus_subject')->where('status', 'active'))
                ->when($onlyUserId !== null, fn ($q) => $q->where('user_id', $onlyUserId))
                ->orderBy('user_id')->limit((int) config('optinexus.governance.approver_scan_limit'))->pluck('user_id')->all(),
            // A role name is routing only (it can name a local role that restricts); OptiNexus still has to let the person in.
            'ROLE' => RoleAssignment::query()->where('tenant_id', $tenantId)
                ->whereHas('role', fn ($q) => $q->where('name', $identifier))->pluck('user_id')->unique()->values()->all(),
            default => [],
        };

        if ($onlyUserId !== null) {
            $candidates = array_values(array_intersect($candidates, [$onlyUserId]));
        }

        $eligible = [];
        foreach (User::query()->whereIn('id', $candidates)->get() as $user) {
            $snapshot = $this->central->snapshot($user, $tenantId);
            $allowed = $snapshot->sessionAllowed && $approverType === 'PERMISSION'
                ? $this->permissions->userHasPermission($user, $identifier, $tenantId)
                : $snapshot->sessionAllowed;

            if ($allowed) {
                $eligible[] = $user->id;
            }
        }

        return $eligible;
    }

    private function isTenantMember(string $tenantId, string $userId): bool
    {
        return TenantUser::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists();
    }
}
