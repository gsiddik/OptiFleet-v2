<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the authenticated request's tenant/platform context from the
 * Sanctum access token's abilities (never from a client-supplied header —
 * the token ability is the authoritative, server-issued claim). Populates
 * the TenantContext singleton that the tenant-aware Eloquent scopes read.
 *
 * Runs after auth:sanctum. Enforces, per request, that the user is active,
 * and — for tenant tokens — that the tenant is ACTIVE and the membership is
 * active. This is part of the effective-access chain (Section 14).
 */
class TenantContextMiddleware
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'This user account is inactive.'], 403);
        }

        $this->context->setUser($user);

        $token = $user->currentAccessToken();
        $abilities = $token?->abilities ?? [];
        $tenantAbility = collect($abilities)->first(fn ($a) => str_starts_with($a, 'tenant:'));

        if ($tenantAbility) {
            $tenantId = substr($tenantAbility, strlen('tenant:'));

            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant || $tenant->status !== 'ACTIVE') {
                return response()->json(['message' => 'Tenant is not active.'], 403);
            }

            $membership = TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->first();

            if (! $membership || ! $membership->isActive()) {
                return response()->json(['message' => 'Tenant membership is not active.'], 403);
            }

            $this->context->setTenantId($tenantId);
            $this->context->setPlatformContext(false);
        } elseif (in_array('platform', $abilities, true)) {
            $this->context->setPlatformContext(true);
        } else {
            return response()->json(['message' => 'Token has no valid scope.'], 403);
        }

        return $next($request);
    }
}
