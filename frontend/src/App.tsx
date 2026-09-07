import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthContext';
import { RequirePermission, RequirePlatform, RequireTenant } from './components/RouteGuards';
import { PlatformLayout } from './layouts/PlatformLayout';
import { TenantLayout } from './layouts/TenantLayout';
import { LoginPage } from './pages/LoginPage';

import { PlatformDashboardPage } from './pages/platform/DashboardPage';
import { TenantListPage } from './pages/platform/tenants/TenantListPage';
import { TenantDetailPage } from './pages/platform/tenants/TenantDetailPage';
import { ModuleCatalogPage } from './pages/platform/modules/ModuleCatalogPage';
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
import { TenantUsersPage } from './pages/tenant/access/TenantUsersPage';
import { TenantRolesPage } from './pages/tenant/access/TenantRolesPage';
import { TenantAuditLogPage } from './pages/tenant/audit/TenantAuditLogPage';
import { AccountSubscriptionPage } from './pages/tenant/account/AccountSubscriptionPage';
import { AccountContractPage } from './pages/tenant/account/AccountContractPage';
import { AccountInvoiceListPage } from './pages/tenant/account/AccountInvoiceListPage';
import { AccountInvoiceDetailPage } from './pages/tenant/account/AccountInvoiceDetailPage';
import { AccountPaymentListPage } from './pages/tenant/account/AccountPaymentListPage';
import { AccountPaymentDetailPage } from './pages/tenant/account/AccountPaymentDetailPage';
import { LoadingState } from './components/States';

import { VehicleListPage } from './pages/tenant/vehicles/VehicleListPage';
import { VehicleDetailPage } from './pages/tenant/vehicles/VehicleDetailPage';
import { VehicleTransferListPage } from './pages/tenant/vehicles/VehicleTransferListPage';
import { VehicleHistoryPage } from './pages/tenant/vehicles/VehicleHistoryPage';
import { InspectionListPage } from './pages/tenant/inspections/InspectionListPage';
import { InspectionDetailPage } from './pages/tenant/inspections/InspectionDetailPage';
import { InspectionTemplateListPage } from './pages/tenant/inspections/InspectionTemplateListPage';
import { MaintenanceSchedulePage } from './pages/tenant/maintenance/MaintenanceSchedulePage';
import { MaintenanceRequestListPage } from './pages/tenant/maintenance/MaintenanceRequestListPage';
import { MaintenanceRequestDetailPage } from './pages/tenant/maintenance/MaintenanceRequestDetailPage';
import { BreakdownListPage } from './pages/tenant/maintenance/BreakdownListPage';
import { BreakdownDetailPage } from './pages/tenant/maintenance/BreakdownDetailPage';
import { WorkOrderListPage } from './pages/tenant/workorders/WorkOrderListPage';
import { WorkOrderDetailPage } from './pages/tenant/workorders/WorkOrderDetailPage';
import { WorkspaceListPage } from './pages/tenant/workshop/WorkspaceListPage';
import { WorkerListPage } from './pages/tenant/workshop/WorkerListPage';
import { WorkshopSchedulerPage } from './pages/tenant/workshop/WorkshopSchedulerPage';
import { WorkspaceReservationListPage } from './pages/tenant/workshop/WorkspaceReservationListPage';
import { WorkloadPage } from './pages/tenant/workshop/WorkloadPage';

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
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
