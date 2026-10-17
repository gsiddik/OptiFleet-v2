<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Governance\Delegation\TenantDelegationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Delegation\SaveDelegatedRoleRequest;
use App\Http\Requests\Delegation\SetDelegatedUserRolesRequest;
use App\Http\Requests\Delegation\StoreDelegatedUserRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Users and roles of a centrally governed tenant, through the OptiNexus delegation API (audit R08/A25). Thin: the route
 * middleware gates the permission, TenantDelegationService does everything else and raises DelegationException, which
 * bootstrap/app.php turns into a translated JSON error.
 */
class DelegationController extends Controller
{
    public function __construct(private readonly TenantDelegationService $delegation, private readonly TenantContext $context) {}

    public function catalog(Request $request): JsonResponse
    {
        return $this->ok($this->delegation->catalog($request->user(), $this->context->tenantId()));
    }

    public function users(Request $request): JsonResponse
    {
        $result = $this->delegation->users($request->user(), $this->context->tenantId(), $request->string('search')->trim()->value() ?: null, $request->integer('per_page', 25));

        return response()->json(['data' => $result['items'], 'meta' => $result['pagination']]);
    }

    public function addUser(StoreDelegatedUserRequest $request): JsonResponse
    {
        $user = $this->delegation->addUser($request->user(), $this->context->tenantId(), $request->validated());

        return $this->ok($user, $user['outcome'] === 'created' ? 201 : 200);
    }

    public function setUserRoles(SetDelegatedUserRolesRequest $request, string $nexusUserId): JsonResponse
    {
        return $this->ok($this->delegation->setUserRoles($request->user(), $this->context->tenantId(), $nexusUserId, $request->validated()['role_ids']));
    }

    public function revokeUser(Request $request, string $nexusUserId): JsonResponse
    {
        $this->delegation->revokeUser($request->user(), $this->context->tenantId(), $nexusUserId);

        return $this->ok(null);
    }

    public function createRole(SaveDelegatedRoleRequest $request): JsonResponse
    {
        return $this->ok($this->delegation->createRole($request->user(), $this->context->tenantId(), $request->validated()), 201);
    }

    public function updateRole(SaveDelegatedRoleRequest $request, string $roleId): JsonResponse
    {
        return $this->ok($this->delegation->updateRole($request->user(), $this->context->tenantId(), $roleId, $request->validated()));
    }

    public function deleteRole(Request $request, string $roleId): JsonResponse
    {
        $this->delegation->deleteRole($request->user(), $this->context->tenantId(), $roleId);

        return $this->ok(null);
    }
}
