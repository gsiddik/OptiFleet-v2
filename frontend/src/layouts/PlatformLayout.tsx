import { NavLink, Outlet } from 'react-router-dom';
import { LanguageSelector } from '../components/LanguageSelector';
import { useAuth } from '../auth/AuthContext';
import { Breadcrumb } from '../components/Breadcrumb';
import { Logo } from '../components/Logo';
import { t } from '../i18n/i18n';

const NAV = [
  { to: '/platform/dashboard', labelKey: 'nav.items.dashboard', permission: null },
  { to: '/platform/tenants', labelKey: 'nav.items.tenantManagement', permission: 'tenant.view' },
  { to: '/platform/modules', labelKey: 'nav.items.moduleCatalog', permission: 'module.view' },
  { to: '/platform/product-categories', labelKey: 'nav.items.productCategories', permission: 'product_category.view' },
  { to: '/platform/component-groups', labelKey: 'nav.items.componentGroups', permission: 'component_group.view' },
  { to: '/platform/component-categories', labelKey: 'nav.items.componentCategories', permission: 'component_category.view' },
  { to: '/platform/component-subcategories', labelKey: 'nav.items.componentSubcategories', permission: 'component_subcategory.view' },
  { to: '/platform/bundles', labelKey: 'nav.items.bundles', permission: 'bundle.view' },
  { to: '/platform/pricing', labelKey: 'nav.items.pricing', permission: 'pricing.view' },
  { to: '/platform/contracts', labelKey: 'nav.items.contracts', permission: 'contract.view' },
  { to: '/platform/subscriptions', labelKey: 'nav.items.subscriptions', permission: 'subscription.view' },
  { to: '/platform/billings', labelKey: 'nav.items.billing', permission: 'billing.view' },
  { to: '/platform/invoices', labelKey: 'nav.items.invoices', permission: 'invoice.view' },
  { to: '/platform/payments', labelKey: 'nav.items.payments', permission: 'payment.view' },
  { to: '/platform/access/users', labelKey: 'nav.items.platformUsers', permission: 'user.view' },
  { to: '/platform/access/roles', labelKey: 'nav.items.platformRoles', permission: 'role.view' },
  { to: '/platform/audit-logs', labelKey: 'nav.items.auditLog', permission: 'audit.view' },
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
          {t('common.fields.platformPortal')}
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
              {t(item.labelKey)}
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
          <LanguageSelector compact />
          <span style={{ fontSize: 14, color: '#374151' }}>{user?.name}</span>
          <button className="btn-secondary" onClick={() => logout()}>
            {t('common.actions.logout')}
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
