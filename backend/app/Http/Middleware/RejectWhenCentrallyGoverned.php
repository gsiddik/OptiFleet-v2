<?php

namespace App\Http\Middleware;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\Messages;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `not.central`: refuses (409 GOVERNED_CENTRALLY) a change that would create a local identity or a local grant
 * in a tenant whose governance is CENTRAL (audit A02/R05). The tenant is the signed-in tenant, or the `{tenant}` of a platform
 * route. Narrowing changes (deactivate a user, local roles and data scopes that restrict) are not behind this gate.
 */
class RejectWhenCentrallyGoverned
{
    public function __construct(
        private readonly CentralGovernance $governance,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route('tenant');
        $tenantId = $route instanceof Tenant ? $route->id : (is_string($route) ? $route : $this->context->tenantId());

        if ($tenantId !== null && $this->governance->governs($tenantId)) {
            return response()->json(['message' => Messages::localized('errors.http.governedCentrally'), 'code' => 'GOVERNED_CENTRALLY'], 409);
        }

        return $next($request);
    }
}
