import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { Breadcrumb } from '../components/Breadcrumb';
import { Logo } from '../components/Logo';

const NAV = [
  { to: '/platform/dashboard', label: 'Dashboard', permission: null },
  { to: '/platform/tenants', label: 'Tenant Management', permission: 'tenant.view' },
  { to: '/platform/modules', label: 'Module Catalog', permission: 'module.view' },
  { to: '/platform/product-categories', label: 'Product Categories', permission: 'product_category.view' },
  { to: '/platform/component-groups', label: 'Component Groups', permission: 'component_group.view' },
  { to: '/platform/component-categories', label: 'Component Categories', permission: 'component_category.view' },
  { to: '/platform/component-subcategories', label: 'Component Subcategories', permission: 'component_subcategory.view' },
  { to: '/platform/bundles', label: 'Bundles', permission: 'bundle.view' },
  { to: '/platform/pricing', label: 'Pricing', permission: 'pricing.view' },
  { to: '/platform/contracts', label: 'Contracts', permission: 'contract.view' },
  { to: '/platform/subscriptions', label: 'Subscriptions', permission: 'subscription.view' },
  { to: '/platform/billings', label: 'Billing', permission: 'billing.view' },
  { to: '/platform/invoices', label: 'Invoices', permission: 'invoice.view' },
  { to: '/platform/payments', label: 'Payments', permission: 'payment.view' },
  { to: '/platform/access/users', label: 'Platform Users', permission: 'user.view' },
  { to: '/platform/access/roles', label: 'Platform Roles', permission: 'role.view' },
  { to: '/platform/audit-logs', label: 'Audit Log', permission: 'audit.view' },
];

export function PlatformLayout() {
  const { user, logout, hasPermission } = useAuth();

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside style={{ width: 230, background: '#111827', color: '#fff', padding: '20px 0', flexShrink: 0 }}>
        <div style={{ padding: '16px 20px 20px' }}>
          {/* Section 4a: the source logo's wordmark/tagline are dark text designed for a
              light background, so a white card keeps it legible on this dark sidebar
              without altering the logo asset itself. */}
          <div style={{ display: 'inline-block', background: '#fff', borderRadius: 8, padding: '10px 14px' }}>
            <Logo height={40} />
          </div>
        </div>
        <div style={{ padding: '0 20px 16px', fontSize: 11, textTransform: 'uppercase', color: '#9ca3af', letterSpacing: 1 }}>
          Platform Portal
        </div>
        <nav>
          {NAV.filter((item) => !item.permission || hasPermission(item.permission)).map((item) => (
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
          <span style={{ fontSize: 14, color: '#374151' }}>{user?.name}</span>
          <button className="btn-secondary" onClick={() => logout()}>
            Logout
          </button>
        </header>
        <main style={{ flex: 1, padding: 24 }}>
          <Breadcrumb />
          <Outlet />
        </main>
      </div>
    </div>
  );
}
