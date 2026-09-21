<?php

use App\Http\Controllers\Api\Tenant\Account\AccountContractController;
use App\Http\Controllers\Api\Tenant\Account\AccountInvoiceController;
use App\Http\Controllers\Api\Tenant\Account\AccountPaymentController;
use App\Http\Controllers\Api\Tenant\Account\AccountSubscriptionController;
use App\Http\Controllers\Api\Tenant\Analytics\BreakdownAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\ComponentAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\CostAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\DowntimeAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\ExportAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\FleetAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\InventoryAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\MaintenanceAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\MechanicAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\OverviewAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\ProcurementAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\TireAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\VendorAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\WarrantyAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\WorkOrderAnalyticsController;
use App\Http\Controllers\Api\Tenant\Analytics\WorkshopAnalyticsController;
use App\Http\Controllers\Api\Tenant\AuditLogController;
use App\Http\Controllers\Api\Tenant\BranchController;
use App\Http\Controllers\Api\Tenant\BreakdownController;
use App\Http\Controllers\Api\Tenant\CompanyProfileController;
use App\Http\Controllers\Api\Tenant\ComponentAssetController;
use App\Http\Controllers\Api\Tenant\ComponentGroupController;
use App\Http\Controllers\Api\Tenant\ConfigurationController;
use App\Http\Controllers\Api\Tenant\DashboardController;
use App\Http\Controllers\Api\Tenant\DataScopeController;
use App\Http\Controllers\Api\Tenant\GoodsReceiptController;
use App\Http\Controllers\Api\Tenant\InspectionController;
use App\Http\Controllers\Api\Tenant\InspectionTemplateController;
use App\Http\Controllers\Api\Tenant\Intelligence\ComponentIntelligenceController;
use App\Http\Controllers\Api\Tenant\Intelligence\IntelligenceOverviewController;
use App\Http\Controllers\Api\Tenant\Intelligence\InventoryIntelligenceController;
use App\Http\Controllers\Api\Tenant\Intelligence\PredictionHistoryController;
use App\Http\Controllers\Api\Tenant\Intelligence\RecommendationController;
use App\Http\Controllers\Api\Tenant\Intelligence\TireIntelligenceApiController;
use App\Http\Controllers\Api\Tenant\Intelligence\VehicleIntelligenceController;
use App\Http\Controllers\Api\Tenant\MaintenancePackageController;
use App\Http\Controllers\Api\Tenant\MaintenanceRequestAssessmentController;
use App\Http\Controllers\Api\Tenant\MaintenanceRequestController;
use App\Http\Controllers\Api\Tenant\MaintenanceScheduleController;
use App\Http\Controllers\Api\Tenant\MasterDataMappingController;
use App\Http\Controllers\Api\Tenant\NotificationRuleController;
use App\Http\Controllers\Api\Tenant\PartnerController;
use App\Http\Controllers\Api\Tenant\PartRequestController;
use App\Http\Controllers\Api\Tenant\PermissionController;
use App\Http\Controllers\Api\Tenant\ProductCategoryController;
use App\Http\Controllers\Api\Tenant\ProductController;
use App\Http\Controllers\Api\Tenant\PurchaseOrderController;
use App\Http\Controllers\Api\Tenant\PurchaseRequestController;
use App\Http\Controllers\Api\Tenant\QualityControlController;
use App\Http\Controllers\Api\Tenant\RfqController;
use App\Http\Controllers\Api\Tenant\RimController;
use App\Http\Controllers\Api\Tenant\RoleController;
use App\Http\Controllers\Api\Tenant\SparePartSaleController;
use App\Http\Controllers\Api\Tenant\StockMovementController;
use App\Http\Controllers\Api\Tenant\StockOpnameController;
use App\Http\Controllers\Api\Tenant\StockReservationController;
use App\Http\Controllers\Api\Tenant\StockTransferController;
use App\Http\Controllers\Api\Tenant\TireController;
use App\Http\Controllers\Api\Tenant\UomController;
use App\Http\Controllers\Api\Tenant\UsedPartDispositionController;
use App\Http\Controllers\Api\Tenant\UserController;
use App\Http\Controllers\Api\Tenant\VehicleBrandController;
use App\Http\Controllers\Api\Tenant\VehicleCategoryController;
use App\Http\Controllers\Api\Tenant\VehicleController;
use App\Http\Controllers\Api\Tenant\VehicleDocumentController;
use App\Http\Controllers\Api\Tenant\VehiclePhotoController;
use App\Http\Controllers\Api\Tenant\VehicleModelController;
use App\Http\Controllers\Api\Tenant\VehicleReleaseController;
use App\Http\Controllers\Api\Tenant\VehicleTransferController;
use App\Http\Controllers\Api\Tenant\VendorInvoiceReferenceController;
use App\Http\Controllers\Api\Tenant\VendorQuotationController;
use App\Http\Controllers\Api\Tenant\WarehouseController;
use App\Http\Controllers\Api\Tenant\WarehouseZoneController;
use App\Http\Controllers\Api\Tenant\WarehouseRackController;
use App\Http\Controllers\Api\Tenant\WarehouseBinController;
use App\Http\Controllers\Api\Tenant\WarehouseStockController;
use App\Http\Controllers\Api\Tenant\WarrantyClaimController;
use App\Http\Controllers\Api\Tenant\WarrantyController;
use App\Http\Controllers\Api\Tenant\WheelConfigurationController;
use App\Http\Controllers\Api\Tenant\WorkerController;
use App\Http\Controllers\Api\Tenant\WorkerTypeController;
use App\Http\Controllers\Api\Tenant\ToolTypeController;
use App\Http\Controllers\Api\Tenant\EquipmentTypeController;
use App\Http\Controllers\Api\Tenant\StorageRequirementController;
use App\Http\Controllers\Api\Tenant\TireLoadIndexController;
use App\Http\Controllers\Api\Tenant\TireSpeedRatingController;
use App\Http\Controllers\Api\Tenant\TirePlyRatingController;
use App\Http\Controllers\Api\Tenant\TireTraCodeController;
use App\Http\Controllers\Api\Tenant\ExternalWorkOrderController;
use App\Http\Controllers\Api\Tenant\ExternalWorkOrderInvoiceController;
use App\Http\Controllers\Api\Tenant\WorkOrderController;
use App\Http\Controllers\Api\Tenant\WorkOrderExecutionController;
use App\Http\Controllers\Api\Tenant\WorkOrderExternalServiceController;
use App\Http\Controllers\Api\Tenant\WorkshopController;
use App\Http\Controllers\Api\Tenant\WorkshopInvoiceController;
use App\Http\Controllers\Api\Tenant\WorkshopSchedulerController;
use App\Http\Controllers\Api\Tenant\WorkspaceController;
use App\Http\Controllers\Api\Tenant\WorkspaceReservationController;
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

        Route::get('/company', [CompanyProfileController::class, 'show'])->middleware('permission:company.view');
        Route::put('/company', [CompanyProfileController::class, 'update'])->middleware('permission:company.update');

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

            Route::get('/warehouse-zones', [WarehouseZoneController::class, 'index'])->middleware('permission:warehouse.view');
            Route::post('/warehouse-zones', [WarehouseZoneController::class, 'store'])->middleware('permission:warehouse.update');
            Route::put('/warehouse-zones/{warehouseZone}', [WarehouseZoneController::class, 'update'])->middleware('permission:warehouse.update');
            Route::delete('/warehouse-zones/{warehouseZone}', [WarehouseZoneController::class, 'destroy'])->middleware('permission:warehouse.update');

            Route::get('/warehouse-racks', [WarehouseRackController::class, 'index'])->middleware('permission:warehouse.view');
            Route::post('/warehouse-racks', [WarehouseRackController::class, 'store'])->middleware('permission:warehouse.update');
            Route::put('/warehouse-racks/{warehouseRack}', [WarehouseRackController::class, 'update'])->middleware('permission:warehouse.update');
            Route::delete('/warehouse-racks/{warehouseRack}', [WarehouseRackController::class, 'destroy'])->middleware('permission:warehouse.update');

            Route::get('/warehouse-bins', [WarehouseBinController::class, 'index'])->middleware('permission:warehouse.view');
            Route::post('/warehouse-bins', [WarehouseBinController::class, 'store'])->middleware('permission:warehouse.update');
            Route::put('/warehouse-bins/{warehouseBin}', [WarehouseBinController::class, 'update'])->middleware('permission:warehouse.update');
            Route::delete('/warehouse-bins/{warehouseBin}', [WarehouseBinController::class, 'destroy'])->middleware('permission:warehouse.update');
        });

        Route::middleware('module:CORE')->group(function () {
            Route::get('/vehicle-categories', [VehicleCategoryController::class, 'index'])->middleware('permission:vehicle_category.view');
            Route::post('/vehicle-categories', [VehicleCategoryController::class, 'store'])->middleware('permission:vehicle_category.create');
            Route::get('/vehicle-categories/{vehicleCategory}', [VehicleCategoryController::class, 'show'])->middleware('permission:vehicle_category.view');
            Route::put('/vehicle-categories/{vehicleCategory}', [VehicleCategoryController::class, 'update'])->middleware('permission:vehicle_category.update');
            Route::delete('/vehicle-categories/{vehicleCategory}', [VehicleCategoryController::class, 'destroy'])->middleware('permission:vehicle_category.update');
            Route::post('/vehicle-categories/{vehicleCategory}/component-groups', [MasterDataMappingController::class, 'syncComponentGroups'])->middleware('permission:component_group.map');
            Route::get('/vehicle-brands', [VehicleBrandController::class, 'index'])->middleware('permission:vehicle_brand.view');
            Route::post('/vehicle-brands', [VehicleBrandController::class, 'store'])->middleware('permission:vehicle_brand.create');
            Route::put('/vehicle-brands/{vehicleBrand}', [VehicleBrandController::class, 'update'])->middleware('permission:vehicle_brand.update');
            Route::delete('/vehicle-brands/{vehicleBrand}', [VehicleBrandController::class, 'destroy'])->middleware('permission:vehicle_brand.update');
            Route::post('/vehicle-brands/{vehicleBrand}/logo', [VehicleBrandController::class, 'uploadLogo'])->middleware('permission:vehicle_brand.update');
            Route::get('/vehicle-brands/{vehicleBrand}/logo', [VehicleBrandController::class, 'showLogo'])->middleware('permission:vehicle_brand.view');
            Route::get('/vehicle-models', [VehicleModelController::class, 'index'])->middleware('permission:vehicle_brand.view');
            Route::post('/vehicle-models', [VehicleModelController::class, 'store'])->middleware('permission:vehicle_brand.create');
            Route::put('/vehicle-models/{vehicleModel}', [VehicleModelController::class, 'update'])->middleware('permission:vehicle_brand.update');
            Route::delete('/vehicle-models/{vehicleModel}', [VehicleModelController::class, 'destroy'])->middleware('permission:vehicle_brand.update');

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

            Route::post('/vehicles/{vehicle}/photo', [VehiclePhotoController::class, 'store'])->middleware('permission:vehicle.update');
            Route::get('/vehicles/{vehicle}/photo', [VehiclePhotoController::class, 'show'])->middleware('permission:vehicle.view');

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
            Route::get('/inspections/{inspection}/logs', [InspectionController::class, 'logs'])->middleware('permission:inspection.view');
        });

        Route::middleware('module:MAINTENANCE')->group(function () {
            Route::get('/maintenance-policies', [MaintenancePackageController::class, 'index'])->middleware('permission:maintenance_policy.view');
            Route::post('/maintenance-policies', [MaintenancePackageController::class, 'store'])->middleware('permission:maintenance_policy.manage');
            Route::get('/maintenance-policies/{maintenancePackage}', [MaintenancePackageController::class, 'show'])->middleware('permission:maintenance_policy.view');
            Route::post('/maintenance-policies/{maintenancePackage}/activate', [MaintenancePackageController::class, 'activate'])->middleware('permission:maintenance_policy.manage');
            Route::post('/maintenance-policies/{maintenancePackage}/items', [MaintenancePackageController::class, 'addItem'])->middleware('permission:maintenance_policy.manage');
            Route::put('/maintenance-policies/{maintenancePackage}/items', [MaintenancePackageController::class, 'updateItems'])->middleware('permission:maintenance_policy.manage');
            Route::post('/maintenance-policies/{maintenancePackage}/intervals', [MaintenancePackageController::class, 'addInterval'])->middleware('permission:maintenance_policy.manage');
            Route::post('/maintenance-policies/{maintenancePackage}/assign', [MaintenancePackageController::class, 'assignToVehicle'])->middleware('permission:maintenance_policy.manage');

            Route::get('/maintenance-schedules', [MaintenanceScheduleController::class, 'index'])->middleware('permission:maintenance_schedule.view');
            Route::post('/maintenance-schedules', [MaintenanceScheduleController::class, 'store'])->middleware('permission:maintenance_schedule.create');
            Route::post('/maintenance-schedules/{maintenanceSchedule}/refresh', [MaintenanceScheduleController::class, 'refresh'])->middleware('permission:maintenance_schedule.manage');
            Route::post('/maintenance-schedules/{maintenanceSchedule}/maintenance-request', [MaintenanceScheduleController::class, 'convertToMaintenanceRequest'])->middleware('permission:maintenance_schedule.convert_maintenance_request');

            Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index'])->middleware('permission:maintenance_request.view');
            Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'store'])->middleware('permission:maintenance_request.create');
            Route::get('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show'])->middleware('permission:maintenance_request.view');
            Route::post('/maintenance-requests/{maintenanceRequest}/submit', [MaintenanceRequestController::class, 'submit'])->middleware('permission:maintenance_request.create');
            Route::post('/maintenance-requests/{maintenanceRequest}/review', [MaintenanceRequestController::class, 'review'])->middleware('permission:maintenance_request.review');
            Route::post('/maintenance-requests/{maintenanceRequest}/approve', [MaintenanceRequestController::class, 'approve'])->middleware('permission:maintenance_request.approve');
            Route::post('/maintenance-requests/{maintenanceRequest}/reject', [MaintenanceRequestController::class, 'reject'])->middleware('permission:maintenance_request.reject');
            // Request Info / NEED_INFORMATION retired: reviewers only Approve or Reject (see WorkflowDefaultsSeeder).
            Route::post('/maintenance-requests/{maintenanceRequest}/cancel', [MaintenanceRequestController::class, 'cancel'])->middleware('permission:maintenance_request.create');

            Route::get('/maintenance-requests/{maintenanceRequest}/assessment', [MaintenanceRequestAssessmentController::class, 'show'])->middleware('permission:maintenance_request.view');
            Route::post('/maintenance-requests/{maintenanceRequest}/assessment', [MaintenanceRequestAssessmentController::class, 'store'])->middleware('permission:maintenance_request.create');
            Route::delete('/maintenance-requests/{maintenanceRequest}/assessment', [MaintenanceRequestAssessmentController::class, 'destroy'])->middleware('permission:maintenance_request.create');

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
            Route::post('/maintenance-schedules/{maintenanceSchedule}/work-order', [WorkOrderController::class, 'fromMaintenanceSchedule'])->middleware('permission:maintenance_schedule.convert_work_order');
            Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->middleware('permission:work_order.view');
            Route::get('/work-orders/{workOrder}/print', [WorkOrderController::class, 'print'])->middleware('permission:work_order.view');
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
            Route::post('/work-orders/{workOrder}/findings/{finding}/resolve', [WorkOrderExecutionController::class, 'resolveFinding'])->middleware('permission:diagnosis.manage');

            // Consolidated External Workshop business rules — deliberately separate from the
            // internal-workshop execution routes above (Findings-only scope, own permission set).
            Route::post('/work-orders/{workOrder}/execution-mode/external', [ExternalWorkOrderController::class, 'markExternalMode'])->middleware('permission:work_order.prepare_external');
            Route::post('/work-orders/{workOrder}/external-findings', [ExternalWorkOrderController::class, 'addFinding'])->middleware('permission:work_order.prepare_external');
            Route::put('/work-orders/{workOrder}/external-findings/{finding}', [ExternalWorkOrderController::class, 'updateFinding'])->middleware('permission:work_order.prepare_external');
            Route::delete('/work-orders/{workOrder}/external-findings/{finding}', [ExternalWorkOrderController::class, 'deleteFinding'])->middleware('permission:work_order.prepare_external');
            Route::post('/work-orders/{workOrder}/external', [ExternalWorkOrderController::class, 'finalize'])->middleware('permission:work_order.finalize_external');
            Route::post('/work-orders/{workOrder}/external/revise', [ExternalWorkOrderController::class, 'revise'])->middleware('permission:work_order.revise_external');
            Route::post('/work-orders/{workOrder}/external/cancel', [ExternalWorkOrderController::class, 'cancel'])->middleware('permission:work_order.cancel_external');
            Route::get('/external-work-order-references', [ExternalWorkOrderController::class, 'referenceIndex'])->middleware('permission:work_order.view_workshop_invoice_reference');

            // "Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice": the
            // full External Work Order Invoice list/detail — own permission namespace, deliberately
            // separate from workshop_invoice.* (the pre-existing, unrelated R1 feature).
            Route::get('/external-work-order-invoices', [ExternalWorkOrderInvoiceController::class, 'index'])->middleware('permission:external_work_order_invoice.view');
            Route::get('/external-work-order-invoices/{externalInvoice}', [ExternalWorkOrderInvoiceController::class, 'show'])->middleware('permission:external_work_order_invoice.view');
            Route::post('/external-work-order-invoices/{externalInvoice}/generate-authorization', [ExternalWorkOrderInvoiceController::class, 'generateAuthorization'])->middleware('permission:external_work_order_invoice.generate_authorization');
            Route::get('/external-work-order-invoices/{externalInvoice}/authorization', [ExternalWorkOrderInvoiceController::class, 'viewAuthorization'])->middleware('permission:external_work_order_invoice.view');
            Route::post('/external-work-order-invoices/{externalInvoice}/deliver', [ExternalWorkOrderInvoiceController::class, 'deliver'])->middleware('permission:external_work_order_invoice.deliver');
            Route::post('/external-work-order-invoices/{externalInvoice}/acknowledge', [ExternalWorkOrderInvoiceController::class, 'acknowledge'])->middleware('permission:external_work_order_invoice.acknowledge');
            Route::get('/external-work-order-invoices/{externalInvoice}/acknowledgement', [ExternalWorkOrderInvoiceController::class, 'viewAcknowledgement'])->middleware('permission:external_work_order_invoice.view');
            Route::post('/external-work-order-invoices/{externalInvoice}/complete', [ExternalWorkOrderInvoiceController::class, 'complete'])->middleware('permission:external_work_order_invoice.complete');
            Route::get('/external-work-order-invoices/{externalInvoice}/completed-work-order', [ExternalWorkOrderInvoiceController::class, 'viewCompletedWorkOrder'])->middleware('permission:external_work_order_invoice.view');
            Route::get('/external-work-order-invoices/{externalInvoice}/vendor-invoice', [ExternalWorkOrderInvoiceController::class, 'viewVendorInvoice'])->middleware('permission:external_work_order_invoice.view');
            Route::post('/external-work-order-invoices/{externalInvoice}/settle', [ExternalWorkOrderInvoiceController::class, 'settle'])->middleware('permission:external_work_order_invoice.settle');
            Route::get('/external-work-order-invoices/{externalInvoice}/payment-proof', [ExternalWorkOrderInvoiceController::class, 'viewPaymentProof'])->middleware('permission:external_work_order_invoice.view');
            Route::post('/work-orders/{workOrder}/estimate', [WorkOrderController::class, 'estimate'])->middleware('permission:work_order.estimate');
            Route::post('/work-orders/{workOrder}/diagnoses', [WorkOrderExecutionController::class, 'addDiagnosis'])->middleware('permission:diagnosis.manage');
            Route::post('/work-orders/{workOrder}/corrective-actions', [WorkOrderExecutionController::class, 'addCorrectiveAction'])->middleware('permission:diagnosis.manage');
            Route::post('/work-orders/{workOrder}/jobs', [WorkOrderExecutionController::class, 'addJob'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/status', [WorkOrderExecutionController::class, 'updateJobStatus'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/planned-parts', [WorkOrderExecutionController::class, 'addPlannedPart'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/additional-works', [WorkOrderExecutionController::class, 'requestAdditionalWork'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/additional-works/{additionalWork}/decide', [WorkOrderExecutionController::class, 'decideAdditionalWork'])->middleware('permission:work_order.approve');

            // Phase 5: Request Parts — a mechanic-initiated request/approval document, kept
            // deliberately separate from Planned Parts (own permission set; approval creates a
            // Planned Part rather than mutating stock directly — see WorkOrderPartRequestService).
            Route::get('/part-requests', [PartRequestController::class, 'index'])->middleware('permission:part_request.view');
            Route::get('/part-requests/{partRequest}', [PartRequestController::class, 'show'])->middleware('permission:part_request.view');
            Route::get('/work-orders/{workOrder}/part-requests', [PartRequestController::class, 'indexForWorkOrder'])->middleware('permission:part_request.view');
            Route::post('/work-orders/{workOrder}/part-requests', [PartRequestController::class, 'store'])->middleware('permission:part_request.create');
            Route::post('/part-requests/{partRequest}/approve', [PartRequestController::class, 'approve'])->middleware('permission:part_request.approve');
            Route::post('/part-requests/{partRequest}/reject', [PartRequestController::class, 'reject'])->middleware('permission:part_request.reject');
            Route::post('/part-requests/{partRequest}/cancel', [PartRequestController::class, 'cancel'])->middleware('permission:part_request.cancel');

            Route::post('/work-orders/{workOrder}/external-services', [WorkOrderExternalServiceController::class, 'store'])->middleware('permission:work_order_external_service.create');
            Route::post('/work-orders/{workOrder}/external-services/{externalService}/complete', [WorkOrderExternalServiceController::class, 'complete'])->middleware('permission:work_order_external_service.complete');
            Route::post('/work-orders/{workOrder}/external-services/{externalService}/cancel', [WorkOrderExternalServiceController::class, 'cancel'])->middleware('permission:work_order_external_service.cancel');
            Route::get('/work-orders/{workOrder}/external-services/{externalService}/print', [WorkOrderExternalServiceController::class, 'print'])->middleware('permission:work_order.view');

            // R1 (Workshop Invoice and Settlement) — externally-issued document, OptiFleet records it.
            Route::get('/workshop-invoices', [WorkshopInvoiceController::class, 'index'])->middleware('permission:workshop_invoice.view');
            Route::get('/workshop-invoices/{workshopInvoice}', [WorkshopInvoiceController::class, 'show'])->middleware('permission:workshop_invoice.view');
            Route::get('/workshop-invoices/{workshopInvoice}/print', [WorkshopInvoiceController::class, 'print'])->middleware('permission:workshop_invoice.view');
            Route::get('/workshop-invoices/{workshopInvoice}/reconciliation', [WorkshopInvoiceController::class, 'reconciliation'])->middleware('permission:workshop_invoice.view');
            Route::put('/workshop-invoices/{workshopInvoice}/reconciliation-note', [WorkshopInvoiceController::class, 'updateReconciliationNote'])->middleware('permission:workshop_invoice.view_settlement_history');
            Route::post('/work-orders/{workOrder}/external-services/{externalService}/workshop-invoice', [WorkshopInvoiceController::class, 'record'])->middleware('permission:workshop_invoice.record');
            Route::post('/workshop-invoices/{workshopInvoice}/payments', [WorkshopInvoiceController::class, 'recordPayment'])->middleware('permission:workshop_invoice.upload_payment');
            Route::post('/workshop-invoices/{workshopInvoice}/request-correction', [WorkshopInvoiceController::class, 'requestCorrection'])->middleware('permission:workshop_invoice.request_correction');
            Route::post('/workshop-invoices/{workshopInvoice}/corrections/{correction}/decide', [WorkshopInvoiceController::class, 'decideCorrection'])->middleware('permission:workshop_invoice.verify_correction');
            Route::post('/workshop-invoices/{workshopInvoice}/request-cancellation', [WorkshopInvoiceController::class, 'requestCancellation'])->middleware('permission:workshop_invoice.request_cancellation');
            Route::post('/workshop-invoices/{workshopInvoice}/cancellations/{cancellation}/decide', [WorkshopInvoiceController::class, 'decideCancellation'])->middleware('permission:workshop_invoice.verify_cancellation');
            Route::post('/work-orders/{workOrder}/mechanics', [WorkOrderExecutionController::class, 'assignMechanic'])->middleware('permission:worker.assign');
            Route::post('/work-orders/{workOrder}/mechanics/{assignment}/unassign', [WorkOrderExecutionController::class, 'unassignMechanic'])->middleware('permission:worker.assign');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/start', [WorkOrderExecutionController::class, 'startLabor'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/{laborLog}/pause', [WorkOrderExecutionController::class, 'pauseLabor'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/{laborLog}/resume', [WorkOrderExecutionController::class, 'resumeLabor'])->middleware('permission:maintenance_job.manage');
            Route::post('/work-orders/{workOrder}/jobs/{job}/labor/{laborLog}/finish', [WorkOrderExecutionController::class, 'finishLabor'])->middleware('permission:maintenance_job.manage');

            Route::get('/qc-inspections', [QualityControlController::class, 'index'])->middleware('permission:qc.view');
            Route::post('/work-orders/{workOrder}/qc/start', [QualityControlController::class, 'start'])->middleware('permission:qc.perform');
            Route::post('/work-orders/{workOrder}/qc/{inspection}/findings', [QualityControlController::class, 'addFinding'])->middleware('permission:qc.perform');
            Route::post('/work-orders/{workOrder}/qc/{inspection}/findings/{finding}/resolve', [QualityControlController::class, 'resolveFinding'])->middleware('permission:qc.perform');
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
            Route::post('/workers/{worker}/link-user', [WorkerController::class, 'linkUser'])->middleware('permission:worker.manage');
            Route::post('/workers/{worker}/unlink-user', [WorkerController::class, 'unlinkUser'])->middleware('permission:worker.manage');
            Route::post('/workers/{worker}/assign', [WorkerController::class, 'assign'])->middleware('permission:worker.assign');

            Route::get('/worker-types', [WorkerTypeController::class, 'index'])->middleware('permission:worker.view');
            Route::post('/worker-types', [WorkerTypeController::class, 'store'])->middleware('permission:worker.manage');
            Route::put('/worker-types/{workerType}', [WorkerTypeController::class, 'update'])->middleware('permission:worker.manage');
            Route::delete('/worker-types/{workerType}', [WorkerTypeController::class, 'destroy'])->middleware('permission:worker.manage');

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

        Route::middleware('module:INVENTORY')->group(function () {
            // "Next Improvement Tenant Portal - Products": Product Categories
            // are Superadmin-managed only — tenants keep read-only access
            // for the Dynamic Product Form; create/update/delete moved to
            // routes/api/platform.php.
            Route::get('/product-categories', [ProductCategoryController::class, 'index'])->middleware('permission:product.view');

            Route::get('/uoms', [UomController::class, 'index'])->middleware('permission:product.view');
            Route::post('/uoms', [UomController::class, 'store'])->middleware('permission:product.create');
            Route::put('/uoms/{uom}', [UomController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/uoms/{uom}', [UomController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/tool-types', [ToolTypeController::class, 'index'])->middleware('permission:product.view');
            Route::post('/tool-types', [ToolTypeController::class, 'store'])->middleware('permission:product.create');
            Route::put('/tool-types/{toolType}', [ToolTypeController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/tool-types/{toolType}', [ToolTypeController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/equipment-types', [EquipmentTypeController::class, 'index'])->middleware('permission:product.view');
            Route::post('/equipment-types', [EquipmentTypeController::class, 'store'])->middleware('permission:product.create');
            Route::put('/equipment-types/{equipmentType}', [EquipmentTypeController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/equipment-types/{equipmentType}', [EquipmentTypeController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/storage-requirements', [StorageRequirementController::class, 'index'])->middleware('permission:product.view');
            Route::post('/storage-requirements', [StorageRequirementController::class, 'store'])->middleware('permission:product.create');
            Route::put('/storage-requirements/{storageRequirement}', [StorageRequirementController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/storage-requirements/{storageRequirement}', [StorageRequirementController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/tire-load-indices', [TireLoadIndexController::class, 'index'])->middleware('permission:product.view');
            Route::post('/tire-load-indices', [TireLoadIndexController::class, 'store'])->middleware('permission:product.create');
            Route::put('/tire-load-indices/{tireLoadIndex}', [TireLoadIndexController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/tire-load-indices/{tireLoadIndex}', [TireLoadIndexController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/tire-speed-ratings', [TireSpeedRatingController::class, 'index'])->middleware('permission:product.view');
            Route::post('/tire-speed-ratings', [TireSpeedRatingController::class, 'store'])->middleware('permission:product.create');
            Route::put('/tire-speed-ratings/{tireSpeedRating}', [TireSpeedRatingController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/tire-speed-ratings/{tireSpeedRating}', [TireSpeedRatingController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/tire-ply-ratings', [TirePlyRatingController::class, 'index'])->middleware('permission:product.view');
            Route::post('/tire-ply-ratings', [TirePlyRatingController::class, 'store'])->middleware('permission:product.create');
            Route::put('/tire-ply-ratings/{tirePlyRating}', [TirePlyRatingController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/tire-ply-ratings/{tirePlyRating}', [TirePlyRatingController::class, 'destroy'])->middleware('permission:product.delete');

            Route::get('/tire-tra-codes', [TireTraCodeController::class, 'index'])->middleware('permission:product.view');
            Route::post('/tire-tra-codes', [TireTraCodeController::class, 'store'])->middleware('permission:product.create');
            Route::get('/tire-tra-codes/{tireTraCode}', [TireTraCodeController::class, 'show'])->middleware('permission:product.view');
            Route::put('/tire-tra-codes/{tireTraCode}', [TireTraCodeController::class, 'update'])->middleware('permission:product.update');
            Route::delete('/tire-tra-codes/{tireTraCode}', [TireTraCodeController::class, 'destroy'])->middleware('permission:product.delete');
            Route::post('/tire-tra-codes/{tireTraCode}/star-ratings', [TireTraCodeController::class, 'storeStarRating'])->middleware('permission:product.create');
            Route::put('/tire-tra-codes/{tireTraCode}/star-ratings/{starRating}', [TireTraCodeController::class, 'updateStarRating'])->middleware('permission:product.update');
            Route::delete('/tire-tra-codes/{tireTraCode}/star-ratings/{starRating}', [TireTraCodeController::class, 'destroyStarRating'])->middleware('permission:product.delete');

            Route::get('/products', [ProductController::class, 'index'])->middleware('permission:product.view');
            Route::post('/products', [ProductController::class, 'store'])->middleware('permission:product.create');
            Route::get('/products/compatible', [ProductController::class, 'compatibleFor'])->middleware('permission:product.view');
            Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('permission:product.view');
            Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:product.update');
            Route::post('/products/{product}/component-groups', [ProductController::class, 'syncComponentGroups'])->middleware('permission:product.update');
            Route::post('/products/{product}/compatibilities', [ProductController::class, 'addCompatibility'])->middleware('permission:product.update');
            Route::delete('/products/{product}/compatibilities/{compatibility}', [ProductController::class, 'destroyCompatibility'])->middleware('permission:product.update');

            Route::get('/inventory', [WarehouseStockController::class, 'index'])->middleware('permission:inventory.view');
            Route::post('/inventory/adjust', [WarehouseStockController::class, 'adjust'])->middleware('permission:inventory.adjust');
            Route::post('/inventory/scrap', [WarehouseStockController::class, 'scrap'])->middleware('permission:inventory.scrap');
            Route::get('/inventory/{warehouseStock}', [WarehouseStockController::class, 'show'])->middleware('permission:inventory.view');
            Route::put('/inventory/{warehouseStock}/thresholds', [WarehouseStockController::class, 'updateThresholds'])->middleware('permission:inventory.adjust');

            Route::get('/stock-movements', [StockMovementController::class, 'index'])->middleware('permission:inventory.view');

            Route::get('/used-part-returns', [UsedPartDispositionController::class, 'index'])->middleware('permission:used_part.view');
            Route::get('/used-part-returns/{usedPartReturn}', [UsedPartDispositionController::class, 'show'])->middleware('permission:used_part.view');
            Route::post('/used-part-returns/{usedPartReturn}/inspect', [UsedPartDispositionController::class, 'inspect'])->middleware('permission:used_part.inspect');
            Route::post('/used-part-returns/{usedPartReturn}/propose-disposition', [UsedPartDispositionController::class, 'proposeDisposition'])->middleware('permission:used_part.dispose');
            Route::post('/used-part-returns/{usedPartReturn}/decide', [UsedPartDispositionController::class, 'decide'])->middleware('permission:used_part.approve');

            Route::get('/sparepart-sales', [SparePartSaleController::class, 'index'])->middleware('permission:sparepart_sale.view');
            Route::get('/sparepart-sales/{sparePartSale}', [SparePartSaleController::class, 'show'])->middleware('permission:sparepart_sale.view');
            Route::post('/sparepart-sales', [SparePartSaleController::class, 'store'])->middleware('permission:sparepart_sale.create');
            Route::post('/sparepart-sales/{sparePartSale}/submit', [SparePartSaleController::class, 'submit'])->middleware('permission:sparepart_sale.create');
            Route::post('/sparepart-sales/{sparePartSale}/decide', [SparePartSaleController::class, 'decide'])->middleware('permission:sparepart_sale.approve');

            Route::get('/stock-reservations', [StockReservationController::class, 'index'])->middleware('permission:inventory.view');
            Route::get('/stock-reservations/{stockReservation}', [StockReservationController::class, 'show'])->middleware('permission:inventory.view');
            Route::post('/stock-reservations/{stockReservation}/cancel', [StockReservationController::class, 'cancel'])->middleware('permission:inventory.reserve');

            Route::get('/stock-opnames', [StockOpnameController::class, 'index'])->middleware('permission:inventory.stock_opname');
            Route::post('/stock-opnames', [StockOpnameController::class, 'store'])->middleware('permission:inventory.stock_opname');
            Route::get('/stock-opnames/{stockOpname}', [StockOpnameController::class, 'show'])->middleware('permission:inventory.stock_opname');
            Route::post('/stock-opnames/{stockOpname}/items/{item}/count', [StockOpnameController::class, 'recordCount'])->middleware('permission:inventory.stock_opname');
            Route::post('/stock-opnames/{stockOpname}/counting', [StockOpnameController::class, 'transitionToCounting'])->middleware('permission:inventory.stock_opname');
            Route::post('/stock-opnames/{stockOpname}/submit', [StockOpnameController::class, 'submit'])->middleware('permission:inventory.stock_opname');
            Route::post('/stock-opnames/{stockOpname}/approve', [StockOpnameController::class, 'approve'])->middleware('permission:inventory.stock_opname');
            Route::post('/stock-opnames/{stockOpname}/post', [StockOpnameController::class, 'post'])->middleware('permission:inventory.stock_opname');

            Route::get('/stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:stock_transfer.view');
            Route::post('/stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:stock_transfer.create');
            Route::get('/stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->middleware('permission:stock_transfer.view');
            Route::post('/stock-transfers/{stockTransfer}/submit', [StockTransferController::class, 'submit'])->middleware('permission:stock_transfer.create');
            Route::post('/stock-transfers/{stockTransfer}/approve', [StockTransferController::class, 'approve'])->middleware('permission:stock_transfer.approve');
            Route::post('/stock-transfers/{stockTransfer}/prepare', [StockTransferController::class, 'prepare'])->middleware('permission:stock_transfer.approve');
            Route::post('/stock-transfers/{stockTransfer}/dispatch', [StockTransferController::class, 'dispatch'])->middleware('permission:stock_transfer.dispatch');
            Route::post('/stock-transfers/{stockTransfer}/in-transit', [StockTransferController::class, 'markInTransit'])->middleware('permission:stock_transfer.dispatch');
            Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->middleware('permission:stock_transfer.receive');
            Route::post('/stock-transfers/{stockTransfer}/complete', [StockTransferController::class, 'complete'])->middleware('permission:stock_transfer.receive');
            Route::post('/stock-transfers/{stockTransfer}/reject', [StockTransferController::class, 'reject'])->middleware('permission:stock_transfer.approve');
            Route::post('/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->middleware('permission:stock_transfer.create');

            Route::post('/work-orders/{workOrder}/planned-parts/{plannedPart}/reserve', [WorkOrderExecutionController::class, 'reservePlannedPart'])->middleware('permission:inventory.reserve');
            Route::post('/work-orders/{workOrder}/planned-parts/{plannedPart}/issue', [WorkOrderExecutionController::class, 'issuePlannedPart'])->middleware('permission:inventory.issue');
            Route::post('/work-orders/{workOrder}/planned-parts/{plannedPart}/return', [WorkOrderExecutionController::class, 'returnPlannedPart'])->middleware('permission:inventory.return');
            Route::post('/work-orders/{workOrder}/planned-parts/{plannedPart}/consume', [WorkOrderExecutionController::class, 'consumePlannedPart'])->middleware('permission:inventory.issue');
        });

        Route::middleware('module:PROCUREMENT')->group(function () {
            Route::get('/purchase-requests', [PurchaseRequestController::class, 'index'])->middleware('permission:purchase_request.view');
            Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])->middleware('permission:purchase_request.create');
            Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->middleware('permission:purchase_request.view');
            Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchase_request.submit');
            Route::post('/purchase-requests/{purchaseRequest}/review', [PurchaseRequestController::class, 'review'])->middleware('permission:purchase_request.approve');
            Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:purchase_request.approve');
            Route::post('/purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->middleware('permission:purchase_request.approve');
            Route::post('/purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware('permission:purchase_request.create');
            Route::put('/purchase-requests/{purchaseRequest}/items/{item}/line-status', [PurchaseRequestController::class, 'setItemLineStatus'])->middleware('permission:purchase_request.approve');

            Route::get('/rfqs', [RfqController::class, 'index'])->middleware('permission:rfq.view');
            Route::post('/rfqs', [RfqController::class, 'store'])->middleware('permission:rfq.manage');
            Route::get('/rfqs/{rfq}', [RfqController::class, 'show'])->middleware('permission:rfq.view');
            Route::post('/rfqs/{rfq}/vendors', [RfqController::class, 'inviteVendors'])->middleware('permission:rfq.manage');
            Route::post('/rfqs/{rfq}/close', [RfqController::class, 'close'])->middleware('permission:rfq.manage');
            Route::post('/rfqs/{rfq}/cancel', [RfqController::class, 'cancel'])->middleware('permission:rfq.manage');
            Route::get('/rfqs/{rfq}/compare', [RfqController::class, 'compare'])->middleware('permission:quotation.view');
            Route::post('/rfqs/{rfq}/quotations', [VendorQuotationController::class, 'store'])->middleware('permission:quotation.manage');

            Route::get('/quotations', [VendorQuotationController::class, 'index'])->middleware('permission:quotation.view');
            Route::get('/quotations/{quotation}', [VendorQuotationController::class, 'show'])->middleware('permission:quotation.view');
            Route::post('/quotations/{quotation}/select', [VendorQuotationController::class, 'select'])->middleware('permission:quotation.select');
            Route::post('/quotations/{quotation}/purchase-order', [PurchaseOrderController::class, 'storeFromQuotation'])->middleware('permission:purchase_order.create');

            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_order.view');
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_order.create');
            Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase_order.view');
            Route::get('/purchase-orders/{purchaseOrder}/print', [PurchaseOrderController::class, 'print'])->middleware('permission:purchase_order.view');
            Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase_order.create');
            Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase_order.approve');
            Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->middleware('permission:purchase_order.approve');
            Route::post('/purchase-orders/{purchaseOrder}/decide-approval', [PurchaseOrderController::class, 'decideApproval'])->middleware('permission:purchase_order.approve');
            Route::post('/purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->middleware('permission:purchase_order.issue');
            Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase_order.approve');
            Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase_order.create');
            Route::post('/purchase-orders/{purchaseOrder}/goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:goods_receipt.post');

            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:goods_receipt.view');
            Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:goods_receipt.view');

            Route::get('/vendor-invoice-references', [VendorInvoiceReferenceController::class, 'index'])->middleware('permission:goods_receipt.view');
            Route::post('/vendor-invoice-references', [VendorInvoiceReferenceController::class, 'store'])->middleware('permission:goods_receipt.create');
            Route::get('/vendor-invoice-references/{vendorInvoiceReference}', [VendorInvoiceReferenceController::class, 'show'])->middleware('permission:goods_receipt.view');
            Route::get('/vendor-invoice-references/{vendorInvoiceReference}/download', [VendorInvoiceReferenceController::class, 'download'])->middleware('permission:goods_receipt.view');
            Route::post('/vendor-invoice-references/{vendorInvoiceReference}/status', [VendorInvoiceReferenceController::class, 'updateStatus'])->middleware('permission:goods_receipt.create');
        });

        Route::middleware('module:PARTNER')->group(function () {
            Route::get('/partners', [PartnerController::class, 'index'])->middleware('permission:partner.view');
            Route::post('/partners', [PartnerController::class, 'store'])->middleware('permission:partner.manage');
            Route::get('/partners/{partner}', [PartnerController::class, 'show'])->middleware('permission:partner.view');
            Route::put('/partners/{partner}', [PartnerController::class, 'update'])->middleware('permission:partner.manage');
        });

        Route::middleware('module:TIRE')->group(function () {
            Route::get('/rims', [RimController::class, 'index'])->middleware('permission:rim.view');
            Route::post('/rims', [RimController::class, 'store'])->middleware('permission:rim.manage');
            Route::get('/rims/{rim}', [RimController::class, 'show'])->middleware('permission:rim.view');
            Route::put('/rims/{rim}', [RimController::class, 'update'])->middleware('permission:rim.manage');
            Route::delete('/rims/{rim}', [RimController::class, 'destroy'])->middleware('permission:rim.manage');

            Route::get('/wheel-configurations', [WheelConfigurationController::class, 'index'])->middleware('permission:tire.view');
            Route::post('/wheel-configurations', [WheelConfigurationController::class, 'store'])->middleware('permission:tire.manage');
            Route::put('/wheel-configurations/{wheelConfiguration}', [WheelConfigurationController::class, 'update'])->middleware('permission:tire.manage');
            Route::delete('/wheel-configurations/{wheelConfiguration}', [WheelConfigurationController::class, 'destroy'])->middleware('permission:tire.manage');

            Route::get('/tires', [TireController::class, 'index'])->middleware('permission:tire.view');
            Route::post('/tires', [TireController::class, 'store'])->middleware('permission:tire.manage');
            Route::get('/tires/{tire}', [TireController::class, 'show'])->middleware('permission:tire.view');
            Route::post('/tires/{tire}/install', [TireController::class, 'install'])->middleware('permission:tire.install');
            Route::post('/tires/{tire}/rotate', [TireController::class, 'rotate'])->middleware('permission:tire.rotate');
            Route::post('/tires/{tire}/swap-positions', [TireController::class, 'swapPositions'])->middleware('permission:tire.rotate');
            Route::post('/tires/{tire}/inspect', [TireController::class, 'inspect'])->middleware('permission:tire.inspect');
            Route::post('/tires/{tire}/remove', [TireController::class, 'remove'])->middleware('permission:tire.remove');
            Route::post('/tires/{tire}/replace', [TireController::class, 'replace'])->middleware('permission:tire.remove');
            // Phase E: send/receive/inspect/approve are four distinct permissions per cycle
            // type — no single actor is expected to hold all four (G-32 maker-checker).
            Route::post('/tires/{tire}/retread', [TireController::class, 'sendForRetread'])->middleware('permission:tire_retread.send');
            Route::post('/tires/{tire}/retreads/{retread}/receive', [TireController::class, 'receiveRetread'])->middleware('permission:tire_retread.receive');
            Route::post('/tires/{tire}/retreads/{retread}/final-inspect', [TireController::class, 'finalInspectRetread'])->middleware('permission:tire_retread.inspect');
            Route::post('/tires/{tire}/retreads/{retread}/approve', [TireController::class, 'approveRetread'])->middleware('permission:tire_retread.approve');
            Route::post('/tires/{tire}/repair', [TireController::class, 'sendForRepair'])->middleware('permission:tire_repair.send');
            Route::post('/tires/{tire}/repairs/{repair}/receive', [TireController::class, 'receiveRepair'])->middleware('permission:tire_repair.receive');
            Route::post('/tires/{tire}/repairs/{repair}/final-inspect', [TireController::class, 'finalInspectRepair'])->middleware('permission:tire_repair.inspect');
            Route::post('/tires/{tire}/repairs/{repair}/approve', [TireController::class, 'approveRepair'])->middleware('permission:tire_repair.approve');
            Route::post('/tires/{tire}/scrap', [TireController::class, 'scrap'])->middleware('permission:tire.scrap');
            // Phase F: calculate/finalize are distinct permissions (maker-checker on the score itself).
            Route::post('/tires/{tire}/scoring', [TireController::class, 'calculateScoring'])->middleware('permission:tire_scoring.calculate');
            Route::post('/tires/{tire}/scoring/{scoringResult}/finalize', [TireController::class, 'finalizeScoring'])->middleware('permission:tire_scoring.finalize');
            Route::post('/tires/{tire}/sell', [TireController::class, 'sell'])->middleware('permission:tire.sell');
        });

        Route::middleware('module:COMPONENT')->group(function () {
            Route::get('/component-assets', [ComponentAssetController::class, 'index'])->middleware('permission:component_asset.view');
            Route::post('/component-assets', [ComponentAssetController::class, 'store'])->middleware('permission:component_asset.manage');
            Route::get('/component-assets/{componentAsset}', [ComponentAssetController::class, 'show'])->middleware('permission:component_asset.view');
            Route::post('/component-assets/{componentAsset}/install', [ComponentAssetController::class, 'install'])->middleware('permission:component_asset.install');
            Route::post('/component-assets/{componentAsset}/remove', [ComponentAssetController::class, 'remove'])->middleware('permission:component_asset.remove');
            Route::post('/component-assets/{componentAsset}/replace', [ComponentAssetController::class, 'replace'])->middleware('permission:component_asset.replace');
            Route::post('/component-assets/{componentAsset}/repairs', [ComponentAssetController::class, 'startRepair'])->middleware('permission:component_asset.manage');
            Route::post('/component-assets/{componentAsset}/repairs/{repair}/complete', [ComponentAssetController::class, 'completeRepair'])->middleware('permission:component_asset.manage');
        });

        Route::middleware('module:WARRANTY')->group(function () {
            Route::get('/warranties', [WarrantyController::class, 'index'])->middleware('permission:warranty.view');
            Route::post('/warranties', [WarrantyController::class, 'store'])->middleware('permission:warranty.manage');
            Route::get('/warranties/{warranty}', [WarrantyController::class, 'show'])->middleware('permission:warranty.view');
            Route::post('/warranties/{warranty}/eligibility', [WarrantyController::class, 'checkEligibility'])->middleware('permission:warranty.view');
            Route::post('/warranties/{warranty}/void', [WarrantyController::class, 'void'])->middleware('permission:warranty.manage');

            Route::get('/warranty-claims', [WarrantyClaimController::class, 'index'])->middleware('permission:warranty.view');
            Route::post('/warranty-claims', [WarrantyClaimController::class, 'store'])->middleware('permission:warranty_claim.create');
            Route::get('/warranty-claims/{warrantyClaim}', [WarrantyClaimController::class, 'show'])->middleware('permission:warranty.view');
            Route::post('/warranty-claims/{warrantyClaim}/submit', [WarrantyClaimController::class, 'submit'])->middleware('permission:warranty_claim.create');
            Route::post('/warranty-claims/{warrantyClaim}/review', [WarrantyClaimController::class, 'review'])->middleware('permission:warranty_claim.review');
            Route::post('/warranty-claims/{warrantyClaim}/approve', [WarrantyClaimController::class, 'approve'])->middleware('permission:warranty_claim.approve');
            Route::post('/warranty-claims/{warrantyClaim}/reject', [WarrantyClaimController::class, 'reject'])->middleware('permission:warranty_claim.approve');
            Route::post('/warranty-claims/{warrantyClaim}/replacement', [WarrantyClaimController::class, 'markReplacement'])->middleware('permission:warranty_claim.approve');
            Route::post('/warranty-claims/{warrantyClaim}/repair', [WarrantyClaimController::class, 'markRepair'])->middleware('permission:warranty_claim.approve');
            Route::post('/warranty-claims/{warrantyClaim}/settle', [WarrantyClaimController::class, 'settle'])->middleware('permission:warranty_claim.approve');
            Route::post('/warranty-claims/{warrantyClaim}/close', [WarrantyClaimController::class, 'close'])->middleware('permission:warranty_claim.approve');
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

        // Phase 5 Section 39: Configuration — gated by permission only (not
        // a module), same as Audit Log above.
        Route::prefix('configuration')->group(function () {
            Route::get('/sets', [ConfigurationController::class, 'index'])->middleware('permission:configuration.view');
            Route::get('/sets/{set}', [ConfigurationController::class, 'show'])->middleware('permission:configuration.view');
            Route::get('/history', [ConfigurationController::class, 'history'])->middleware('permission:configuration_history.view');
            Route::get('/metadata', [ConfigurationController::class, 'metadata'])->middleware('permission:configuration.view');
            Route::post('/preview', [ConfigurationController::class, 'preview'])->middleware('permission:configuration.view');
            // These 4 span all 3 versioned types (NUMBERING/TEMPLATE/WORKFLOW), so the
            // matching manage/publish permission is checked inside the controller
            // (PermissionService, keyed off the set's type) rather than one fixed
            // route-level permission.
            Route::post('/versions', [ConfigurationController::class, 'store']);
            Route::put('/versions/{version}', [ConfigurationController::class, 'updateDraft']);
            Route::post('/versions/{version}/publish', [ConfigurationController::class, 'publish']);
            Route::post('/versions/{version}/archive', [ConfigurationController::class, 'archive']);
        });

        Route::prefix('notification-rules')->group(function () {
            Route::get('/', [NotificationRuleController::class, 'index'])->middleware('permission:configuration.view');
            Route::get('/events', [NotificationRuleController::class, 'events'])->middleware('permission:configuration.view');
            Route::post('/', [NotificationRuleController::class, 'store'])->middleware('permission:notification_rule.manage');
            Route::put('/{rule}', [NotificationRuleController::class, 'update'])->middleware('permission:notification_rule.manage');
            Route::post('/{rule}/activate', [NotificationRuleController::class, 'activate'])->middleware('permission:notification_rule.manage');
            Route::post('/{rule}/deactivate', [NotificationRuleController::class, 'deactivate'])->middleware('permission:notification_rule.manage');
        });

        // Phase 6: Analytics & Data Warehouse. Read-only MongoDB-projection
        // APIs — every route still passes through tenant context, module
        // entitlement, permission, and (per-domain) data scope exactly like
        // the transactional APIs above (Section 6).
        Route::middleware('module:ANALYTICS')->prefix('analytics')->group(function () {
            Route::get('/overview', [OverviewAnalyticsController::class, 'index'])->middleware('permission:analytics.overview.view');
            Route::get('/kpi-catalog', [OverviewAnalyticsController::class, 'kpiCatalog'])->middleware('permission:analytics.overview.view');
            Route::get('/fleet', [FleetAnalyticsController::class, 'index'])->middleware('permission:analytics.fleet.view');
            Route::get('/maintenance', [MaintenanceAnalyticsController::class, 'index'])->middleware('permission:analytics.maintenance.view');
            Route::get('/work-orders', [WorkOrderAnalyticsController::class, 'index'])->middleware('permission:analytics.work_order.view');
            Route::get('/breakdowns', [BreakdownAnalyticsController::class, 'index'])->middleware('permission:analytics.breakdown.view');
            Route::get('/downtime', [DowntimeAnalyticsController::class, 'index'])->middleware('permission:analytics.breakdown.view');
            Route::get('/workshops', [WorkshopAnalyticsController::class, 'index'])->middleware('permission:analytics.workshop.view');
            Route::get('/mechanics', [MechanicAnalyticsController::class, 'index'])->middleware('permission:analytics.mechanic.view');
            Route::get('/inventory', [InventoryAnalyticsController::class, 'index'])->middleware('permission:analytics.inventory.view');
            Route::get('/procurement', [ProcurementAnalyticsController::class, 'index'])->middleware('permission:analytics.procurement.view');
            Route::get('/vendors', [VendorAnalyticsController::class, 'index'])->middleware('permission:analytics.vendor.view');
            Route::get('/cost', [CostAnalyticsController::class, 'index'])->middleware('permission:analytics.cost.view');
            Route::get('/tires', [TireAnalyticsController::class, 'index'])->middleware('permission:analytics.tire.view');
            Route::get('/components', [ComponentAnalyticsController::class, 'index'])->middleware('permission:analytics.component.view');
            Route::get('/warranty', [WarrantyAnalyticsController::class, 'index'])->middleware('permission:analytics.warranty.view');

            Route::get('/export/{domain}', [ExportAnalyticsController::class, 'export'])->middleware('permission:analytics.export');
        });

        // Phase 7: Maintenance Intelligence. Same discipline as Analytics
        // above (Section 6/60) — module entitlement + per-route permission
        // + data-scope enforcement inside each controller. No route here
        // ever mutates an operational table except recommendation review
        // actions, and those only through the existing Maintenance
        // Request workflow (Section 2).
        Route::middleware('module:MAINTENANCE_INTELLIGENCE')->prefix('intelligence')->group(function () {
            Route::get('/overview', [IntelligenceOverviewController::class, 'index'])->middleware('permission:intelligence.overview.view');
            Route::get('/vehicles', [VehicleIntelligenceController::class, 'index'])->middleware('permission:intelligence.vehicle.view');
            Route::get('/vehicles/{vehicle}', [VehicleIntelligenceController::class, 'show'])->middleware('permission:intelligence.vehicle.view');
            Route::get('/components', [ComponentIntelligenceController::class, 'index'])->middleware('permission:intelligence.component.view');
            Route::get('/tires', [TireIntelligenceApiController::class, 'index'])->middleware('permission:intelligence.tire.view');
            Route::get('/inventory', [InventoryIntelligenceController::class, 'index'])->middleware('permission:intelligence.inventory.view');
            Route::get('/inventory/forecast', [InventoryIntelligenceController::class, 'forecast'])->middleware('permission:intelligence.inventory.view');
            Route::get('/predictions', [PredictionHistoryController::class, 'index'])->middleware('permission:intelligence.vehicle.view');

            Route::get('/recommendations', [RecommendationController::class, 'index'])->middleware('permission:intelligence.recommendation.view');
            Route::post('/recommendations/{id}/review', [RecommendationController::class, 'review'])->middleware('permission:intelligence.recommendation.review');
            Route::post('/recommendations/{id}/accept', [RecommendationController::class, 'accept'])->middleware('permission:intelligence.recommendation.accept');
            Route::post('/recommendations/{id}/reject', [RecommendationController::class, 'reject'])->middleware('permission:intelligence.recommendation.reject');
            Route::post('/recommendations/{id}/convert', [RecommendationController::class, 'convert'])->middleware('permission:intelligence.recommendation.convert');
        });
    });
});
