<?php

namespace App\Http\Middleware;

use App\Domain\Entitlement\Services\EntitlementService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `module:MODULE_CODE`. Only applies within tenant scope
 * (platform routes are never module-gated). The React sidebar mirrors
 * entitlement for UX, but this is the authoritative backend gate.
 */
class CheckModuleEntitlement
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $tenantId = $this->context->tenantId();

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 403);
        }

        if (! $this->entitlements->tenantHasModule($tenantId, $moduleCode)) {
            return response()->json(['message' => "Module not entitled: {$moduleCode}"], 403);
        }

        return $next($request);
    }
}
