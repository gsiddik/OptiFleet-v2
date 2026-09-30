<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\RolePermissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignPermissionsRequest;
use App\Http\Requests\AccessControl\StoreRoleRequest;
use App\Http\Requests\AccessControl\UpdateRoleRequest;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(
        private readonly RolePermissionService $rolePermissions,
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

        $role = DB::transaction(function () use ($request, $tenantId) {
            $role = Role::query()->create([
                'tenant_id' => $tenantId,
                'scope' => 'tenant',
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'is_system' => false,
            ]);
            $this->rolePermissions->sync($role, $request->input('permission_ids', []), null, null);

            return $role;
        });

        return $this->ok($this->present($role->fresh('permissions')), 201);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $this->authorizeTenantRole($role);
        $this->rolePermissions->assertEditable($role);
        $validated = $request->validated();
        // Seeded (system) roles keep their name — seeders and provisioning address them by it —
        // but their description and permission set are managed like any other role.
        if ($role->is_system && array_key_exists('name', $validated) && $validated['name'] !== $role->name) {
            abort(422, 'The name of a system role cannot be changed.');
        }

        $role->update($validated);

        return $this->ok($this->present($role->fresh('permissions')));
    }

    public function assignPermissions(AssignPermissionsRequest $request, Role $role)
    {
        $this->authorizeTenantRole($role);

        $this->rolePermissions->sync($role, $request->input('permission_ids', []), $this->context->user(), $this->context->tenantId());

        return $this->ok($this->present($role->fresh('permissions')));
    }

    private function authorizeTenantRole(Role $role): void
    {
        abort_unless($role->tenant_id === $this->context->tenantId(), 404);
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
            'permission_ids' => $role->permissions->pluck('id'),
            'editable' => ! ($role->scope === 'platform' && $role->is_system),
        ];
    }
}
