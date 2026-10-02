import { useEffect, useMemo, useState } from 'react';
import { Outlet } from 'react-router-dom';
import { apiClient } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { Breadcrumb } from '../components/Breadcrumb';
import { NavDropdown } from '../components/NavDropdown';
import { NAV_GROUPS, navItemAllowed } from './tenantNav';
import { TenantSidebar } from './TenantSidebar';

const MINIMIZED_KEY = 'optifleet_sidebar_minimized';

function readMinimized(): boolean {
  try {
    return localStorage.getItem(MINIMIZED_KEY) === '1';
  } catch {
    return false;
  }
}

/** Mobile uses the off-canvas drawer, which is always shown expanded. */
function useIsMobile(): boolean {
  const query = '(max-width: 768px)';
  const [mobile, setMobile] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const media = window.matchMedia(query);
    const onChange = (e: MediaQueryListEvent) => setMobile(e.matches);
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
  }, []);
  return mobile;
}

// Section 5.2/5.3/5.4: these three groups now render as navbar hover/click
// dropdowns (see the header below) instead of sidebar entries — same
// routes, same permissions, just relocated.
const ACCOUNT_NAV = [
  { to: '/app/account/company', label: 'Company Profile', permission: 'company.view' },
  { to: '/app/account/subscription', label: 'Subscription', permission: 'account.subscription.view' },
  { to: '/app/account/contract', label: 'Contract', permission: 'account.contract.view' },
  { to: '/app/account/invoices', label: 'Invoices', permission: 'account.invoice.view' },
  { to: '/app/account/payments', label: 'Payments', permission: 'account.payment.view' },
];

const ORGANIZATION_NAV = [
  { to: '/app/organization/branches', label: 'Branches', permission: 'branch.view' },
  { to: '/app/organization/workshops', label: 'Workshops', permission: 'workshop.view' },
  { to: '/app/organization/warehouses', label: 'Warehouses', permission: 'warehouse.view' },
];

const ACCESS_NAV = [
  { to: '/app/access/users', label: 'Users', permission: 'user.view' },
  { to: '/app/access/roles', label: 'Roles', permission: 'role.view' },
];

export function TenantLayout() {
  const { user, logout, hasPermission, activeTenantId, switchTenant } = useAuth();
  const [activeModules, setActiveModules] = useState<string[] | null>(null);
  const [subscriptionStatus, setSubscriptionStatus] = useState<string | null>(null);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [minimizedPref, setMinimizedPref] = useState(readMinimized);
  const isMobile = useIsMobile();
  const minimized = minimizedPref && !isMobile;

  function toggleMinimized() {
    setMinimizedPref((prev) => {
      try {
        localStorage.setItem(MINIMIZED_KEY, prev ? '0' : '1');
      } catch {
        // storage unavailable — the preference just isn't remembered
      }
      return !prev;
    });
  }

  // Permission + active-module filtering first; search and minimize only work on what remains.
  const navGroups = useMemo(
    () =>
      NAV_GROUPS.map((group) => ({
        ...group,
        items: group.items
          .filter((item) => navItemAllowed(item, hasPermission))
          .filter((item) => !item.module || activeModules === null || activeModules.includes(item.module)),
      })).filter((group) => group.items.length > 0),
    [hasPermission, activeModules],
  );

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
  const tenantLogoUrl = currentMembership?.tenant_logo_url ?? null;

  // Section: tenant logo upload — the favicon `<link>` tags in index.html
  // are static and can't carry an Authorization header, so this swaps them
  // to the tenant's public logo URL once known, and restores the default
  // OptiFleet favicon on unmount (e.g. when logging out to the login page).
  useEffect(() => {
    const icons = document.querySelectorAll<HTMLLinkElement>('link[rel="icon"]');
    if (icons.length === 0) return;
    const originalHrefs = Array.from(icons).map((icon) => icon.href);
    if (tenantLogoUrl) {
      icons.forEach((icon) => {
        icon.href = tenantLogoUrl;
      });
    }
    return () => {
      icons.forEach((icon, i) => {
        icon.href = originalHrefs[i];
      });
    };
  }, [tenantLogoUrl]);

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <div className={`tenant-sidebar-overlay${sidebarOpen ? ' open' : ''}`} onClick={() => setSidebarOpen(false)} />
      <TenantSidebar
        groups={navGroups}
        minimized={minimized}
        onToggleMinimized={toggleMinimized}
        mobileOpen={sidebarOpen}
        onNavigate={() => setSidebarOpen(false)}
        logoSrc={tenantLogoUrl}
      />
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0 }}>
        <header
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            gap: 16,
            padding: '12px 24px',
            background: '#fff',
            borderBottom: '1px solid #e5e7eb',
            flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', rowGap: 6, columnGap: 4, minWidth: 0 }}>
            <button
              className="tenant-mobile-toggle btn-secondary"
              aria-label="Toggle menu"
              onClick={() => setSidebarOpen((v) => !v)}
              style={{ marginRight: 8 }}
            >
              ☰ Menu
            </button>
            {/* Section 5.1: tenant name moved from the right side of the navbar to here, on the left. */}
            <span
              title={currentMembership?.tenant_name}
              style={{
                fontSize: 14,
                fontWeight: 600,
                color: '#111827',
                marginRight: 8,
                flexShrink: 0,
                minWidth: 60,
                maxWidth: 220,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}
            >
              {currentMembership?.tenant_name}
            </span>
            <NavDropdown label="Account" items={ACCOUNT_NAV.filter((item) => hasPermission(item.permission))} />
            <NavDropdown label="Organization" items={ORGANIZATION_NAV.filter((item) => hasPermission(item.permission))} />
            <NavDropdown label="Access" items={ACCESS_NAV.filter((item) => hasPermission(item.permission))} />
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
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
            <span style={{ fontSize: 14, color: '#374151' }}>{user?.name}</span>
            <button className="btn-secondary" onClick={() => logout()}>
              Logout
            </button>
          </div>
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
        <main style={{ flex: 1, padding: 24, minWidth: 0 }}>
          <Breadcrumb />
          <Outlet />
        </main>
      </div>
    </div>
  );
}
