<?php

use App\Http\Controllers\Api\Tenant\Account\AccountContractController;
use App\Http\Controllers\Api\Tenant\Account\AccountInvoiceController;
use App\Http\Controllers\Api\Tenant\Account\AccountPaymentController;
use App\Http\Controllers\Api\Tenant\Account\AccountSubscriptionController;
use App\Http\Controllers\Api\Tenant\AuditLogController;
use App\Http\Controllers\Api\Tenant\BranchController;
use App\Http\Controllers\Api\Tenant\ComponentGroupController;
use App\Http\Controllers\Api\Tenant\DashboardController;
use App\Http\Controllers\Api\Tenant\DataScopeController;
use App\Http\Controllers\Api\Tenant\MasterDataMappingController;
use App\Http\Controllers\Api\Tenant\PermissionController;
use App\Http\Controllers\Api\Tenant\RoleController;
use App\Http\Controllers\Api\Tenant\UserController;
use App\Http\Controllers\Api\Tenant\VehicleCategoryController;
use App\Http\Controllers\Api\Tenant\WarehouseController;
use App\Http\Controllers\Api\Tenant\WorkshopController;
use Illuminate\Support\Facades\Route;

Route::prefix('app')->middleware('tenant.scope')->group(function () {
    // Section 39: billing-only Account routes are deliberately NOT wrapped
    // with 'subscription.access', so a SUSPENDED tenant keeps this access
    // while every operational route below (wrapped in the group further
    // down) is blocked.
    Route::prefix('account')->group(function () {
        Route::get('/subscription', [AccountSubscriptionController::class, 'show'])->middleware('permission:account.subscription.view');
        Route::get('/active-modules', [AccountSubscriptionController::class, 'activeModules'])->middleware('permission:account.subscription.view');
        Route::get('/usage-limits', [AccountSubscriptionController::class, 'usageAndLimits'])->middleware('permission:account.subscription.view');

        Route::get('/contract', [AccountContractController::class, 'show'])->middleware('permission:account.contract.view');
        Route::get('/contracts', [AccountContractController::class, 'index'])->middleware('permission:account.contract.view');

        Route::get('/invoices', [AccountInvoiceController::class, 'index'])->middleware('permission:account.invoice.view');
        Route::get('/invoices/{invoice}', [AccountInvoiceController::class, 'show'])->middleware('permission:account.invoice.view');
        Route::get('/invoices/{invoice}/pdf', [AccountInvoiceController::class, 'downloadPdf'])->middleware('permission:account.invoice.download');

        Route::get('/payments', [AccountPaymentController::class, 'index'])->middleware('permission:account.payment.view');
        Route::get('/payments/{payment}', [AccountPaymentController::class, 'show'])->middleware('permission:account.payment.view');
        Route::post('/payments', [AccountPaymentController::class, 'store'])->middleware('permission:account.payment.submit');
        Route::post('/payments/{payment}/proof', [AccountPaymentController::class, 'uploadProof'])->middleware('permission:account.payment.submit');
        Route::post('/payments/{payment}/resubmit', [AccountPaymentController::class, 'resubmit'])->middleware('permission:account.payment.submit');
        Route::get('/payments/{payment}/proofs/{proof}', [AccountPaymentController::class, 'downloadProof'])->middleware('permission:account.payment.view');
    });

    Route::middleware('subscription.access')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::middleware('module:ORGANIZATION')->group(function () {
            Route::get('/branches', [BranchController::class, 'index'])->middleware('permission:branch.view');
            Route::post('/branches', [BranchController::class, 'store'])->middleware('permission:branch.create');
            Route::get('/branches/{branch}', [BranchController::class, 'show'])->middleware('permission:branch.view');
            Route::put('/branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branch.update');
            Route::post('/branches/{branch}/activate', [BranchController::class, 'activate'])->middleware('permission:branch.activate');
            Route::post('/branches/{branch}/deactivate', [BranchController::class, 'deactivate'])->middleware('permission:branch.deactivate');

            Route::get('/workshops', [WorkshopController::class, 'index'])->middleware('permission:workshop.view');
            Route::post('/workshops', [WorkshopController::class, 'store'])->middleware('permission:workshop.create');
            Route::get('/workshops/{workshop}', [WorkshopController::class, 'show'])->middleware('permission:workshop.view');
            Route::put('/workshops/{workshop}', [WorkshopController::class, 'update'])->middleware('permission:workshop.update');
            Route::post('/workshops/{workshop}/activate', [WorkshopController::class, 'activate'])->middleware('permission:workshop.activate');
            Route::post('/workshops/{workshop}/deactivate', [WorkshopController::class, 'deactivate'])->middleware('permission:workshop.deactivate');

            Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouse.view');
            Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouse.create');
            Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show'])->middleware('permission:warehouse.view');
            Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.update');
            Route::post('/warehouses/{warehouse}/activate', [WarehouseController::class, 'activate'])->middleware('permission:warehouse.activate');
            Route::post('/warehouses/{warehouse}/deactivate', [WarehouseController::class, 'deactivate'])->middleware('permission:warehouse.deactivate');
        });

        Route::middleware('module:CORE')->group(function () {
            Route::get('/vehicle-categories', [VehicleCategoryController::class, 'index'])->middleware('permission:vehicle_category.view');
            Route::post('/vehicle-categories', [VehicleCategoryController::class, 'store'])->middleware('permission:vehicle_category.create');
            Route::get('/vehicle-categories/{vehicleCategory}', [VehicleCategoryController::class, 'show'])->middleware('permission:vehicle_category.view');
            Route::put('/vehicle-categories/{vehicleCategory}', [VehicleCategoryController::class, 'update'])->middleware('permission:vehicle_category.update');
            Route::delete('/vehicle-categories/{vehicleCategory}', [VehicleCategoryController::class, 'destroy'])->middleware('permission:vehicle_category.update');
            Route::post('/vehicle-categories/{vehicleCategory}/component-groups', [MasterDataMappingController::class, 'syncComponentGroups'])->middleware('permission:component_group.map');

            Route::get('/component-groups', [ComponentGroupController::class, 'index'])->middleware('permission:component_group.view');
            Route::post('/component-groups', [ComponentGroupController::class, 'store'])->middleware('permission:component_group.create');
            Route::get('/component-groups/{componentGroup}', [ComponentGroupController::class, 'show'])->middleware('permission:component_group.view');
            Route::put('/component-groups/{componentGroup}', [ComponentGroupController::class, 'update'])->middleware('permission:component_group.update');
            Route::delete('/component-groups/{componentGroup}', [ComponentGroupController::class, 'destroy'])->middleware('permission:component_group.update');
            Route::post('/component-groups/{componentGroup}/vehicle-categories', [MasterDataMappingController::class, 'syncVehicleCategories'])->middleware('permission:component_group.map');
        });

        Route::middleware('module:ACCESS_MANAGEMENT')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view');
            Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create');
            Route::patch('/users/{tenantUser}', [UserController::class, 'update'])->middleware('permission:user.update');
            Route::post('/users/{tenantUser}/roles', [UserController::class, 'assignRole'])->middleware('permission:user.assign');
            Route::delete('/users/{tenantUser}/roles/{roleId}', [UserController::class, 'revokeRole'])->middleware('permission:user.assign');

            Route::get('/users/{tenantUser}/data-scopes', [DataScopeController::class, 'index'])->middleware('permission:user.view');
            Route::post('/users/{tenantUser}/data-scopes', [DataScopeController::class, 'store'])->middleware('permission:user.assign');
            Route::delete('/users/{tenantUser}/data-scopes/{dataScopeAssignment}', [DataScopeController::class, 'destroy'])->middleware('permission:user.assign');

            Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:role.view');
            Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:role.create');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:role.update');
            Route::post('/roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:role.assign_permission');

            Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:role.view');
        });

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    });
});
