<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\PermissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignPermissionsRequest;
use App\Http\Requests\AccessControl\StoreRoleRequest;
use App\Http\Requests\AccessControl\UpdateRoleRequest;
use App\Support\TenantContext;

class RoleController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    public function index()
    {
        $roles = Role::query()
            ->where('tenant_id', $this->context->tenantId())
            ->with('permissions')
            ->get()
            ->map(fn (Role $r) => $this->present($r));

        return $this->ok($roles);
    }

    public function store(StoreRoleRequest $request)
    {
        $tenantId = $this->context->tenantId();

        $role = Role::query()->create([
            'tenant_id' => $tenantId,
            'scope' => 'tenant',
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'is_system' => false,
        ]);

        $this->syncPermissions($role, $request->input('permission_ids', []));

        return $this->ok($this->present($role->fresh('permissions')), 201);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $this->authorizeTenantRole($role);
        abort_if($role->is_system, 422, 'System roles cannot be modified.');

        $role->update($request->validated());

        return $this->ok($this->present($role->fresh('permissions')));
    }

    public function assignPermissions(AssignPermissionsRequest $request, Role $role)
    {
        $this->authorizeTenantRole($role);

        $this->syncPermissions($role, $request->input('permission_ids'));

        return $this->ok($this->present($role->fresh('permissions')));
    }

    private function authorizeTenantRole(Role $role): void
    {
        abort_unless($role->tenant_id === $this->context->tenantId(), 404);
    }

    private function syncPermissions(Role $role, array $permissionIds): void
    {
        $validIds = Permission::query()
            ->where('scope', 'tenant')
            ->whereIn('id', $permissionIds)
            ->pluck('id');

        $role->permissions()->sync($validIds);
        $this->permissions->flushAll();
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'scope' => $role->scope,
            'is_system' => $role->is_system,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name'),
        ];
    }
}
