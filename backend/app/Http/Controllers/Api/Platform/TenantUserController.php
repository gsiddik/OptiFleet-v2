<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Entitlement\Services\CapacityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignRoleRequest;
use App\Http\Requests\Platform\StoreTenantMembershipRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class TenantUserController extends Controller
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly PermissionService $permissions,
    ) {}

    public function index(Tenant $tenant)
    {
        $memberships = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->paginate(20);

        return $this->paginated($memberships, fn (TenantUser $m) => [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'status' => $m->status,
            'joined_at' => $m->joined_at,
            'roles' => RoleAssignment::query()
                ->where('user_id', $m->user_id)
                ->where('tenant_id', $tenant->id)
                ->with('role')
                ->get()
                ->pluck('role.name'),
        ]);
    }

    public function store(StoreTenantMembershipRequest $request, Tenant $tenant)
    {
        $this->capacity->assertCanCreate($tenant->id, 'user');

        if ($existingId = $request->input('existing_user_id')) {
            $user = User::query()->where('id', $existingId)->where('user_type', 'tenant')->firstOrFail();
        } else {
            $user = User::query()->firstOrCreate(
                ['email' => $request->string('email')],
                [
                    'name' => $request->string('name'),
                    'password' => Hash::make($request->string('password')),
                    'user_type' => 'tenant',
                    'status' => 'active',
                ]
            );
        }

        $membership = TenantUser::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['status' => 'active', 'joined_at' => now()]
        );

        return $this->ok(['membership' => $membership, 'user' => $user], 201);
    }

    public function update(Tenant $tenant, TenantUser $tenantUser)
    {
        abort_unless($tenantUser->tenant_id === $tenant->id, 404);

        $status = request()->string('status')->value();
        abort_unless(in_array($status, ['active', 'inactive'], true), 422, 'Invalid status.');

        $tenantUser->update(['status' => $status]);

        return $this->ok($tenantUser);
    }

    public function assignRole(AssignRoleRequest $request, Tenant $tenant, TenantUser $tenantUser)
    {
        abort_unless($tenantUser->tenant_id === $tenant->id, 404);

        $assignment = RoleAssignment::query()->firstOrCreate([
            'user_id' => $tenantUser->user_id,
            'tenant_id' => $tenant->id,
            'role_id' => $request->input('role_id'),
        ]);

        $this->permissions->forgetCache($tenantUser->user, $tenant->id);

        return $this->ok($assignment, 201);
    }

    public function revokeRole(Tenant $tenant, TenantUser $tenantUser, string $roleId)
    {
        abort_unless($tenantUser->tenant_id === $tenant->id, 404);

        RoleAssignment::query()
            ->where('user_id', $tenantUser->user_id)
            ->where('tenant_id', $tenant->id)
            ->where('role_id', $roleId)
            ->delete();

        $this->permissions->forgetCache($tenantUser->user, $tenant->id);

        return $this->message('Role revoked.');
    }
}
