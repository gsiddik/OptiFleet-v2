<?php

namespace App\Http\Support;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

class CurrentUserPresenter
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
        private readonly CentralGovernance $governance,
    ) {}

    public function present(User $user): array
    {
        $isPlatform = $user->isPlatformUser();
        $tenantId = $isPlatform ? null : $this->context->tenantId();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'scope' => $isPlatform ? 'platform' : 'tenant',
            // i18n: the UI resolves its language as preferred_locale → tenant_default_locale → browser → en.
            'preferred_locale' => $user->preferred_locale,
            'tenant_default_locale' => $tenantId ? Tenant::query()->whereKey($tenantId)->value('default_locale') : null,
            'permissions' => $this->permissions->permissionsFor($user, $tenantId)->values(),
            'memberships' => $isPlatform ? [] : $this->memberships($user),
            // Who decides what this person may do: OptiNexus (CENTRAL) or this application (LOCAL, legacy).
            'governance' => $tenantId ? $this->governance->describe($user, $tenantId) : null,
        ];
    }

    private function memberships(User $user): array
    {
        return $user->tenantMemberships()
            ->with('tenant')
            ->get()
            ->map(function ($membership) use ($user) {
                $roles = RoleAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('tenant_id', $membership->tenant_id)
                    ->with('role')
                    ->get()
                    ->pluck('role.name');

                return [
                    'tenant_id' => $membership->tenant_id,
                    'tenant_code' => $membership->tenant->code,
                    'tenant_name' => $membership->tenant->name,
                    'tenant_logo_url' => $membership->tenant->logo_url,
                    // The tenant's identity at OptiNexus (null when not linked): the product switcher's `tenant_hint` is compared with it.
                    'optinexus_tenant_id' => $membership->tenant->optinexus_tenant_id,
                    'status' => $membership->status,
                    'roles' => $roles,
                ];
            })
            ->values()
            ->all();
    }
}
