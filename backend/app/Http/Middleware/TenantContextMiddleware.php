<?php

namespace App\Http\Middleware;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Shared\Support\Messages;
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
            return $this->error($request, 'errors.http.unauthenticated', 401);
        }

        if (! $user->isActive()) {
            return $this->error($request, 'errors.http.userAccountInactive', 403);
        }

        $this->context->setUser($user);

        $token = $user->currentAccessToken();
        $abilities = $token?->abilities ?? [];
        $tenantAbility = collect($abilities)->first(fn ($a) => str_starts_with($a, 'tenant:'));

        if ($tenantAbility) {
            $tenantId = substr($tenantAbility, strlen('tenant:'));

            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant || $tenant->status !== 'ACTIVE') {
                return $this->error($request, 'errors.http.tenantIsNotActive', 403);
            }

            $membership = TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->first();

            if (! $membership || ! $membership->isActive()) {
                return $this->error($request, 'errors.http.tenantMembershipNotActive', 403);
            }

            $this->context->setTenantId($tenantId);
            $this->context->setTenantDefaultLocale($tenant->default_locale);
            $this->context->setPlatformContext(false);
        } elseif (in_array('platform', $abilities, true)) {
            $this->context->setPlatformContext(true);
        } else {
            return $this->error($request, 'errors.http.tokenNoValidScope', 403);
        }

        return $next($request);
    }

    /**
     * These errors are returned before the request locale is resolved, so their language comes from the
     * user's own preference, else the request's Accept-Language, else English.
     */
    private function error(Request $request, string $key, int $status): Response
    {
        $locale = DocumentLocale::resolve($request->user()?->preferred_locale, null, DocumentLocale::fromLanguages($request->getLanguages()));

        return response()->json(['message' => Messages::text($key, [], $locale)], $status);
    }
}
