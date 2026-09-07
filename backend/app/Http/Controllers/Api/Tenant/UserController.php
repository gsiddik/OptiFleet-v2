<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\TenantUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessControl\AssignRoleRequest;
use App\Http\Requests\Platform\StoreTenantMembershipRequest;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(
        private readonly CapacityService $capacity,
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();

        $query = TenantUser::query()->where('tenant_id', $tenantId)->with('user');

        if ($search = $request->string('search')->trim()->value()) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return $this->paginated($query->paginate($request->integer('per_page', 20)), fn (TenantUser $m) => [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'status' => $m->status,
            'joined_at' => $m->joined_at,
            'roles' => RoleAssignment::query()
                ->where('user_id', $m->user_id)
                ->where('tenant_id', $tenantId)
                ->with('role')
                ->get()
                ->pluck('role.name'),
        ]);
    }

    public function store(StoreTenantMembershipRequest $request)
    {
        $tenantId = $this->context->tenantId();
        $this->capacity->assertCanCreate($tenantId, 'user');

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
            ['tenant_id' => $tenantId, 'user_id' => $user->id],
            ['status' => 'active', 'joined_at' => now()]
        );

        return $this->ok(['membership' => $membership, 'user' => $user], 201);
    }

    public function update(Request $request, TenantUser $tenantUser)
    {
        abort_unless($tenantUser->tenant_id === $this->context->tenantId(), 404);

        $status = $request->string('status')->value();
        abort_unless(in_array($status, ['active', 'inactive'], true), 422, 'Invalid status.');

        $tenantUser->update(['status' => $status]);

        return $this->ok($tenantUser);
    }

    public function assignRole(AssignRoleRequest $request, TenantUser $tenantUser)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($tenantUser->tenant_id === $tenantId, 404);

        $assignment = RoleAssignment::query()->firstOrCreate([
            'user_id' => $tenantUser->user_id,
            'tenant_id' => $tenantId,
            'role_id' => $request->input('role_id'),
        ]);

        $this->permissions->forgetCache($tenantUser->user, $tenantId);

        return $this->ok($assignment, 201);
    }

    public function revokeRole(TenantUser $tenantUser, string $roleId)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($tenantUser->tenant_id === $tenantId, 404);

        RoleAssignment::query()
            ->where('user_id', $tenantUser->user_id)
            ->where('tenant_id', $tenantId)
            ->where('role_id', $roleId)
            ->delete();

        $this->permissions->forgetCache($tenantUser->user, $tenantId);

        return $this->message('Role revoked.');
    }
}
