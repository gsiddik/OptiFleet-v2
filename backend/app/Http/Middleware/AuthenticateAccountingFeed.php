<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Domain\Subscription\Models\Subscription;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine authentication for /api/v1/integration/accounting-feed: a hashed, read-only bearer credential that is
 * bound to exactly one tenant. The tenant comes from the credential row and nowhere else (never from the query,
 * body or a header), is put on the request attributes and in the shared TenantContext, and the credential needs the
 * route's scope. Unknown, revoked and expired tokens all answer the same 401 so a token cannot be probed.
 * Nothing is written here (no last-used stamp): the feed has no write side effects.
 */
class AuthenticateAccountingFeed
{
    public const TENANT_ATTRIBUTE = 'accounting_feed_tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $scope = AccountingFeedCredential::SCOPE_READ): Response
    {
        $token = $request->bearerToken();
        $credential = $token ? AccountingFeedCredential::findByToken($token) : null;

        if (! $credential || ! $credential->isUsable()) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'FEED_UNAUTHENTICATED'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $credential->hasScope($scope)) {
            return response()->json(['message' => 'The credential does not carry the required scope.', 'code' => 'FEED_SCOPE_MISSING'], 403);
        }

        $tenant = Tenant::query()->find($credential->tenant_id);
        if (! $tenant || $tenant->status !== 'ACTIVE') {
            return response()->json(['message' => 'The tenant is not active.', 'code' => 'FEED_TENANT_NOT_ACTIVE'], 403);
        }

        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->whereNotIn('status', ['CANCELLED', 'EXPIRED'])->latest('created_at')->first();
        if ($subscription && $subscription->isSuspended()) {
            return response()->json(['message' => 'The tenant subscription is suspended.', 'code' => 'FEED_SUBSCRIPTION_SUSPENDED'], 403);
        }

        $this->context->setTenantId($tenant->id);
        $this->context->setPlatformContext(false);
        $request->attributes->set(self::TENANT_ATTRIBUTE, $tenant->id);

        return $next($request);
    }
}
