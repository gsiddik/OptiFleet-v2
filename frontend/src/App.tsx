import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthContext';
import { RequirePermission, RequirePlatform, RequireTenant } from './components/RouteGuards';
import { PlatformLayout } from './layouts/PlatformLayout';
import { TenantLayout } from './layouts/TenantLayout';
import { LoginPage } from './pages/LoginPage';
import { BreadcrumbLabelProvider } from './navigation/BreadcrumbLabelContext';
import { NavigationTrailProvider } from './navigation/NavigationTrailContext';

import { PlatformDashboardPage } from './pages/platform/DashboardPage';
import { TenantListPage } from './pages/platform/tenants/TenantListPage';
import { TenantDetailPage } from './pages/platform/tenants/TenantDetailPage';
import { ModuleCatalogPage } from './pages/platform/modules/ModuleCatalogPage';
import { ProductCategoriesPage as PlatformProductCategoriesPage } from './pages/platform/masterdata/ProductCategoriesPage';
import { ComponentGroupsPage as PlatformComponentGroupsPage } from './pages/platform/masterdata/ComponentGroupsPage';
import { ComponentCategoriesPage as PlatformComponentCategoriesPage } from './pages/platform/masterdata/ComponentCategoriesPage';
import { ComponentSubcategoriesPage as PlatformComponentSubcategoriesPage } from './pages/platform/masterdata/ComponentSubcategoriesPage';
import { ModuleDetailPage } from './pages/platform/modules/ModuleDetailPage';
import { PlatformUsersPage } from './pages/platform/access/PlatformUsersPage';
import { PlatformRolesPage } from './pages/platform/access/PlatformRolesPage';
import { PlatformAuditLogPage } from './pages/platform/audit/PlatformAuditLogPage';
import { BundleListPage } from './pages/platform/bundles/BundleListPage';
import { BundleDetailPage } from './pages/platform/bundles/BundleDetailPage';
import { PricingListPage } from './pages/platform/pricing/PricingListPage';
import { ContractListPage } from './pages/platform/contracts/ContractListPage';
import { ContractDetailPage } from './pages/platform/contracts/ContractDetailPage';
import { SubscriptionListPage } from './pages/platform/subscriptions/SubscriptionListPage';
import { BillingListPage } from './pages/platform/billing/BillingListPage';
import { InvoiceListPage } from './pages/platform/invoices/InvoiceListPage';
import { InvoiceDetailPage } from './pages/platform/invoices/InvoiceDetailPage';
import { PaymentListPage } from './pages/platform/payments/PaymentListPage';
import { PaymentDetailPage } from './pages/platform/payments/PaymentDetailPage';

import { TenantDashboardPage } from './pages/tenant/DashboardPage';
import { BranchesPage } from './pages/tenant/organization/BranchesPage';
import { WorkshopsPage } from './pages/tenant/organization/WorkshopsPage';
import { WarehousesPage } from './pages/tenant/organization/WarehousesPage';
import { VehicleCategoriesPage } from './pages/tenant/masterdata/VehicleCategoriesPage';
import { ComponentGroupsPage } from './pages/tenant/masterdata/ComponentGroupsPage';
import { ComponentCategoriesPage } from './pages/tenant/masterdata/ComponentCategoriesPage';
import { ComponentSubcategoriesPage } from './pages/tenant/masterdata/ComponentSubcategoriesPage';
import { UomsPage } from './pages/tenant/masterdata/UomsPage';
import { VehicleBrandsPage } from './pages/tenant/masterdata/VehicleBrandsPage';
import { VehicleModelsPage } from './pages/tenant/masterdata/VehicleModelsPage';
import { TenantUsersPage } from './pages/tenant/access/TenantUsersPage';
import { TenantRolesPage } from './pages/tenant/access/TenantRolesPage';
import { TenantAuditLogPage } from './pages/tenant/audit/TenantAuditLogPage';
import { NumberingConfigPage } from './pages/tenant/configuration/NumberingConfigPage';
import { DocumentTemplateConfigPage } from './pages/tenant/configuration/DocumentTemplateConfigPage';
import { WorkflowConfigPage } from './pages/tenant/configuration/WorkflowConfigPage';
import { TireScoringConfigPage } from './pages/tenant/configuration/TireScoringConfigPage';
import { NotificationRulesPage } from './pages/tenant/configuration/NotificationRulesPage';
import { ConfigurationHistoryPage } from './pages/tenant/configuration/ConfigurationHistoryPage';
import { AccountSubscriptionPage } from './pages/tenant/account/AccountSubscriptionPage';
import { AccountContractPage } from './pages/tenant/account/AccountContractPage';
import { CompanyProfilePage } from './pages/tenant/account/CompanyProfilePage';
import { AccountInvoiceListPage } from './pages/tenant/account/AccountInvoiceListPage';
import { AccountInvoiceDetailPage } from './pages/tenant/account/AccountInvoiceDetailPage';
import { AccountPaymentListPage } from './pages/tenant/account/AccountPaymentListPage';
import { AccountPaymentDetailPage } from './pages/tenant/account/AccountPaymentDetailPage';
import { LoadingState } from './components/States';

import { VehicleListPage } from './pages/tenant/vehicles/VehicleListPage';
import { VehicleDetailPage } from './pages/tenant/vehicles/VehicleDetailPage';
import { VehicleTransferListPage } from './pages/tenant/vehicles/VehicleTransferListPage';
import { MaintenanceHistoryPage } from './pages/tenant/history/MaintenanceHistoryPage';
import { VehicleHistoryPage } from './pages/tenant/vehicles/VehicleHistoryPage';
import { InspectionListPage } from './pages/tenant/inspections/InspectionListPage';
import { InspectionDetailPage } from './pages/tenant/inspections/InspectionDetailPage';
import { InspectionTemplateListPage } from './pages/tenant/inspections/InspectionTemplateListPage';
import { MaintenanceSchedulePage } from './pages/tenant/maintenance/MaintenanceSchedulePage';
import { MaintenancePackagesPage, MaintenancePackageDetailPage } from './pages/tenant/maintenance/MaintenancePackagesPage';
import { MaintenanceRequestListPage } from './pages/tenant/maintenance/MaintenanceRequestListPage';
import { MaintenanceRequestDetailPage } from './pages/tenant/maintenance/MaintenanceRequestDetailPage';
import { BreakdownListPage } from './pages/tenant/maintenance/BreakdownListPage';
import { BreakdownDetailPage } from './pages/tenant/maintenance/BreakdownDetailPage';
import { PartRequestListPage } from './pages/tenant/workorders/PartRequestListPage';
import { WorkOrderListPage } from './pages/tenant/workorders/WorkOrderListPage';
import { WorkOrderDetailPage } from './pages/tenant/workorders/WorkOrderDetailPage';
import { ExternalWorkOrderInvoiceListPage } from './pages/tenant/external-work-order-invoices/ExternalWorkOrderInvoiceListPage';
import { WorkshopInvoiceDetailPage } from './pages/tenant/workshop-invoices/WorkshopInvoiceDetailPage';
import { WorkspaceListPage } from './pages/tenant/workshop/WorkspaceListPage';
import { WorkerListPage } from './pages/tenant/workshop/WorkerListPage';
import { WorkshopSchedulerPage } from './pages/tenant/workshop/WorkshopSchedulerPage';
import { WorkspaceReservationListPage } from './pages/tenant/workshop/WorkspaceReservationListPage';
import { WorkloadPage } from './pages/tenant/workshop/WorkloadPage';

import { ProductListPage } from './pages/tenant/inventory/ProductListPage';
import { ProductDetailPage } from './pages/tenant/inventory/ProductDetailPage';
import { WarehouseStockListPage } from './pages/tenant/inventory/WarehouseStockListPage';
import { StockTransferListPage } from './pages/tenant/inventory/StockTransferListPage';
import { StockTransferDetailPage } from './pages/tenant/inventory/StockTransferDetailPage';
import { StockOpnameListPage } from './pages/tenant/inventory/StockOpnameListPage';
import { StockOpnameDetailPage } from './pages/tenant/inventory/StockOpnameDetailPage';
import { StockMovementListPage } from './pages/tenant/inventory/StockMovementListPage';
import { UsedPartDispositionPage } from './pages/tenant/inventory/UsedPartDispositionPage';
import { ReturnListPage } from './pages/tenant/inventory/ReturnListPage';
import { SparePartSalePage } from './pages/tenant/inventory/SparePartSalePage';
import { PurchaseRequestListPage } from './pages/tenant/procurement/PurchaseRequestListPage';
import { PurchaseRequestDetailPage } from './pages/tenant/procurement/PurchaseRequestDetailPage';
import { NewRfqPage } from './pages/tenant/procurement/NewRfqPage';
import { RfqListPage } from './pages/tenant/procurement/RfqListPage';
import { RfqDetailPage } from './pages/tenant/procurement/RfqDetailPage';
import { VendorQuotationListPage } from './pages/tenant/procurement/VendorQuotationListPage';
import { CreatePurchaseOrderFromQuotationPage } from './pages/tenant/procurement/CreatePurchaseOrderFromQuotationPage';
import { PurchaseOrderListPage } from './pages/tenant/procurement/PurchaseOrderListPage';
import { PurchaseOrderDetailPage } from './pages/tenant/procurement/PurchaseOrderDetailPage';
import { GoodsReceiptListPage } from './pages/tenant/procurement/GoodsReceiptListPage';
import { VendorInvoiceReferenceListPage } from './pages/tenant/procurement/VendorInvoiceReferenceListPage';
import { PartnerListPage } from './pages/tenant/partners/PartnerListPage';
import { PartnerDetailPage } from './pages/tenant/partners/PartnerDetailPage';
import { TireListPage } from './pages/tenant/tires/TireListPage';
import { TireDetailPage } from './pages/tenant/tires/TireDetailPage';
import { TireProductDetailPage } from './pages/tenant/tires/TireProductDetailPage';
import { TireOperationsLandingPage } from './pages/tenant/tires/operations/TireOperationsLandingPage';
import { TireOperationFormPage } from './pages/tenant/tires/operations/TireOperationFormPage';
import { UsedTireManagementPage } from './pages/tenant/tires/operations/UsedTireManagementPage';
import { TireHistoryPage } from './pages/tenant/tires/operations/TireHistoryPage';
import { TIRE_OPERATION_PERMISSIONS, USED_TIRE_PERMISSIONS } from './layouts/tenantNav';
import { UsedTireInspectionPage } from './pages/tenant/tires/inspection/UsedTireInspectionPage';
import { TireRuleProfilesPage } from './pages/tenant/tires/inspection/TireRuleProfilesPage';
import { WheelConfigurationListPage } from './pages/tenant/tires/WheelConfigurationListPage';
import { AddWheelConfigurationPage } from './pages/tenant/tires/wheel-configuration/AddWheelConfigurationPage';
import { WheelConfigurationDetailPage } from './pages/tenant/tires/wheel-configuration/WheelConfigurationDetailPage';
import { VehicleMappingPage } from './pages/tenant/tires/wheel-configuration/VehicleMappingPage';
import { RimsPage } from './pages/tenant/tires/RimsPage';
import { ComponentAssetListPage } from './pages/tenant/components/ComponentAssetListPage';
import { ComponentAssetDetailPage } from './pages/tenant/components/ComponentAssetDetailPage';
import { AnalyticsOverviewPage } from './pages/tenant/analytics/AnalyticsOverviewPage';
import { FleetAnalyticsPage } from './pages/tenant/analytics/FleetAnalyticsPage';
import { MaintenanceAnalyticsPage } from './pages/tenant/analytics/MaintenanceAnalyticsPage';
import { WorkOrderAnalyticsPage } from './pages/tenant/analytics/WorkOrderAnalyticsPage';
import { BreakdownAnalyticsPage } from './pages/tenant/analytics/BreakdownAnalyticsPage';
import { DowntimeAnalyticsPage } from './pages/tenant/analytics/DowntimeAnalyticsPage';
import { WorkshopAnalyticsPage } from './pages/tenant/analytics/WorkshopAnalyticsPage';
import { MechanicAnalyticsPage } from './pages/tenant/analytics/MechanicAnalyticsPage';
import { InventoryAnalyticsPage } from './pages/tenant/analytics/InventoryAnalyticsPage';
import { ProcurementAnalyticsPage } from './pages/tenant/analytics/ProcurementAnalyticsPage';
import { VendorAnalyticsPage } from './pages/tenant/analytics/VendorAnalyticsPage';
import { CostAnalyticsPage } from './pages/tenant/analytics/CostAnalyticsPage';
import { TireAnalyticsPage } from './pages/tenant/analytics/TireAnalyticsPage';
import { ComponentAnalyticsPage } from './pages/tenant/analytics/ComponentAnalyticsPage';
import { WarrantyAnalyticsPage } from './pages/tenant/analytics/WarrantyAnalyticsPage';
import { IntelligenceOverviewPage } from './pages/tenant/intelligence/IntelligenceOverviewPage';
import { VehicleIntelligencePage } from './pages/tenant/intelligence/VehicleIntelligencePage';
import { VehicleIntelligenceDetailPage } from './pages/tenant/intelligence/VehicleIntelligenceDetailPage';
import { ComponentIntelligencePage } from './pages/tenant/intelligence/ComponentIntelligencePage';
import { TireIntelligencePage } from './pages/tenant/intelligence/TireIntelligencePage';
import { RecommendationsPage } from './pages/tenant/intelligence/RecommendationsPage';

function RootRedirect() {
  const { user, loading } = useAuth();
  if (loading) return <LoadingState />;
  if (!user) return <Navigate to="/login" replace />;
  return <Navigate to={user.scope === 'platform' ? '/platform/dashboard' : '/app/dashboard'} replace />;
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <NavigationTrailProvider>
        <BreadcrumbLabelProvider>
        <Routes>
          <Route path="/" element={<RootRedirect />} />
          <Route path="/login" element={<LoginPage />} />

          <Route
            path="/platform"
            element={
              <RequirePlatform>
                <PlatformLayout />
              </RequirePlatform>
            }
          >
            <Route path="dashboard" element={<PlatformDashboardPage />} />
            <Route
              path="tenants"
              element={
                <RequirePermission permission="tenant.view">
                  <TenantListPage />
                </RequirePermission>
              }
            />
            <Route
              path="tenants/:id"
              element={
                <RequirePermission permission="tenant.view">
                  <TenantDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="modules"
              element={
                <RequirePermission permission="module.view">
                  <ModuleCatalogPage />
                </RequirePermission>
              }
            />
            <Route
              path="modules/:id"
              element={
                <RequirePermission permission="module.view">
                  <ModuleDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="product-categories"
              element={
                <RequirePermission permission="product_category.view">
                  <PlatformProductCategoriesPage />
                </RequirePermission>
              }
            />
            <Route
              path="component-groups"
              element={
                <RequirePermission permission="component_group.view">
                  <PlatformComponentGroupsPage />
                </RequirePermission>
              }
            />
            <Route
              path="component-categories"
              element={
                <RequirePermission permission="component_category.view">
                  <PlatformComponentCategoriesPage />
                </RequirePermission>
              }
            />
            <Route
              path="component-subcategories"
              element={
                <RequirePermission permission="component_subcategory.view">
                  <PlatformComponentSubcategoriesPage />
                </RequirePermission>
              }
            />
            <Route
              path="access/users"
              element={
                <RequirePermission permission="user.view">
                  <PlatformUsersPage />
                </RequirePermission>
              }
            />
            <Route
              path="access/roles"
              element={
                <RequirePermission permission="role.view">
                  <PlatformRolesPage />
                </RequirePermission>
              }
            />
            <Route
              path="audit-logs"
              element={
                <RequirePermission permission="audit.view">
                  <PlatformAuditLogPage />
                </RequirePermission>
              }
            />
            <Route
              path="bundles"
              element={
                <RequirePermission permission="bundle.view">
                  <BundleListPage />
                </RequirePermission>
              }
            />
            <Route
              path="bundles/:id"
              element={
                <RequirePermission permission="bundle.view">
                  <BundleDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="pricing"
              element={
                <RequirePermission permission="pricing.view">
                  <PricingListPage />
                </RequirePermission>
              }
            />
            <Route
              path="contracts"
              element={
                <RequirePermission permission="contract.view">
                  <ContractListPage />
                </RequirePermission>
              }
            />
            <Route
              path="contracts/:id"
              element={
                <RequirePermission permission="contract.view">
                  <ContractDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="subscriptions"
              element={
                <RequirePermission permission="subscription.view">
                  <SubscriptionListPage />
                </RequirePermission>
              }
            />
            <Route
              path="billings"
              element={
                <RequirePermission permission="billing.view">
                  <BillingListPage />
                </RequirePermission>
              }
            />
            <Route
              path="invoices"
              element={
                <RequirePermission permission="invoice.view">
                  <InvoiceListPage />
                </RequirePermission>
              }
            />
            <Route
              path="invoices/:id"
              element={
                <RequirePermission permission="invoice.view">
                  <InvoiceDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="payments"
              element={
                <RequirePermission permission="payment.view">
                  <PaymentListPage />
                </RequirePermission>
              }
            />
            <Route
              path="payments/:id"
              element={
                <RequirePermission permission="payment.view">
                  <PaymentDetailPage />
                </RequirePermission>
              }
            />
          </Route>

          <Route
            path="/app"
            element={
              <RequireTenant>
                <TenantLayout />
              </RequireTenant>
            }
          >
            <Route path="dashboard" element={<TenantDashboardPage />} />
            <Route
              path="vehicles"
              element={
                <RequirePermission permission="vehicle.view">
                  <VehicleListPage />
                </RequirePermission>
              }
            />
            <Route
              path="vehicles/:id"
              element={
                <RequirePermission permission="vehicle.view">
                  <VehicleDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="vehicle-transfers"
              element={
                <RequirePermission permission="vehicle.transfer">
                  <VehicleTransferListPage />
                </RequirePermission>
              }
            />
            <Route
              path="maintenance-history"
              element={
                <RequirePermission permission="maintenance_history.view">
                  <MaintenanceHistoryPage />
                </RequirePermission>
              }
            />
            <Route
              path="vehicle-history"
              element={
                <RequirePermission permission="maintenance_history.view">
                  <VehicleHistoryPage />
                </RequirePermission>
              }
            />
            <Route
              path="inspections"
              element={
                <RequirePermission permission="inspection.view">
                  <InspectionListPage />
                </RequirePermission>
              }
            />
            <Route
              path="inspections/:id"
              element={
                <RequirePermission permission="inspection.view">
                  <InspectionDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="inspection-templates"
              element={
                <RequirePermission permission="inspection.view">
                  <InspectionTemplateListPage />
                </RequirePermission>
              }
            />
            <Route
              path="maintenance-schedules"
              element={
                <RequirePermission permission="maintenance_schedule.view">
                  <MaintenanceSchedulePage />
                </RequirePermission>
              }
            />
            <Route
              path="maintenance-policies"
              element={
                <RequirePermission permission="maintenance_policy.view">
                  <MaintenancePackagesPage />
                </RequirePermission>
              }
            />
            <Route
              path="maintenance-policies/:id"
              element={
                <RequirePermission permission="maintenance_policy.view">
                  <MaintenancePackageDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="maintenance-requests"
              element={
                <RequirePermission permission="maintenance_request.view">
                  <MaintenanceRequestListPage />
                </RequirePermission>
              }
            />
            <Route
              path="maintenance-requests/:id"
              element={
                <RequirePermission permission="maintenance_request.view">
                  <MaintenanceRequestDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="breakdowns"
              element={
                <RequirePermission permission="breakdown.view">
                  <BreakdownListPage />
                </RequirePermission>
              }
            />
            <Route
              path="breakdowns/:id"
              element={
                <RequirePermission permission="breakdown.view">
                  <BreakdownDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="work-orders"
              element={
                <RequirePermission permission="work_order.view">
                  <WorkOrderListPage />
                </RequirePermission>
              }
            />
            <Route
              path="work-orders/:id"
              element={
                <RequirePermission permission="work_order.view">
                  <WorkOrderDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="part-requests"
              element={
                <RequirePermission permission="part_request.view">
                  <PartRequestListPage />
                </RequirePermission>
              }
            />
            {/* The standalone Workshop Invoices list was retired: third-party Service Invoices (domain: WorkshopInvoice) are reached from their
                Work Order (External Services tab). Old links/bookmarks land on the Work Order list. */}
            <Route path="workshop-invoices" element={<Navigate to="/app/work-orders" replace />} />
            <Route
              path="external-work-order-invoices"
              element={
                <RequirePermission permission="external_work_order_invoice.view">
                  <ExternalWorkOrderInvoiceListPage />
                </RequirePermission>
              }
            />
            <Route
              path="workshop-invoices/:id"
              element={
                <RequirePermission permission="workshop_invoice.view">
                  <WorkshopInvoiceDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="workspaces"
              element={
                <RequirePermission permission="workspace.view">
                  <WorkspaceListPage />
                </RequirePermission>
              }
            />
            <Route
              path="workers"
              element={
                <RequirePermission permission="worker.view">
                  <WorkerListPage />
                </RequirePermission>
              }
            />
            <Route
              path="workshop-scheduler"
              element={
                <RequirePermission permission="workspace.view">
                  <WorkshopSchedulerPage />
                </RequirePermission>
              }
            />
            <Route
              path="workspace-reservations"
              element={
                <RequirePermission permission="workspace.view">
                  <WorkspaceReservationListPage />
                </RequirePermission>
              }
            />
            <Route
              path="workers/workload"
              element={
                <RequirePermission permission="worker.view">
                  <WorkloadPage />
                </RequirePermission>
              }
            />
            <Route
              path="organization/branches"
              element={
                <RequirePermission permission="branch.view">
                  <BranchesPage />
                </RequirePermission>
              }
            />
            <Route
              path="organization/workshops"
              element={
                <RequirePermission permission="workshop.view">
                  <WorkshopsPage />
                </RequirePermission>
              }
            />
            <Route
              path="organization/warehouses"
              element={
                <RequirePermission permission="warehouse.view">
                  <WarehousesPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/vehicle-categories"
              element={
                <RequirePermission permission="vehicle_category.view">
                  <VehicleCategoriesPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/component-groups"
              element={
                <RequirePermission permission="component_group.view">
                  <ComponentGroupsPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/component-categories"
              element={
                <RequirePermission permission="component_category.view">
                  <ComponentCategoriesPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/component-subcategories"
              element={
                <RequirePermission permission="component_subcategory.view">
                  <ComponentSubcategoriesPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/uoms"
              element={
                <RequirePermission permission="product.view">
                  <UomsPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/vehicle-brands"
              element={
                <RequirePermission permission="vehicle_brand.view">
                  <VehicleBrandsPage />
                </RequirePermission>
              }
            />
            <Route
              path="master-data/vehicle-models"
              element={
                <RequirePermission permission="vehicle_brand.view">
                  <VehicleModelsPage />
                </RequirePermission>
              }
            />
            <Route
              path="access/users"
              element={
                <RequirePermission permission="user.view">
                  <TenantUsersPage />
                </RequirePermission>
              }
            />
            <Route
              path="access/roles"
              element={
                <RequirePermission permission="role.view">
                  <TenantRolesPage />
                </RequirePermission>
              }
            />
            <Route
              path="audit-logs"
              element={
                <RequirePermission permission="audit.view">
                  <TenantAuditLogPage />
                </RequirePermission>
              }
            />
            <Route
              path="configuration/numbering"
              element={
                <RequirePermission permission="configuration.view">
                  <NumberingConfigPage />
                </RequirePermission>
              }
            />
            <Route
              path="configuration/document-templates"
              element={
                <RequirePermission permission="configuration.view">
                  <DocumentTemplateConfigPage />
                </RequirePermission>
              }
            />
            <Route
              path="configuration/workflows"
              element={
                <RequirePermission permission="configuration.view">
                  <WorkflowConfigPage />
                </RequirePermission>
              }
            />
            <Route
              path="configuration/notifications"
              element={
                <RequirePermission permission="configuration.view">
                  <NotificationRulesPage />
                </RequirePermission>
              }
            />
            <Route
              path="configuration/tire-scoring"
              element={
                <RequirePermission permission="configuration.view">
                  <TireScoringConfigPage />
                </RequirePermission>
              }
            />
            <Route
              path="configuration/history"
              element={
                <RequirePermission permission="configuration_history.view">
                  <ConfigurationHistoryPage />
                </RequirePermission>
              }
            />
            <Route
              path="account/subscription"
              element={
                <RequirePermission permission="account.subscription.view">
                  <AccountSubscriptionPage />
                </RequirePermission>
              }
            />
            <Route
              path="account/contract"
              element={
                <RequirePermission permission="account.contract.view">
                  <AccountContractPage />
                </RequirePermission>
              }
            />
            <Route
              path="account/company"
              element={
                <RequirePermission permission="company.view">
                  <CompanyProfilePage />
                </RequirePermission>
              }
            />
            <Route
              path="account/invoices"
              element={
                <RequirePermission permission="account.invoice.view">
                  <AccountInvoiceListPage />
                </RequirePermission>
              }
            />
            <Route
              path="account/invoices/:id"
              element={
                <RequirePermission permission="account.invoice.view">
                  <AccountInvoiceDetailPage />
                </RequirePermission>
              }
            />
            <Route
              path="account/payments"
              element={
                <RequirePermission permission="account.payment.view">
                  <AccountPaymentListPage />
                </RequirePermission>
              }
            />
            <Route
              path="account/payments/:id"
              element={
                <RequirePermission permission="account.payment.view">
                  <AccountPaymentDetailPage />
                </RequirePermission>
              }
            />

            <Route path="products" element={<RequirePermission permission="product.view"><ProductListPage /></RequirePermission>} />
            <Route path="products/:id" element={<RequirePermission permission="product.view"><ProductDetailPage /></RequirePermission>} />
            <Route path="inventory" element={<RequirePermission permission="inventory.view"><WarehouseStockListPage /></RequirePermission>} />
            <Route path="stock-transfers" element={<RequirePermission permission="stock_transfer.view"><StockTransferListPage /></RequirePermission>} />
            <Route path="stock-transfers/:id" element={<RequirePermission permission="stock_transfer.view"><StockTransferDetailPage /></RequirePermission>} />
            <Route path="stock-opnames" element={<RequirePermission permission="inventory.stock_opname"><StockOpnameListPage /></RequirePermission>} />
            <Route path="stock-opnames/:id" element={<RequirePermission permission="inventory.stock_opname"><StockOpnameDetailPage /></RequirePermission>} />
            <Route path="stock-movements" element={<RequirePermission permission="inventory.view"><StockMovementListPage /></RequirePermission>} />
            <Route path="returns" element={<RequirePermission permission="part_return.view"><ReturnListPage /></RequirePermission>} />
            <Route path="used-part-returns" element={<RequirePermission permission="used_part.view"><UsedPartDispositionPage /></RequirePermission>} />
            <Route path="sparepart-sales" element={<RequirePermission permission="sparepart_sale.view"><SparePartSalePage /></RequirePermission>} />

            <Route path="purchase-requests" element={<RequirePermission permission="purchase_request.view"><PurchaseRequestListPage /></RequirePermission>} />
            <Route path="purchase-requests/:id" element={<RequirePermission permission="purchase_request.view"><PurchaseRequestDetailPage /></RequirePermission>} />
            <Route path="rfqs" element={<RequirePermission permission="rfq.view"><RfqListPage /></RequirePermission>} />
            <Route path="rfqs/new" element={<RequirePermission permission="rfq.manage"><NewRfqPage /></RequirePermission>} />
            <Route path="rfqs/:id" element={<RequirePermission permission="rfq.view"><RfqDetailPage /></RequirePermission>} />
            <Route path="quotations" element={<RequirePermission permission="quotation.view"><VendorQuotationListPage /></RequirePermission>} />
            <Route path="quotations/:quotationId/create-po" element={<RequirePermission permission="purchase_order.create"><CreatePurchaseOrderFromQuotationPage /></RequirePermission>} />
            <Route path="purchase-orders" element={<RequirePermission permission="purchase_order.view"><PurchaseOrderListPage /></RequirePermission>} />
            <Route path="purchase-orders/:id" element={<RequirePermission permission="purchase_order.view"><PurchaseOrderDetailPage /></RequirePermission>} />
            <Route path="goods-receipts" element={<RequirePermission permission="goods_receipt.view"><GoodsReceiptListPage /></RequirePermission>} />
            <Route path="vendor-invoice-references" element={<RequirePermission permission="vendor_invoice.view"><VendorInvoiceReferenceListPage /></RequirePermission>} />

            <Route path="partners" element={<RequirePermission permission="partner.view"><PartnerListPage /></RequirePermission>} />
            {/* Supplier is a Vendor (partner) type, not a separate entity: the old Suppliers page is the Vendor list filtered to supplier types. */}
            <Route path="suppliers" element={<Navigate to="/app/partners?type=SUPPLIERS" replace />} />
            <Route path="partners/:id" element={<RequirePermission permission="partner.view"><PartnerDetailPage /></RequirePermission>} />

            <Route path="tires" element={<RequirePermission permission="tire.view"><TireListPage /></RequirePermission>} />
            <Route path="tires/products" element={<Navigate to="/app/tires" replace />} />
            <Route path="tires/products/:productId" element={<RequirePermission permission="tire.view"><TireProductDetailPage /></RequirePermission>} />
            <Route path="tires/:id" element={<RequirePermission permission="tire.view"><TireDetailPage /></RequirePermission>} />
            <Route path="tires/:id/inspection" element={<RequirePermission permission="tire.view"><UsedTireInspectionPage /></RequirePermission>} />
            <Route path="tire-inspection-rules" element={<RequirePermission permission="tire.view"><TireRuleProfilesPage /></RequirePermission>} />
            <Route path="tire-operations" element={<RequirePermission permission={TIRE_OPERATION_PERMISSIONS}><TireOperationsLandingPage /></RequirePermission>} />
            <Route path="tire-operations/new" element={<RequirePermission permission={TIRE_OPERATION_PERMISSIONS}><TireOperationFormPage /></RequirePermission>} />
            <Route path="tire-operations/:id/edit" element={<RequirePermission permission={TIRE_OPERATION_PERMISSIONS}><TireOperationFormPage /></RequirePermission>} />
            {/* ORPHANED (owner decision): the earlier tabbed Installation / Rotation / Inspection page (TireOperationsPage) is no longer routed; its source and the tire APIs it used are kept. */}
            <Route path="used-tires" element={<RequirePermission permission={USED_TIRE_PERMISSIONS}><UsedTireManagementPage /></RequirePermission>} />
            <Route path="tire-history" element={<RequirePermission permission="tire.view"><TireHistoryPage /></RequirePermission>} />
            <Route path="wheel-configurations" element={<RequirePermission permission="tire.view"><WheelConfigurationListPage /></RequirePermission>} />
            {/* Prototype (owner review): Passenger Car wheel configuration builder — not persisted yet. */}
            <Route path="wheel-configurations/new" element={<RequirePermission permission="tire.manage"><AddWheelConfigurationPage /></RequirePermission>} />
            <Route path="wheel-configurations/:id/edit" element={<RequirePermission permission="tire.manage"><AddWheelConfigurationPage /></RequirePermission>} />
            <Route path="wheel-configurations/:id" element={<RequirePermission permission="tire.view"><WheelConfigurationDetailPage /></RequirePermission>} />
            <Route path="wheel-configurations/:id/vehicle-mapping" element={<RequirePermission permission="tire.view"><VehicleMappingPage /></RequirePermission>} />
            <Route path="rims" element={<RequirePermission permission="rim.view"><RimsPage /></RequirePermission>} />

            <Route path="component-assets" element={<RequirePermission permission="component_asset.view"><ComponentAssetListPage /></RequirePermission>} />
            <Route path="component-assets/:id" element={<RequirePermission permission="component_asset.view"><ComponentAssetDetailPage /></RequirePermission>} />

            {/* ORPHANED (owner decision): Warranty / Eligibility / Claims pages are not routed in the active UI; source in pages/tenant/warranty, APIs and data kept. */}

            <Route path="analytics/overview" element={<RequirePermission permission="analytics.overview.view"><AnalyticsOverviewPage /></RequirePermission>} />
            <Route path="analytics/fleet" element={<RequirePermission permission="analytics.fleet.view"><FleetAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/maintenance" element={<RequirePermission permission="analytics.maintenance.view"><MaintenanceAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/work-orders" element={<RequirePermission permission="analytics.work_order.view"><WorkOrderAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/breakdowns" element={<RequirePermission permission="analytics.breakdown.view"><BreakdownAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/downtime" element={<RequirePermission permission="analytics.breakdown.view"><DowntimeAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/workshops" element={<RequirePermission permission="analytics.workshop.view"><WorkshopAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/mechanics" element={<RequirePermission permission="analytics.mechanic.view"><MechanicAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/inventory" element={<RequirePermission permission="analytics.inventory.view"><InventoryAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/procurement" element={<RequirePermission permission="analytics.procurement.view"><ProcurementAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/vendors" element={<RequirePermission permission="analytics.vendor.view"><VendorAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/cost" element={<RequirePermission permission="analytics.cost.view"><CostAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/tires" element={<RequirePermission permission="analytics.tire.view"><TireAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/components" element={<RequirePermission permission="analytics.component.view"><ComponentAnalyticsPage /></RequirePermission>} />
            <Route path="analytics/warranty" element={<RequirePermission permission="analytics.warranty.view"><WarrantyAnalyticsPage /></RequirePermission>} />
            <Route path="intelligence/overview" element={<RequirePermission permission="intelligence.overview.view"><IntelligenceOverviewPage /></RequirePermission>} />
            <Route path="intelligence/vehicles" element={<RequirePermission permission="intelligence.vehicle.view"><VehicleIntelligencePage /></RequirePermission>} />
            <Route path="intelligence/vehicles/:id" element={<RequirePermission permission="intelligence.vehicle.view"><VehicleIntelligenceDetailPage /></RequirePermission>} />
            <Route path="intelligence/components" element={<RequirePermission permission="intelligence.component.view"><ComponentIntelligencePage /></RequirePermission>} />
            <Route path="intelligence/tires" element={<RequirePermission permission="intelligence.tire.view"><TireIntelligencePage /></RequirePermission>} />
            <Route path="intelligence/recommendations" element={<RequirePermission permission="intelligence.recommendation.view"><RecommendationsPage /></RequirePermission>} />
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
        </BreadcrumbLabelProvider>
        </NavigationTrailProvider>
      </AuthProvider>
    </BrowserRouter>
  );
}
