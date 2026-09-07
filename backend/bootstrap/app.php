<?php

use App\Http\Middleware\CheckModuleEntitlement;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsurePlatformScope;
use App\Http\Middleware\EnsureTenantScope;
use App\Http\Middleware\RestrictSuspendedTenant;
use App\Http\Middleware\TenantContextMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant.context' => TenantContextMiddleware::class,
            'platform.scope' => EnsurePlatformScope::class,
            'tenant.scope' => EnsureTenantScope::class,
            'permission' => CheckPermission::class,
            'module' => CheckModuleEntitlement::class,
            'subscription.access' => RestrictSuspendedTenant::class,
        ]);

        // TenantContextMiddleware must run before route-model-binding
        // substitution, otherwise a {branch}/{workshop}/... route parameter
        // would resolve without the tenant global scope applied yet.
        // Controllers additionally re-verify tenant ownership explicitly
        // (defense-in-depth), but this ordering keeps the query-scope layer
        // effective too.
        $middleware->priority([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            TenantContextMiddleware::class,
            EnsurePlatformScope::class,
            EnsureTenantScope::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            CheckModuleEntitlement::class,
            CheckPermission::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage() ?: 'Forbidden.'], 403);
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (\App\Domain\Entitlement\Services\CapacityExceededException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Entitlement\Services\EntitlementException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\ProductCatalog\Services\ModuleDependencyException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\ProductCatalog\Services\BundleException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Pricing\Services\PricingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Contract\Services\ContractException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Contract\Services\AmendmentException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Subscription\Services\SubscriptionException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Billing\Services\BillingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Invoice\Services\InvoiceException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Payment\Services\PaymentException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
