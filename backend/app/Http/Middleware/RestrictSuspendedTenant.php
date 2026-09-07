<?php

namespace App\Http\Middleware;

use App\Domain\Subscription\Models\Subscription;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 39: a SUSPENDED tenant keeps billing-only access (Account:
 * subscription/contract/invoice/payment, plus logout) but every
 * operational route is blocked at the API layer — React hiding a menu
 * item is UX only, this is the actual enforcement. Applied to the
 * operational route groups (organization, master data, access management,
 * dashboard); the /app/account/* routes are deliberately never wrapped
 * with this middleware so they keep working while suspended.
 *
 * Tenants with no commercial subscription at all (Phase 1 tenants with no
 * Phase 2 contract yet) are unaffected — this only blocks on an explicit
 * SUSPENDED subscription record, never on the absence of one.
 */
class RestrictSuspendedTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->context->tenantId();

        $subscription = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['CANCELLED', 'EXPIRED'])
            ->latest('created_at')
            ->first();

        if ($subscription && $subscription->isSuspended()) {
            return response()->json([
                'message' => 'Your subscription is suspended due to an outstanding balance. Please settle your invoice to restore access.',
                'code' => 'SUBSCRIPTION_SUSPENDED',
            ], 403);
        }

        return $next($request);
    }
}
