<?php

use App\Http\Controllers\Api\Platform\AuditLogController;
use App\Http\Controllers\Api\Platform\BillingController;
use App\Http\Controllers\Api\Platform\BundleController;
use App\Http\Controllers\Api\Platform\ContractAmendmentController;
use App\Http\Controllers\Api\Platform\ContractController;
use App\Http\Controllers\Api\Platform\ContractRenewalController;
use App\Http\Controllers\Api\Platform\DashboardController;
use App\Http\Controllers\Api\Platform\InvoiceController;
use App\Http\Controllers\Api\Platform\ModuleController;
use App\Http\Controllers\Api\Platform\ModuleDependencyController;
use App\Http\Controllers\Api\Platform\PaymentController;
use App\Http\Controllers\Api\Platform\PermissionController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\Platform\PricingController;
use App\Http\Controllers\Api\Platform\RoleController;
use App\Http\Controllers\Api\Platform\SubscriptionController;
use App\Http\Controllers\Api\Platform\TenantCapacityController;
use App\Http\Controllers\Api\Platform\TenantController;
use App\Http\Controllers\Api\Platform\TenantCustomPricingController;
use App\Http\Controllers\Api\Platform\TenantEntitlementController;
use App\Http\Controllers\Api\Platform\TenantUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform')->middleware('platform.scope')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/tenants', [TenantController::class, 'index'])->middleware('permission:tenant.view');
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('permission:tenant.create');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->middleware('permission:tenant.view');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->middleware('permission:tenant.update');
    Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])->middleware('permission:tenant.activate');
    Route::post('/tenants/{tenant}/deactivate', [TenantController::class, 'deactivate'])->middleware('permission:tenant.deactivate');

    Route::get('/tenants/{tenant}/users', [TenantUserController::class, 'index'])->middleware('permission:user.view');
    Route::post('/tenants/{tenant}/users', [TenantUserController::class, 'store'])->middleware('permission:user.create');
    Route::patch('/tenants/{tenant}/users/{tenantUser}', [TenantUserController::class, 'update'])->middleware('permission:user.update');
    Route::post('/tenants/{tenant}/users/{tenantUser}/roles', [TenantUserController::class, 'assignRole'])->middleware('permission:user.assign');
    Route::delete('/tenants/{tenant}/users/{tenantUser}/roles/{roleId}', [TenantUserController::class, 'revokeRole'])->middleware('permission:user.assign');

    Route::get('/tenants/{tenant}/entitlements', [TenantEntitlementController::class, 'index'])->middleware('permission:entitlement.view');
    Route::post('/tenants/{tenant}/entitlements', [TenantEntitlementController::class, 'update'])->middleware('permission:entitlement.manage');

    Route::get('/tenants/{tenant}/capacity-limits', [TenantCapacityController::class, 'index'])->middleware('permission:entitlement.view');
    Route::put('/tenants/{tenant}/capacity-limits', [TenantCapacityController::class, 'update'])->middleware('permission:entitlement.manage');

    Route::get('/modules', [ModuleController::class, 'index'])->middleware('permission:module.view');
    Route::post('/modules', [ModuleController::class, 'store'])->middleware('permission:module.manage');
    Route::get('/modules/{module}', [ModuleController::class, 'show'])->middleware('permission:module.view');
    Route::put('/modules/{module}', [ModuleController::class, 'update'])->middleware('permission:module.manage');
    Route::get('/modules/{module}/dependencies', [ModuleDependencyController::class, 'index'])->middleware('permission:module.view');
    Route::post('/modules/{module}/dependencies', [ModuleDependencyController::class, 'store'])->middleware('permission:module.manage');
    Route::delete('/modules/{module}/dependencies/{dependsOn}', [ModuleDependencyController::class, 'destroy'])->middleware('permission:module.manage');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:role.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:role.create');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.update');
    Route::post('/roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:role.assign_permission');

    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:role.view');

    Route::get('/users', [PlatformUserController::class, 'index'])->middleware('permission:user.view');
    Route::post('/users', [PlatformUserController::class, 'store'])->middleware('permission:user.create');
    Route::patch('/users/{user}', [PlatformUserController::class, 'update'])->middleware('permission:user.update');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

    // --- Phase 2: Commercial SaaS ---

    Route::get('/bundles', [BundleController::class, 'index'])->middleware('permission:bundle.view');
    Route::post('/bundles', [BundleController::class, 'store'])->middleware('permission:bundle.create');
    Route::get('/bundles/{bundle}', [BundleController::class, 'show'])->middleware('permission:bundle.view');
    Route::put('/bundles/{bundle}', [BundleController::class, 'update'])->middleware('permission:bundle.update');
    Route::put('/bundles/{bundle}/modules', [BundleController::class, 'syncModules'])->middleware('permission:bundle.update');
    Route::get('/bundles/{bundle}/missing-dependencies', [BundleController::class, 'missingDependencies'])->middleware('permission:bundle.view');
    Route::post('/bundles/{bundle}/publish', [BundleController::class, 'publish'])->middleware('permission:bundle.publish');

    Route::get('/pricing', [PricingController::class, 'index'])->middleware('permission:pricing.view');
    Route::post('/pricing', [PricingController::class, 'store'])->middleware('permission:pricing.create');
    Route::get('/pricing/{pricing}', [PricingController::class, 'show'])->middleware('permission:pricing.view');
    Route::post('/pricing/{pricing}/versions', [PricingController::class, 'publishVersion'])->middleware('permission:pricing.publish');

    Route::get('/tenants/{tenant}/custom-pricing', [TenantCustomPricingController::class, 'index'])->middleware('permission:pricing.view');
    Route::post('/tenants/{tenant}/custom-pricing', [TenantCustomPricingController::class, 'store'])->middleware('permission:pricing.update');
    Route::delete('/tenants/{tenant}/custom-pricing/{tenantCustomPricing}', [TenantCustomPricingController::class, 'destroy'])->middleware('permission:pricing.update');

    Route::get('/contracts', [ContractController::class, 'index'])->middleware('permission:contract.view');
    Route::post('/contracts', [ContractController::class, 'store'])->middleware('permission:contract.create');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->middleware('permission:contract.view');
    Route::post('/contracts/{contract}/submit', [ContractController::class, 'submitForApproval'])->middleware('permission:contract.submit');
    Route::post('/contracts/{contract}/approve', [ContractController::class, 'approve'])->middleware('permission:contract.approve');
    Route::post('/contracts/{contract}/reject', [ContractController::class, 'reject'])->middleware('permission:contract.approve');
    Route::post('/contracts/{contract}/terminate', [ContractController::class, 'terminate'])->middleware('permission:contract.terminate');
    Route::post('/contracts/{contract}/renew', [ContractRenewalController::class, 'store'])->middleware('permission:contract.renew');

    Route::get('/contracts/{contract}/amendments', [ContractAmendmentController::class, 'index'])->middleware('permission:contract.view');
    Route::post('/contracts/{contract}/amendments', [ContractAmendmentController::class, 'store'])->middleware('permission:contract.amend');
    Route::post('/contracts/{contract}/amendments/{amendment}/items', [ContractAmendmentController::class, 'addItem'])->middleware('permission:contract.amend');
    Route::post('/contracts/{contract}/amendments/{amendment}/remove-item', [ContractAmendmentController::class, 'removeItem'])->middleware('permission:contract.amend');
    Route::post('/contracts/{contract}/amendments/{amendment}/submit', [ContractAmendmentController::class, 'submitForApproval'])->middleware('permission:contract.amend');
    Route::post('/contracts/{contract}/amendments/{amendment}/approve', [ContractAmendmentController::class, 'approve'])->middleware('permission:contract.approve');
    Route::post('/contracts/{contract}/amendments/{amendment}/reject', [ContractAmendmentController::class, 'reject'])->middleware('permission:contract.approve');

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])->middleware('permission:subscription.view');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->middleware('permission:subscription.view');
    Route::post('/subscriptions/{subscription}/suspend', [SubscriptionController::class, 'suspend'])->middleware('permission:subscription.suspend');
    Route::post('/subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])->middleware('permission:subscription.reactivate');

    Route::get('/billings', [BillingController::class, 'index'])->middleware('permission:billing.view');
    Route::get('/billings/{billing}', [BillingController::class, 'show'])->middleware('permission:billing.view');
    Route::post('/subscriptions/{subscription}/generate-billing', [BillingController::class, 'generate'])->middleware('permission:billing.generate');

    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:invoice.view');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoice.view');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:invoice.void');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->middleware('permission:invoice.view');

    Route::get('/payments', [PaymentController::class, 'index'])->middleware('permission:payment.view');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('permission:payment.view');
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify'])->middleware('permission:payment.verify');
    Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->middleware('permission:payment.reject');
    Route::get('/payments/{payment}/proofs/{proof}', [PaymentController::class, 'downloadProof'])->middleware('permission:payment.view');
});
