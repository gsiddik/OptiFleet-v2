<?php

namespace App\Http\Middleware;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * i18n structural preparation (S7): the request locale used for framework validation messages is
 * resolved the same way as a document's language without an explicit choice — the user's preferred
 * locale, then the tenant default, then English.
 *
 * Off until the i18n rollout (`app.runtime_locale_resolution`, env APP_RUNTIME_LOCALE_RESOLUTION):
 * every response stays English. Browser Accept-Language is deliberately not used.
 */
class ResolveRequestLocale
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.runtime_locale_resolution')) {
            $user = $this->context->user();
            $tenantId = $this->context->tenantId();
            $tenantDefault = $tenantId ? Tenant::query()->whereKey($tenantId)->value('default_locale') : null;
            app()->setLocale(DocumentLocale::resolve(null, $user?->preferred_locale, $tenantDefault));
        }

        return $next($request);
    }
}
