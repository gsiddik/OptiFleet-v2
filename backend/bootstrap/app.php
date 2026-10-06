<?php

use App\Http\Middleware\CheckModuleEntitlement;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsurePlatformScope;
use App\Http\Middleware\EnsureTenantScope;
use App\Http\Middleware\ResolveRequestLocale;
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
            'request.locale' => ResolveRequestLocale::class,
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
            ResolveRequestLocale::class,
            EnsurePlatformScope::class,
            EnsureTenantScope::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            CheckModuleEntitlement::class,
            CheckPermission::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        // Configuration editors send text exactly as typed (a template's "Work Order " before a
        // variable keeps its space; an empty text stays ""). Their values are validated and
        // normalized by the configuration services instead.
        $configurationEditor = fn (Request $request) => $request->is('api/v1/app/configuration/*');
        \Illuminate\Foundation\Http\Middleware\TrimStrings::skipWhen($configurationEditor);
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::skipWhen($configurationEditor);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                // Machine-readable error codes (i18n): additive, only for coded validation errors.
                ...($e instanceof \App\Domain\Shared\Exceptions\CodedValidationException ? ['codes' => $e->codes()] : []),
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

        $exceptions->render(function (\App\Domain\Vehicle\Services\VehicleException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\MasterData\Services\MasterDataException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Inspection\Services\InspectionException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\MaintenancePolicy\Services\MaintenancePolicyException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\MaintenanceRequest\Services\MaintenanceRequestException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Breakdown\Services\BreakdownException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\WorkOrder\Services\WorkOrderException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Workshop\Services\WorkshopOpsException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\QualityControl\Services\QualityControlException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\VehicleRelease\Services\VehicleReleaseException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Inventory\Services\InventoryException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Inventory\Services\StockTransferException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Shared\Services\DocumentStorageException $e, Request $request) {
            return response()->json(['message' => \App\Domain\Shared\Services\DocumentStorageException::USER_MESSAGE], 503);
        });

        $exceptions->render(function (\App\Domain\Procurement\Services\ProcurementException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Tire\Services\TireException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Tire\Services\WheelConfigurationMappingBlockedException $e, Request $request) {
            return response()->json(['message' => $e->getMessage(), 'blockers' => $e->blockers], 422);
        });

        $exceptions->render(function (\App\Domain\ComponentAsset\Services\ComponentAssetException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Warranty\Services\WarrantyException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // Phase 5: Tenant Configuration & Business Rules (Section 51 —
        // configuration errors are user-correctable input problems, never
        // server errors).
        $exceptions->render(function (\App\Domain\Configuration\Services\ConfigurationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Configuration\Services\NumberingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Configuration\Services\TemplateValidationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Configuration\Services\TemplateParseException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Workflow\Services\WorkflowException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Workflow\Services\WorkflowValidationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (\App\Domain\Notification\Services\NotificationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
