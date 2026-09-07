<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\PermissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignPermissionsRequest;
use App\Http\Requests\AccessControl\StoreRoleRequest;
use App\Http\Requests\AccessControl\UpdateRoleRequest;

class RoleController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function index()
    {
        $roles = Role::query()
            ->where('scope', 'platform')
            ->with('permissions')
            ->get()
            ->map(fn (Role $r) => $this->present($r));

        return $this->ok($roles);
    }

    public function store(StoreRoleRequest $request)
    {
        $role = Role::query()->create([
            'tenant_id' => null,
            'scope' => 'platform',
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'is_system' => false,
        ]);

        $this->syncPermissions($role, $request->input('permission_ids', []));

        return $this->ok($this->present($role->fresh('permissions')), 201);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        abort_unless($role->scope === 'platform', 404);
        abort_if($role->is_system, 422, 'System roles cannot be modified.');

        $role->update($request->validated());

        return $this->ok($this->present($role->fresh('permissions')));
    }

    public function assignPermissions(AssignPermissionsRequest $request, Role $role)
    {
        abort_unless($role->scope === 'platform', 404);

        $this->syncPermissions($role, $request->input('permission_ids'));

        return $this->ok($this->present($role->fresh('permissions')));
    }

    private function syncPermissions(Role $role, array $permissionIds): void
    {
        $validIds = Permission::query()
            ->where('scope', 'platform')
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
