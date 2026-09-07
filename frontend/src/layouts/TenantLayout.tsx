import { useEffect, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { apiClient } from '../api/client';
import { useAuth } from '../auth/AuthContext';

interface NavItem {
  to: string;
  label: string;
  permission: string | null;
  module: string | null;
}
interface NavGroup {
  label: string | null;
  items: NavItem[];
}

const NAV_GROUPS: NavGroup[] = [
  { label: null, items: [{ to: '/app/dashboard', label: 'Dashboard', permission: null, module: null }] },
  {
    label: 'Vehicle',
    items: [
      { to: '/app/vehicles', label: 'List', permission: 'vehicle.view', module: 'VEHICLE' },
      { to: '/app/vehicle-transfers', label: 'Transfer', permission: 'vehicle.transfer', module: 'VEHICLE' },
      { to: '/app/vehicle-history', label: 'History', permission: 'maintenance_history.view', module: 'VEHICLE' },
    ],
  },
  {
    label: 'Inspection',
    items: [
      { to: '/app/inspections', label: 'Inspections', permission: 'inspection.view', module: 'INSPECTION' },
      { to: '/app/inspection-templates', label: 'Templates', permission: 'inspection.view', module: 'INSPECTION' },
    ],
  },
  {
    label: 'Maintenance',
    items: [
      { to: '/app/maintenance-schedules', label: 'Planning & Schedule', permission: 'maintenance_schedule.view', module: 'MAINTENANCE' },
      { to: '/app/maintenance-requests', label: 'Maintenance Request', permission: 'maintenance_request.view', module: 'MAINTENANCE' },
      { to: '/app/work-orders', label: 'Work Order', permission: 'work_order.view', module: 'WORK_ORDER' },
      { to: '/app/breakdowns', label: 'Breakdown', permission: 'breakdown.view', module: 'MAINTENANCE' },
      { to: '/app/work-orders?status=QC_PENDING', label: 'Quality Control', permission: 'qc.view', module: 'WORK_ORDER' },
    ],
  },
  {
    label: 'Workshop Operations',
    items: [
      { to: '/app/workspaces', label: 'Workspace', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workshop-scheduler', label: 'Scheduler', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workers', label: 'Mechanic', permission: 'worker.view', module: 'WORKSHOP' },
      { to: '/app/workspace-reservations', label: 'Assignment', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workers/workload', label: 'Workload', permission: 'worker.view', module: 'WORKSHOP' },
    ],
  },
  {
    label: 'History',
    items: [{ to: '/app/vehicle-history', label: 'Maintenance History', permission: 'maintenance_history.view', module: null }],
  },
  {
    label: 'Organization',
    items: [
      { to: '/app/organization/branches', label: 'Branches', permission: 'branch.view', module: 'ORGANIZATION' },
      { to: '/app/organization/workshops', label: 'Workshops', permission: 'workshop.view', module: 'ORGANIZATION' },
      { to: '/app/organization/warehouses', label: 'Warehouses', permission: 'warehouse.view', module: 'ORGANIZATION' },
    ],
  },
  {
    label: 'Master Data',
    items: [
      { to: '/app/master-data/vehicle-categories', label: 'Vehicle Categories', permission: 'vehicle_category.view', module: 'CORE' },
      { to: '/app/master-data/component-groups', label: 'Component Groups', permission: 'component_group.view', module: 'CORE' },
    ],
  },
  {
    label: 'Access',
    items: [
      { to: '/app/access/users', label: 'Users', permission: 'user.view', module: 'ACCESS_MANAGEMENT' },
      { to: '/app/access/roles', label: 'Roles', permission: 'role.view', module: 'ACCESS_MANAGEMENT' },
    ],
  },
  { label: null, items: [{ to: '/app/audit-logs', label: 'Audit Log', permission: 'audit.view', module: null }] },
];

const ACCOUNT_NAV = [
  { to: '/app/account/subscription', label: 'Subscription', permission: 'account.subscription.view' },
  { to: '/app/account/contract', label: 'Contract', permission: 'account.contract.view' },
  { to: '/app/account/invoices', label: 'Invoices', permission: 'account.invoice.view' },
  { to: '/app/account/payments', label: 'Payments', permission: 'account.payment.view' },
];

export function TenantLayout() {
  const { user, logout, hasPermission, activeTenantId, switchTenant } = useAuth();
  const [activeModules, setActiveModules] = useState<string[] | null>(null);
  const [subscriptionStatus, setSubscriptionStatus] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/dashboard')
      .then((res) => setActiveModules(res.data.data.active_modules))
      .catch(() => setActiveModules([]));

    apiClient
      .get('/app/account/subscription')
      .then((res) => setSubscriptionStatus(res.data.data?.status ?? null))
      .catch(() => setSubscriptionStatus(null));
  }, [activeTenantId]);

  const currentMembership = user?.memberships.find((m) => m.tenant_id === activeTenantId);

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside style={{ width: 230, background: '#111827', color: '#fff', padding: '20px 0', flexShrink: 0 }}>
        <div style={{ padding: '0 20px 20px', fontSize: 18, fontWeight: 700 }}>OptiFleet</div>
        <div style={{ padding: '0 20px 16px', fontSize: 11, textTransform: 'uppercase', color: '#9ca3af', letterSpacing: 1 }}>
          Tenant Portal
        </div>
        <nav>
          {NAV_GROUPS.map((group) => {
            const items = group.items
              .filter((item) => !item.permission || hasPermission(item.permission))
              .filter((item) => !item.module || activeModules === null || activeModules.includes(item.module));
            if (items.length === 0) return null;
            return (
              <div key={group.label ?? items[0].to}>
                {group.label && (
                  <div style={{ padding: '14px 20px 4px', fontSize: 11, textTransform: 'uppercase', color: '#6b7280', letterSpacing: 1 }}>
                    {group.label}
                  </div>
                )}
                {items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    style={({ isActive }) => ({
                      display: 'block',
                      padding: '8px 20px',
                      paddingLeft: group.label ? 28 : 20,
                      color: isActive ? '#fff' : '#cbd5e1',
                      background: isActive ? '#1d4ed8' : 'transparent',
                      textDecoration: 'none',
                      fontSize: 14,
                    })}
                  >
                    {item.label}
                  </NavLink>
                ))}
              </div>
            );
          })}
        </nav>
        <div style={{ padding: '16px 20px 8px', fontSize: 11, textTransform: 'uppercase', color: '#9ca3af', letterSpacing: 1 }}>Account</div>
        <nav>
          {ACCOUNT_NAV.filter((item) => hasPermission(item.permission)).map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              style={({ isActive }) => ({
                display: 'block',
                padding: '10px 20px',
                color: isActive ? '#fff' : '#cbd5e1',
                background: isActive ? '#1d4ed8' : 'transparent',
                textDecoration: 'none',
                fontSize: 14,
              })}
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
        <header
          style={{
            display: 'flex',
            justifyContent: 'flex-end',
            alignItems: 'center',
            gap: 16,
            padding: '12px 24px',
            background: '#fff',
            borderBottom: '1px solid #e5e7eb',
          }}
        >
          {user && user.memberships.length > 1 && (
            <select
              value={activeTenantId ?? ''}
              onChange={(e) => switchTenant(e.target.value)}
              style={{ padding: '6px 10px', borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
            >
              {user.memberships.map((m) => (
                <option key={m.tenant_id} value={m.tenant_id}>
                  {m.tenant_name}
                </option>
              ))}
            </select>
          )}
          <span style={{ fontSize: 13, color: '#6b7280' }}>{currentMembership?.tenant_name}</span>
          <span style={{ fontSize: 14, color: '#374151' }}>{user?.name}</span>
          <button className="btn-secondary" onClick={() => logout()}>
            Logout
          </button>
        </header>
        {subscriptionStatus === 'SUSPENDED' && (
          <div style={{ background: '#b91c1c', color: '#fff', padding: '10px 24px', fontSize: 13, textAlign: 'center' }}>
            Your subscription is suspended due to an outstanding payment. Operational features are restricted — visit Account →
            Payments to resolve it.
          </div>
        )}
        {['GRACE_PERIOD', 'PAST_DUE'].includes(subscriptionStatus ?? '') && (
          <div style={{ background: '#fffbeb', color: '#a16207', padding: '10px 24px', fontSize: 13, textAlign: 'center', borderBottom: '1px solid #fde68a' }}>
            Your account has an outstanding balance. Please settle it soon to avoid suspension — see Account → Payments.
          </div>
        )}
        <main style={{ flex: 1, padding: 24 }}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
