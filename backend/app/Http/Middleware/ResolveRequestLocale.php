<?php

namespace App\Http\Middleware;

use App\Domain\DocumentGeneration\Support\DocumentLocale;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\Support\ResponseMessageLocalizer;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request locale (i18n): the language of framework validation messages and of the messages the API
 * returns (`Messages::localized()`). Resolution, first supported value wins:
 *
 *   1. the authenticated user's preferred locale (users.preferred_locale)
 *   2. the tenant default (tenants.default_locale)
 *   3. the request's Accept-Language (the web client sends its active UI locale)
 *   4. English
 *
 * A client that sends no language and has no stored preference gets English, as before. Codes in the
 * response (`codes`, reason codes, status codes) never depend on the locale. The resolution can be
 * switched off with APP_RUNTIME_LOCALE_RESOLUTION=false (every response then stays English).
 */
class ResolveRequestLocale
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.runtime_locale_resolution')) {
            app()->setLocale(config('app.locale'));

            return $next($request);
        }

        $user = $this->context->user() ?? $request->user();
        $tenantId = $this->context->tenantId();
        $tenantDefault = $tenantId ? ($this->context->tenantDefaultLocale() ?? Tenant::query()->whereKey($tenantId)->value('default_locale')) : null;
        app()->setLocale(DocumentLocale::resolve($user?->preferred_locale, $tenantDefault, DocumentLocale::fromLanguages($request->getLanguages())));

        return $this->localizeMessages($next($request));
    }

    /**
     * English domain messages (errors raised anywhere below, already rendered to JSON) in the request
     * locale; see ResponseMessageLocalizer. Only `message` / `errors` change, never codes or data.
     */
    private function localizeMessages(Response $response): Response
    {
        $locale = app()->getLocale();
        if ($locale === 'en' || ! $response instanceof JsonResponse) {
            return $response;
        }
        $payload = $response->getData(true);
        if (! is_array($payload) || (! isset($payload['message']) && ! isset($payload['errors']))) {
            return $response;
        }
        $response->setData(ResponseMessageLocalizer::localizePayload($payload, $locale));

        return $response;
    }
}
