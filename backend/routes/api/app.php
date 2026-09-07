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
use App\Http\Controllers\Api\Tenant\InspectionController;
use App\Http\Controllers\Api\Tenant\InspectionTemplateController;
use App\Http\Controllers\Api\Tenant\BreakdownController;
use App\Http\Controllers\Api\Tenant\MaintenancePackageController;
use App\Http\Controllers\Api\Tenant\MaintenanceRequestController;
use App\Http\Controllers\Api\Tenant\MaintenanceScheduleController;
use App\Http\Controllers\Api\Tenant\MasterDataMappingController;
use App\Http\Controllers\Api\Tenant\PermissionController;
use App\Http\Controllers\Api\Tenant\QualityControlController;
use App\Http\Controllers\Api\Tenant\RoleController;
use App\Http\Controllers\Api\Tenant\UserController;
use App\Http\Controllers\Api\Tenant\VehicleCategoryController;
use App\Http\Controllers\Api\Tenant\VehicleController;
use App\Http\Controllers\Api\Tenant\VehicleDocumentController;
use App\Http\Controllers\Api\Tenant\VehicleTransferController;
use App\Http\Controllers\Api\Tenant\WarehouseController;
use App\Http\Controllers\Api\Tenant\WorkerController;
use App\Http\Controllers\Api\Tenant\WorkOrderController;
use App\Http\Controllers\Api\Tenant\WorkOrderExecutionController;
use App\Http\Controllers\Api\Tenant\WorkshopController;
use App\Http\Controllers\Api\Tenant\WorkshopSchedulerController;
use App\Http\Controllers\Api\Tenant\WorkspaceController;
use App\Http\Controllers\Api\Tenant\WorkspaceReservationController;
use App\Http\Controllers\Api\Tenant\VehicleReleaseController;
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

        Route::middleware('module:VEHICLE')->group(function () {
            Route::get('/vehicles', [VehicleController::class, 'index'])->middleware('permission:vehicle.view');
            Route::post('/vehicles', [VehicleController::class, 'store'])->middleware('permission:vehicle.create');
            Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->middleware('permission:vehicle.view');
            Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->middleware('permission:vehicle.update');
            Route::post('/vehicles/{vehicle}/status', [VehicleController::class, 'updateStatus'])->middleware('permission:vehicle.status.update');
            Route::post('/vehicles/{vehicle}/assign', [VehicleController::class, 'assign'])->middleware('permission:vehicle.assign');
            Route::get('/vehicles/{vehicle}/assignments', [VehicleController::class, 'assignmentHistory'])->middleware('permission:vehicle.view');
            Route::get('/vehicles/{vehicle}/history', [VehicleController::class, 'history'])->middleware('permission:maintenance_history.view');

            Route::get('/vehicles/{vehicle}/documents', [VehicleDocumentController::class, 'index'])->middleware('permission:vehicle.view');
            Route::post('/vehicles/{vehicle}/documents', [VehicleDocumentController::class, 'store'])->middleware('permission:vehicle.update');
            Route::get('/vehicles/{vehicle}/documents/{document}', [VehicleDocumentController::class, 'download'])->middleware('permission:vehicle.view');
            Route::delete('/vehicles/{vehicle}/documents/{document}', [VehicleDocumentController::class, 'destroy'])->middleware('permission:vehicle.update');

            Route::get('/vehicle-transfers', [VehicleTransferController::class, 'index'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers', [VehicleTransferController::class, 'store'])->middleware('permission:vehicle.transfer');
            Route::get('/vehicle-transfers/{vehicleTransfer}', [VehicleTransferController::class, 'show'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/submit', [VehicleTransferController::class, 'submit'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/approve', [VehicleTransferController::class, 'approve'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/dispatch', [VehicleTransferController::class, 'dispatch'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/receive', [VehicleTransferController::class, 'receive'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/complete', [VehicleTransferController::class, 'complete'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/reject', [VehicleTransferController::class, 'reject'])->middleware('permission:vehicle.transfer');
            Route::post('/vehicle-transfers/{vehicleTransfer}/cancel', [VehicleTransferController::class, 'cancel'])->middleware('permission:vehicle.transfer');
        });

        Route::middleware('module:INSPECTION')->group(function () {
            Route::get('/inspection-templates', [InspectionTemplateController::class, 'index'])->middleware('permission:inspection.view');
            Route::post('/inspection-templates', [InspectionTemplateController::class, 'store'])->middleware('permission:inspection.create');
            Route::get('/inspection-templates/{inspectionTemplate}', [InspectionTemplateController::class, 'show'])->middleware('permission:inspection.view');
            Route::put('/inspection-templates/{inspectionTemplate}', [InspectionTemplateController::class, 'update'])->middleware('permission:inspection.create');
            Route::post('/inspection-templates/{inspectionTemplate}/activate', [InspectionTemplateController::class, 'activate'])->middleware('permission:inspection.create');
            Route::post('/inspection-templates/{inspectionTemplate}/archive', [InspectionTemplateController::class, 'archive'])->middleware('permission:inspection.create');
            Route::post('/inspection-templates/{inspectionTemplate}/items', [InspectionTemplateController::class, 'addItem'])->middleware('permission:inspection.create');
            Route::delete('/inspection-templates/{inspectionTemplate}/items/{item}', [InspectionTemplateController::class, 'removeItem'])->middleware('permission:inspection.create');

            Route::get('/inspections', [InspectionController::class, 'index'])->middleware('permission:inspection.view');
            Route::post('/inspections', [InspectionController::class, 'store'])->middleware('permission:inspection.create');
            Route::get('/inspections/{inspection}', [InspectionController::class, 'show'])->middleware('permission:inspection.view');
            Route::post('/inspections/{inspection}/assign', [InspectionController::class, 'assign'])->middleware('permission:inspection.perform');
            Route::post('/inspections/{inspection}/start', [InspectionController::class, 'start'])->middleware('permission:inspection.perform');
            Route::post('/inspections/{inspection}/submit', [InspectionController::class, 'submit'])->middleware('permission:inspection.submit');
            Route::post('/inspections/{inspection}/maintenance-request', [InspectionController::class, 'createMaintenanceRequest'])->middleware('permission:maintenance_request.create');
        });

        Route::middleware('module:MAINTENANCE')->group(function () {
            Route::get('/maintenance-policies', [MaintenancePackageController::class, 'index'])->middleware('permission:maintenance_policy.view');
            Route::post('/maintenance-policies', [MaintenancePackageController::class, 'store'])->middleware('permission:maintenance_policy.manage');
            Route::get('/maintenance-policies/{maintenancePackage}', [MaintenancePackageController::class, 'show'])->middleware('permission:maintenance_policy.view');
            Route::post('/maintenance-policies/{maintenancePackage}/activate', [MaintenancePackageController::class, 'activate'])->middleware('permission:maintenance_policy.manage');
            Route::post('/maintenance-policies/{maintenancePackage}/items', [MaintenancePackageController::class, 'addItem'])->middleware('permission:maintenance_policy.manage');
            Route::post('/maintenance-policies/{maintenancePackage}/intervals', [MaintenancePackageController::class, 'addInterval'])->middleware('permission:maintenance_policy.manage');
            Route::post('/maintenance-policies/{maintenancePackage}/assign', [MaintenancePackageController::class, 'assignToVehicle'])->middleware('permission:maintenance_policy.manage');

            Route::get('/maintenance-schedules', [MaintenanceScheduleController::class, 'index'])->middleware('permission:maintenance_schedule.view');
            Route::post('/maintenance-schedules/{maintenanceSchedule}/refresh', [MaintenanceScheduleController::class, 'refresh'])->middleware('permission:maintenance_schedule.manage');

            Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index'])->middleware('permission:maintenance_request.view');
            Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'store'])->middleware('permission:maintenance_request.create');
            Route::get('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show'])->middleware('permission:maintenance_request.view');
            Route::post('/maintenance-requests/{maintenanceRequest}/submit', [MaintenanceRequestController::class, 'submit'])->middleware('permission:maintenance_request.create');
            Route::post('/maintenance-requests/{maintenanceRequest}/review', [MaintenanceRequestController::class, 'review'])->middleware('permission:maintenance_request.review');
            Route::post('/maintenance-requests/{maintenanceRequest}/approve', [MaintenanceRequestController::class, 'approve'])->middleware('permission:maintenance_request.approve');
            Route::post('/maintenance-requests/{maintenanceRequest}/reject', [MaintenanceRequestController::class, 'reject'])->middleware('permission:maintenance_request.reject');
            Route::post('/maintenance-requests/{maintenanceRequest}/request-info', [MaintenanceRequestController::class, 'requestInfo'])->middleware('permission:maintenance_request.review');
            Route::post('/maintenance-requests/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel'])->middleware('permission:maintenance_request.create');

            Route::get('/breakdowns', [BreakdownController::class, 'index'])->middleware('permission:breakdown.view');
            Route::post('/breakdowns', [BreakdownController::class, 'store'])->middleware('permission:breakdown.report');
            Route::get('/breakdowns/{breakdown}', [BreakdownController::class, 'show'])->middleware('permission:breakdown.view');
            Route::post('/breakdowns/{breakdown}/verify', [BreakdownController::class, 'verify'])->middleware('permission:breakdown.review');
            Route::post('/breakdowns/{breakdown}/assess', [BreakdownController::class, 'assess'])->middleware('permission:breakdown.review');
            Route::post('/breakdowns/{breakdown}/require-repair', [BreakdownController::class, 'requireRepair'])->middleware('permission:breakdown.review');
            Route::post('/breakdowns/{breakdown}/resolve', [BreakdownController::class, 'resolve'])->middleware('permission:breakdown.resolve');
            Route::post('/breakdowns/{breakdown}/convert-to-request', [BreakdownController::class, 'convertToMaintenanceRequest'])->middleware('permission:breakdown.review');
        });

        Route::middleware('module:WORK_ORDER')->group(function () {
            Route::get('/work-orders', [WorkOrderController::class, 'index'])->middleware('permission:work_order.view');
            Route::post('/work-orders', [WorkOrderController::class, 'store'])->middleware('permission:work_order.create');
            Route::post('/maintenance-requests/{maintenanceRequest}/work-order', [WorkOrderController::class, 'fromMaintenanceRequest'])->middleware('permission:maintenance_request.convert_work_order');
            Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->middleware('permission:work_order.view');
            Route::post('/work-orders/{workOrder}/submit', [WorkOrderController::class, 'submit'])->middleware('permission:work_order.submit');
            Route::post('/work-orders/{workOrder}/approve', [WorkOrderController::class, 'approve'])->middleware('permission:work_order.approve');
            Route::post('/work-orders/{workOrder}/reject', [WorkOrderController::class, 'reject'])->middleware('permission:work_order.approve');
            Route::post('/work-orders/{workOrder}/assign', [WorkOrderController::class, 'assign'])->middleware('permission:work_order.assign');
            Route::post('/work-orders/{workOrder}/schedule', [WorkOrderController::class, 'schedule'])->middleware('permission:work_order.schedule');
            Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->middleware('permission:work_order.start');
            Route::post('/work-orders/{workOrder}/hold', [WorkOrderController::class, 'hold'])->middleware('permission:work_order.pause');
            Route::post('/work-orders/{workOrder}/resume', [WorkOrderController::class, 'resume'])->middleware('permission:work_order.pause');
            Route::post('/work-orders/{workOrder}/wait-for-part', [WorkOrderController::class, 'waitForPart'])->middleware('permission:work_order.pause');
            Route::post('/work-orders/{workOrder}/findings', [WorkOrderExecutionController::class, 'addFinding'])->middleware('permission:diagnosis.manage');
            Route::post('/work-orders/{workOrder}/diagnoses', [WorkOrderExecutionController::class, 'addDiagnosis'])->middleware('permission:diagnosis.manage');
            Route::post('/work-orders/{workOrder}/corrective-actions', [WorkOrderExecutionController::class, 'addCorrectiveAction'])->middleware('permission:diagnosis.manage');
            Route::post('/work-orders/{workOrder}/jobs', [WorkOrderExecutionController::class, 'addJob'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/status', [WorkOrderExecutionController::class, 'updateJobStatus'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/planned-parts', [WorkOrderExecutionController::class, 'addPlannedPart'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/additional-works', [WorkOrderExecutionController::class, 'requestAdditionalWork'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/additional-works/{additionalWork}/decide', [WorkOrderExecutionController::class, 'decideAdditionalWork'])->middleware('permission:work_order.approve');
            Route::post('/work-orders/{workOrder}/mechanics', [WorkOrderExecutionController::class, 'assignMechanic'])->middleware('permission:worker.assign');
            Route::post('/work-orders/{workOrder}/mechanics/{assignment}/unassign', [WorkOrderExecutionController::class, 'unassignMechanic'])->middleware('permission:worker.assign');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/start', [WorkOrderExecutionController::class, 'startLabor'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/{laborLog}/pause', [WorkOrderExecutionController::class, 'pauseLabor'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/{laborLog}/resume', [WorkOrderExecutionController::class, 'resumeLabor'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/{laborLog}/finish', [WorkOrderExecutionController::class, 'finishLabor'])->middleware('permission:maintenance_job.manage');

            Route::get('/qc-inspections', [QualityControlController::class, 'index'])->middleware('permission:qc.view');
            Route::post('/work-orders/{workOrder}/qc/start', [QualityControlController::class, 'start'])->middleware('permission:qc.perform');
            Route::post('/work-orders/{workOrder}/qc/{inspection}/findings', [QualityControlController::class, 'addFinding'])->middleware('permission:qc.perform');
            Route::post('/work-orders/{workOrder}/qc/{inspection}/pass', [QualityControlController::class, 'pass'])->middleware('permission:qc.approve');
            Route::post('/work-orders/{workOrder}/qc/{inspection}/fail', [QualityControlController::class, 'fail'])->middleware('permission:qc.reject');
            Route::post('/work-orders/{workOrder}/qc/{inspection}/complete', [QualityControlController::class, 'complete'])->middleware('permission:qc.approve');
            Route::post('/work-orders/{workOrder}/road-test', [QualityControlController::class, 'recordRoadTest'])->middleware('permission:qc.perform');

            Route::post('/work-orders/{workOrder}/release', [VehicleReleaseController::class, 'store'])->middleware('permission:vehicle_release.perform');
            Route::post('/work-orders/{workOrder}/submit-to-qc', [WorkOrderController::class, 'submitToQc'])->middleware('permission:work_order.complete');
            Route::post('/work-orders/{workOrder}/complete', [WorkOrderController::class, 'complete'])->middleware('permission:work_order.complete');
            Route::post('/work-orders/{workOrder}/close', [WorkOrderController::class, 'close'])->middleware('permission:work_order.close');
            Route::post('/work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->middleware('permission:work_order.cancel');
            Route::get('/work-orders/{workOrder}/downtime', [WorkOrderController::class, 'downtime'])->middleware('permission:work_order.view');
        });

        Route::middleware('module:WORKSHOP')->group(function () {
            Route::get('/workers', [WorkerController::class, 'index'])->middleware('permission:worker.view');
            Route::post('/workers', [WorkerController::class, 'store'])->middleware('permission:worker.manage');
            Route::get('/workers/workload', [WorkerController::class, 'workload'])->middleware('permission:worker.view');
            Route::get('/workers/{worker}', [WorkerController::class, 'show'])->middleware('permission:worker.view');
            Route::put('/workers/{worker}', [WorkerController::class, 'update'])->middleware('permission:worker.manage');
            Route::post('/workers/{worker}/skills', [WorkerController::class, 'addSkill'])->middleware('permission:worker.manage');
            Route::post('/workers/{worker}/assign', [WorkerController::class, 'assign'])->middleware('permission:worker.assign');

            Route::get('/workspaces', [WorkspaceController::class, 'index'])->middleware('permission:workspace.view');
            Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('permission:workspace.manage');
            Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])->middleware('permission:workspace.view');
            Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->middleware('permission:workspace.manage');
            Route::post('/workspaces/{workspace}/block', [WorkspaceController::class, 'block'])->middleware('permission:workspace.block');
            Route::post('/workspaces/{workspace}/unblock', [WorkspaceController::class, 'unblock'])->middleware('permission:workspace.block');
            Route::post('/workspaces/{workspace}/vehicle-categories', [WorkspaceController::class, 'syncVehicleCategories'])->middleware('permission:workspace.manage');

            Route::get('/workspace-reservations', [WorkspaceReservationController::class, 'index'])->middleware('permission:workspace.view');
            Route::post('/workspace-reservations', [WorkspaceReservationController::class, 'store'])->middleware('permission:workspace.reserve');
            Route::post('/workspace-reservations/{reservation}/activate', [WorkspaceReservationController::class, 'activate'])->middleware('permission:workspace.reserve');
            Route::post('/workspace-reservations/{reservation}/complete', [WorkspaceReservationController::class, 'complete'])->middleware('permission:workspace.reserve');
            Route::post('/workspace-reservations/{reservation}/cancel', [WorkspaceReservationController::class, 'cancel'])->middleware('permission:workspace.reserve');

            Route::get('/workshop-scheduler', [WorkshopSchedulerController::class, 'index'])->middleware('permission:workspace.view');
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
