<?php

namespace App\Domain\Notification\Services;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Workflow\Services\ApprovalResolver;
use App\Models\User;

/**
 * Section 30: resolves a rule's declared recipient_rules into concrete
 * (user_id | email) targets, strictly within one tenant. RBAC-based types
 * (PERMISSION/ROLE/EXPLICIT_USER) reuse ApprovalResolver's existing
 * tenant-scoped RoleAssignment query rather than duplicating it.
 * Entity-relative types (REQUESTER/APPROVER/ASSIGNED_MECHANIC/VEHICLE_PIC/
 * WAREHOUSE_PIC/VENDOR_CONTACT) are read straight out of the event
 * context the caller built — this resolver never reaches into a table to
 * "figure out" who the requester was, it only ever reads what the trusted
 * dispatch call site already put there, which is what keeps this
 * cross-tenant-safe (the context itself was built from the tenant's own
 * resource).
 */
class RecipientResolver
{
    public function __construct(
        private readonly ApprovalResolver $rbac,
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * @return array<int, array{user_id?: string, email?: string}>
     */
    public function resolve(array $recipientRules, string $tenantId, array $context): array
    {
        $targets = [];
        foreach ($recipientRules as $rule) {
            array_push($targets, ...$this->resolveOne($rule, $tenantId, $context));
        }

        $seen = [];

        return array_values(array_filter($targets, function ($target) use (&$seen) {
            $key = $target['user_id'] ?? $target['email'] ?? null;
            if (! $key || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }

    private function resolveOne(array $rule, string $tenantId, array $context): array
    {
        $type = $rule['type'] ?? null;
        $identifier = $rule['identifier'] ?? null;

        return match ($type) {
            // Section 51: EXPLICIT_USER is tenant-authored config data, so it goes through
            // ApprovalResolver's tenant-membership check — never trust a raw identifier
            // into a delivery target without confirming it belongs to this tenant.
            'EXPLICIT_USER' => array_map(fn ($id) => ['user_id' => $id], $this->rbac->resolveUserIds($tenantId, 'EXPLICIT_USER', (string) $identifier)),
            'PERMISSION' => array_map(fn ($id) => ['user_id' => $id], $this->rbac->resolveUserIds($tenantId, 'PERMISSION', $identifier)),
            'ROLE' => array_map(fn ($id) => ['user_id' => $id], $this->rbac->resolveUserIds($tenantId, 'ROLE', $identifier)),
            'BRANCH_MANAGER' => $this->resolveScopedRole($tenantId, 'Branch Manager', $context['branch_id'] ?? null, 'branch'),
            'WORKSHOP_MANAGER' => $this->resolveScopedRole($tenantId, 'Workshop Manager', $context['workshop_id'] ?? null, 'workshop'),
            'REQUESTER' => $this->fromContext($context, 'requester_user_id'),
            'APPROVER' => $this->fromContext($context, 'approver_user_id'),
            'ASSIGNED_MECHANIC' => $this->fromContext($context, 'assigned_mechanic_user_id'),
            'VEHICLE_PIC' => $this->fromContext($context, 'vehicle_pic_user_id'),
            'WAREHOUSE_PIC' => $this->fromContext($context, 'warehouse_pic_user_id'),
            'VENDOR_CONTACT' => $this->emailFromContext($context, 'vendor_contact_email'),
            'CUSTOM_EMAIL' => $this->validatedEmail($identifier),
            default => [],
        };
    }

    private function resolveScopedRole(string $tenantId, string $roleName, ?string $scopeId, string $scopeType): array
    {
        if (! $scopeId) {
            return [];
        }

        $userIds = $this->rbac->resolveUserIds($tenantId, 'ROLE', $roleName);
        if (empty($userIds)) {
            return [];
        }

        $matched = [];
        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $allowed = $scopeType === 'branch'
                ? $this->dataScope->canAccessBranch($user, $tenantId, $scopeId)
                : $this->dataScope->canAccessWorkshop($user, $tenantId, $scopeId);
            if ($allowed) {
                $matched[] = ['user_id' => $user->id];
            }
        }

        return $matched;
    }

    private function fromContext(array $context, string $key): array
    {
        return ! empty($context[$key]) ? [['user_id' => $context[$key]]] : [];
    }

    private function emailFromContext(array $context, string $key): array
    {
        return $this->validatedEmail($context[$key] ?? null);
    }

    private function validatedEmail(?string $email): array
    {
        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? [['email' => $email]] : [];
    }
}
