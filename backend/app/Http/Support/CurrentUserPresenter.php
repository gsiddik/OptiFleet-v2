<?php

namespace App\Http\Support;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\AccessControl\Services\PermissionService;
use App\Models\User;
use App\Support\TenantContext;

class CurrentUserPresenter
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
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
            'permissions' => $this->permissions->permissionsFor($user, $tenantId)->values(),
            'memberships' => $isPlatform ? [] : $this->memberships($user),
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
                    'status' => $membership->status,
                    'roles' => $roles,
                ];
            })
            ->values()
            ->all();
    }
}
