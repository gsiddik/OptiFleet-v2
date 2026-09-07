<?php

namespace App\Http\Middleware;

use App\Domain\AccessControl\Services\PermissionService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `permission:resource.action`. Backend-authoritative
 * permission check — the frontend hiding a button is UX only, this is what
 * actually enforces "what can this user do".
 */
class CheckPermission
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $this->context->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $tenantId = $this->context->isPlatformContext() ? null : $this->context->tenantId();

        if (! $this->permissions->userHasPermission($user, $permission, $tenantId)) {
            return response()->json(['message' => "Missing required permission: {$permission}"], 403);
        }

        return $next($request);
    }
}
