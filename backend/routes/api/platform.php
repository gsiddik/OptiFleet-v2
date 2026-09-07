<?php

use App\Http\Controllers\Api\Platform\AuditLogController;
use App\Http\Controllers\Api\Platform\DashboardController;
use App\Http\Controllers\Api\Platform\ModuleController;
use App\Http\Controllers\Api\Platform\ModuleDependencyController;
use App\Http\Controllers\Api\Platform\PermissionController;
use App\Http\Controllers\Api\Platform\PlatformUserController;
use App\Http\Controllers\Api\Platform\RoleController;
use App\Http\Controllers\Api\Platform\TenantCapacityController;
use App\Http\Controllers\Api\Platform\TenantController;
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
});
