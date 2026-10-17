<?php

use App\Domain\Billing\Services\BillingException;
use App\Domain\Breakdown\Services\BreakdownException;
use App\Domain\ComponentAsset\Services\ComponentAssetException;
use App\Domain\Configuration\Services\ConfigurationException;
use App\Domain\Configuration\Services\NumberingException;
use App\Domain\Configuration\Services\TemplateParseException;
use App\Domain\Configuration\Services\TemplateValidationException;
use App\Domain\Contract\Services\AmendmentException;
use App\Domain\Contract\Services\ContractException;
use App\Domain\Entitlement\Services\CapacityExceededException;
use App\Domain\Entitlement\Services\EntitlementException;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use App\Domain\Inspection\Services\InspectionException;
use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Inventory\Services\StockTransferException;
use App\Domain\Invoice\Services\InvoiceException;
use App\Domain\MaintenancePolicy\Services\MaintenancePolicyException;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestException;
use App\Domain\MasterData\Services\MasterDataException;
use App\Domain\Notification\Services\NotificationException;
use App\Domain\Payment\Services\PaymentException;
use App\Domain\Pricing\Services\PricingException;
use App\Domain\Procurement\Services\ProcurementException;
use App\Domain\ProductCatalog\Services\BundleException;
use App\Domain\ProductCatalog\Services\ModuleDependencyException;
use App\Domain\QualityControl\Services\QualityControlException;
use App\Domain\Shared\Exceptions\CodedValidationException;
use App\Domain\Shared\Services\DocumentStorageException;
use App\Domain\Shared\Support\Messages;
use App\Domain\Subscription\Services\SubscriptionException;
use App\Domain\Tire\Services\TireException;
use App\Domain\Tire\Services\WheelConfigurationMappingBlockedException;
use App\Domain\Vehicle\Services\VehicleException;
use App\Domain\VehicleRelease\Services\VehicleReleaseException;
use App\Domain\Warranty\Services\WarrantyException;
use App\Domain\Workflow\Services\WorkflowException;
use App\Domain\Workflow\Services\WorkflowValidationException;
use App\Domain\WorkOrder\Services\WorkOrderException;
use App\Domain\Workshop\Services\WorkshopOpsException;
use App\Http\Middleware\CheckModuleEntitlement;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsurePlatformScope;
use App\Http\Middleware\EnsureTenantScope;
use App\Http\Middleware\RejectWhenCentrallyGoverned;
use App\Http\Middleware\ResolveRequestLocale;
use App\Http\Middleware\RestrictSuspendedTenant;
use App\Http\Middleware\TenantContextMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'not.central' => RejectWhenCentrallyGoverned::class,
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
            EnsureFrontendRequestsAreStateful::class,
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            TenantContextMiddleware::class,
            ResolveRequestLocale::class,
            EnsurePlatformScope::class,
            EnsureTenantScope::class,
            SubstituteBindings::class,
            CheckModuleEntitlement::class,
            CheckPermission::class,
            Authorize::class,
        ]);

        // Configuration editors send text exactly as typed (a template's "Work Order " before a
        // variable keeps its space; an empty text stays ""). Their values are validated and
        // normalized by the configuration services instead.
        $configurationEditor = fn (Request $request) => $request->is('api/v1/app/configuration/*');
        TrimStrings::skipWhen($configurationEditor);
        ConvertEmptyStringsToNull::skipWhen($configurationEditor);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
                // Machine-readable error codes (i18n): additive, only for coded validation errors.
                ...($e instanceof CodedValidationException ? ['codes' => $e->codes()] : []),
            ], 422);
        });

        // Central governance (audit A02/R05): OptiNexus could not answer, so access is denied rather than guessed. The code lets
        // the UI show "authorization service unavailable" instead of a permission error; Retry-After is advice, not a grant.
        $exceptions->dontReport(GovernanceUnavailableException::class);

        $exceptions->render(function (GovernanceUnavailableException|GovernanceContractException $e, Request $request) {
            return response()->json([
                'message' => Messages::localized('errors.http.governanceUnavailable'),
                'code' => GovernanceUnavailableException::CODE,
            ], 503, ['Retry-After' => (string) (($e instanceof GovernanceUnavailableException ? $e->retryAfterSeconds : null) ?? 30)]);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage() ?: 'Forbidden.'], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (CapacityExceededException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (EntitlementException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (ModuleDependencyException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (BundleException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (PricingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (ContractException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (AmendmentException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (SubscriptionException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (BillingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (InvoiceException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (PaymentException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (VehicleException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (MasterDataException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (InspectionException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (MaintenancePolicyException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (MaintenanceRequestException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (BreakdownException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (WorkOrderException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (WorkshopOpsException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (QualityControlException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (VehicleReleaseException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (InventoryException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (StockTransferException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (DocumentStorageException $e, Request $request) {
            return response()->json(['message' => DocumentStorageException::USER_MESSAGE], 503);
        });

        $exceptions->render(function (ProcurementException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (TireException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (WheelConfigurationMappingBlockedException $e, Request $request) {
            return response()->json(['message' => $e->getMessage(), 'blockers' => $e->blockers], 422);
        });

        $exceptions->render(function (ComponentAssetException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (WarrantyException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        // Phase 5: Tenant Configuration & Business Rules (Section 51 —
        // configuration errors are user-correctable input problems, never
        // server errors).
        $exceptions->render(function (ConfigurationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (NumberingException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (TemplateValidationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (TemplateParseException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (WorkflowException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (WorkflowValidationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });

        $exceptions->render(function (NotificationException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
