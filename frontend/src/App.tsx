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

import { TenantDashboardPage } from './pages/tenant/DashboardPage';
import { BranchesPage } from './pages/tenant/organization/BranchesPage';
import { WorkshopsPage } from './pages/tenant/organization/WorkshopsPage';
import { WarehousesPage } from './pages/tenant/organization/WarehousesPage';
import { VehicleCategoriesPage } from './pages/tenant/masterdata/VehicleCategoriesPage';
import { ComponentGroupsPage } from './pages/tenant/masterdata/ComponentGroupsPage';
import { TenantUsersPage } from './pages/tenant/access/TenantUsersPage';
import { TenantRolesPage } from './pages/tenant/access/TenantRolesPage';
import { TenantAuditLogPage } from './pages/tenant/audit/TenantAuditLogPage';
import { LoadingState } from './components/States';

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
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
}
