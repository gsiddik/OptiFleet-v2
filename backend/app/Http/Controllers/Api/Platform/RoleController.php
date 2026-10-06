<?php

namespace App\Http\Controllers\Api\Platform;

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
            ->where('scope', 'platform')
            ->with('permissions')
            ->get()
            ->map(fn (Role $r) => $this->present($r));

        return $this->ok($roles);
    }

    public function store(StoreRoleRequest $request)
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::query()->create([
                'tenant_id' => null,
                'scope' => 'platform',
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
        abort_unless($role->scope === 'platform', 404);
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
        abort_unless($role->scope === 'platform', 404);

        $this->rolePermissions->sync($role, $request->input('permission_ids', []), $this->context->user(), null);

        return $this->ok($this->present($role->fresh('permissions')));
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            // Canonical identifier of a system-defined role (never shown, never translated); null for tenant roles.
            'code' => $role->code,
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
