<?php

namespace App\Http\Middleware;

use App\Domain\Governance\CentralGovernance;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\Messages;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `not.central`: refuses (409 GOVERNED_CENTRALLY) a change that would create a local identity or a local grant
 * in a tenant whose governance is CENTRAL (audit A02/R05). The tenant is the signed-in tenant, or the `{tenant}` of a platform
 * route, or the tenant that owns the bound contract, subscription, billing, invoice or payment of a platform route, or the `tenant_id`
 * the platform names in the body (wave 6, audit G9: the Superadmin commercial routes must not keep a second, local copy of what
 * OptiNexus bills). Narrowing changes (deactivate a user, local roles and data scopes that restrict) are not behind this gate.
 */
class RejectWhenCentrallyGoverned
{
    public function __construct(
        private readonly CentralGovernance $governance,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->tenantId($request);

        if ($tenantId !== null && $this->governance->governs($tenantId)) {
            return response()->json(['message' => Messages::localized('errors.http.governedCentrally'), 'code' => 'GOVERNED_CENTRALLY'], 409);
        }

        return $next($request);
    }

    private function tenantId(Request $request): ?string
    {
        $route = $request->route('tenant');
        if ($route instanceof Tenant) {
            return $route->id;
        }
        if (is_string($route)) {
            return $route;
        }

        foreach ($request->route()?->parameters() ?? [] as $bound) {
            if ($bound instanceof Model && ! $bound instanceof Tenant && $bound->getAttribute('tenant_id') !== null) {
                return (string) $bound->getAttribute('tenant_id');
            }
        }

        $named = $request->input('tenant_id');
        if (is_string($named) && $named !== '' && $this->context->tenantId() === null) {
            return $named;
        }

        return $this->context->tenantId();
    }
}
