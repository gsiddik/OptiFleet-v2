<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/v1/platform/*. Requires a platform-scope token AND a
 * platform-type user account, so a tenant user can never reach platform
 * endpoints even with a crafted/leaked token ability.
 */
class EnsurePlatformScope
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->isPlatformContext() || ! $this->context->user()?->isPlatformUser()) {
            return response()->json(['message' => 'Platform access required.'], 403);
        }

        return $next($request);
    }
}
