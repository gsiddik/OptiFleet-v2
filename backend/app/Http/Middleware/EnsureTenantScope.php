<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/v1/app/*. Requires a tenant-scope token bound to an active
 * tenant/membership (already validated by TenantContextMiddleware) AND a
 * tenant-type user account, so a platform user never inherits tenant access.
 */
class EnsureTenantScope
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasTenant() || $this->context->user()?->isPlatformUser()) {
            return response()->json(['message' => 'Tenant access required.'], 403);
        }

        return $next($request);
    }
}
