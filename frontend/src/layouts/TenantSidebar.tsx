import { useEffect, useMemo, useRef, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { Logo } from '../components/Logo';
import { NavIcon } from '../components/NavIcon';
import { searchNav, type NavGroup, type NavItem } from './tenantNav';

const EXPANDED_KEY = 'optifleet_nav_expanded';

function readExpanded(): Record<string, boolean> {
  try {
    return JSON.parse(sessionStorage.getItem(EXPANDED_KEY) ?? '{}') as Record<string, boolean>;
  } catch {
    return {};
  }
}

function itemPath(item: NavItem): string {
  return item.to.split('?')[0];
}

const linkStyle = (active: boolean, indent: number) => ({
  display: 'flex',
  alignItems: 'center',
  gap: 10,
  padding: `7px 14px 7px ${indent}px`,
  margin: '1px 8px',
  borderRadius: 6,
  color: active ? '#fff' : '#cbd5e1',
  background: active ? '#1d4ed8' : 'transparent',
  textDecoration: 'none',
  fontSize: 14,
  lineHeight: 1.3,
});

/**
 * Tenant sidebar: search, collapsible groups, minimize (icons only, hover/focus flyout with the
 * group's menu) and maximize. It scrolls internally — the logo / controls stay put and the last
 * menu item is always reachable. On mobile it is the existing off-canvas drawer, always expanded.
 */
export function TenantSidebar({
  groups,
  minimized,
  onToggleMinimized,
  mobileOpen,
  onNavigate,
  logoSrc,
}: {
  groups: NavGroup[];
  minimized: boolean;
  onToggleMinimized: () => void;
  mobileOpen: boolean;
  onNavigate: () => void;
  logoSrc: string | null;
}) {
  const { pathname } = useLocation();
  const [query, setQuery] = useState('');
  const [expanded, setExpanded] = useState<Record<string, boolean>>(readExpanded);
  const [flyout, setFlyout] = useState<{ label: string; top: number } | null>(null);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const focusSearchAfterExpand = useRef(false);

  useEffect(() => {
    try {
      sessionStorage.setItem(EXPANDED_KEY, JSON.stringify(expanded));
    } catch {
      // storage unavailable — expand state just isn't remembered
    }
  }, [expanded]);

  useEffect(() => {
    if (!minimized && focusSearchAfterExpand.current) {
      focusSearchAfterExpand.current = false;
      searchRef.current?.focus();
    }
  }, [minimized]);

  useEffect(() => () => {
    if (closeTimer.current) clearTimeout(closeTimer.current);
  }, []);

  const isActive = (item: NavItem) => {
    const base = itemPath(item);
    return pathname === base || pathname.startsWith(`${base}/`);
  };
  const searching = query.trim() !== '';
  const visible = useMemo(() => searchNav(groups, query), [groups, query]);

  const isOpen = (group: NavGroup) => {
    if (searching) return true;
    const key = group.label ?? '';
    return expanded[key] ?? group.items.some(isActive);
  };
  const toggle = (group: NavGroup) => {
    const key = group.label ?? '';
    setExpanded((prev) => ({ ...prev, [key]: !isOpen(group) }));
  };

  const openFlyout = (label: string, target: HTMLElement) => {
    if (closeTimer.current) clearTimeout(closeTimer.current);
    setFlyout({ label, top: target.getBoundingClientRect().top });
  };
  const scheduleClose = () => {
    if (closeTimer.current) clearTimeout(closeTimer.current);
    closeTimer.current = setTimeout(() => setFlyout(null), 150);
  };

  const flyoutGroup = minimized && flyout ? groups.find((g) => (g.label ?? g.items[0].label) === flyout.label) : undefined;

  return (
    <aside
      className={`tenant-sidebar${mobileOpen ? ' open' : ''}${minimized ? ' minimized' : ''}`}
      aria-label="Main menu"
      onClick={(e) => {
        if ((e.target as HTMLElement).closest('a')) onNavigate();
      }}
    >
      <div className="tenant-sidebar-head">
        {/* The wordmark is dark-on-light artwork, so it sits on a white card spanning the sidebar
            width; minimized shows the square mark. Never stretched, never cropped. */}
        <div className="tenant-sidebar-logo">
          {minimized ? (
            <img src={logoSrc ?? '/apple-touch-icon.png'} alt="OptiFleet" style={{ width: 36, height: 36, objectFit: 'contain', display: 'block' }} />
          ) : logoSrc ? (
            <Logo src={logoSrc} height={64} style={{ width: '100%', height: 64 }} />
          ) : (
            // Default wordmark: the 512×188 asset carries transparent margins (artwork spans
            // x 30–465, y 27–176). The frame matches the artwork's aspect ratio and only that
            // transparent margin falls outside it, so the wordmark fills the card.
            <div style={{ position: 'relative', width: '100%', aspectRatio: '436 / 150', overflow: 'hidden' }}>
              <img src="/logo-optifleet.png" alt="OptiFleet" style={{ position: 'absolute', width: '117.43%', left: '-6.88%', top: '-18%', height: 'auto', display: 'block' }} />
            </div>
          )}
        </div>
      </div>

      {minimized ? (
        <button
          type="button"
          className="tenant-sidebar-iconbtn"
          aria-label="Search menu"
          title="Search menu"
          onClick={() => {
            focusSearchAfterExpand.current = true;
            onToggleMinimized();
          }}
        >
          <NavIcon name="search" />
        </button>
      ) : (
        <div className="tenant-sidebar-search">
          <span aria-hidden="true" style={{ position: 'absolute', left: 22, top: 9, color: '#6b7280' }}>
            <NavIcon name="search" size={16} />
          </span>
          <input
            ref={searchRef}
            type="search"
            aria-label="Search menu"
            placeholder="Search menu…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Escape') setQuery('');
            }}
          />
        </div>
      )}

      <nav className="tenant-sidebar-nav">
        {!minimized && searching && visible.length === 0 && <div style={{ padding: '10px 20px', fontSize: 13, color: '#9ca3af' }}>No menu matches “{query.trim()}”.</div>}

        {(minimized ? groups : visible).map((group, index) => {
          const key = group.label ?? group.items[0].label;
          const groupActive = group.items.some(isActive);

          // Ungrouped single entries (Dashboard, Audit Log) are direct links.
          // A later ungrouped entry (Audit Log) is set apart from the groups above it.
          if (!group.label) {
            return group.items.map((item) => (
              <NavLink
                key={`root:${item.label}`}
                className={minimized ? 'tenant-sidebar-iconlink' : undefined}
                to={item.to}
                title={minimized ? item.label : undefined}
                aria-label={minimized ? item.label : undefined}
                style={({ isActive: active }) => ({
                  ...(minimized ? { background: active ? '#1d4ed8' : 'transparent', color: active ? '#fff' : '#cbd5e1' } : linkStyle(active, 14)),
                  marginTop: index > 0 ? 16 : undefined,
                })}
              >
                <NavIcon name={group.icon} />
                {!minimized && <span>{item.label}</span>}
              </NavLink>
            ));
          }

          if (minimized) {
            return (
              <button
                key={key}
                type="button"
                className="tenant-sidebar-iconbtn"
                aria-label={group.label}
                aria-haspopup="menu"
                aria-expanded={flyout?.label === key}
                title={group.label}
                onMouseEnter={(e) => openFlyout(key, e.currentTarget)}
                onMouseLeave={scheduleClose}
                onFocus={(e) => openFlyout(key, e.currentTarget)}
                onClick={(e) => (flyout?.label === key ? setFlyout(null) : openFlyout(key, e.currentTarget))}
                style={groupActive ? { background: '#1d4ed8', color: '#fff' } : undefined}
              >
                <NavIcon name={group.icon} />
              </button>
            );
          }

          const open = isOpen(group);
          const listId = `nav-group-${key.replace(/\W+/g, '-').toLowerCase()}`;
          return (
            <div key={key}>
              <button
                type="button"
                className="tenant-sidebar-group"
                aria-expanded={open}
                aria-controls={listId}
                onClick={() => toggle(group)}
                disabled={searching}
                style={{ color: groupActive ? '#fff' : '#cbd5e1' }}
              >
                <NavIcon name={group.icon} />
                <span style={{ flex: 1, textAlign: 'left' }}>{group.label}</span>
                <span style={{ color: '#6b7280' }}>
                  <NavIcon name={open ? 'chevronDown' : 'chevronRight'} size={16} />
                </span>
              </button>
              {open && (
                <div id={listId}>
                  {group.items.map((item) => (
                    <NavLink key={`${key}:${item.label}`} to={item.to} style={({ isActive: active }) => linkStyle(active, 42)}>
                      {item.label}
                    </NavLink>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </nav>

      <button
        type="button"
        className="tenant-sidebar-collapse"
        onClick={onToggleMinimized}
        aria-label={minimized ? 'Expand sidebar' : 'Minimize sidebar'}
        title={minimized ? 'Expand sidebar' : 'Minimize sidebar'}
      >
        <NavIcon name={minimized ? 'expand' : 'collapse'} />
        {!minimized && <span>Minimize</span>}
      </button>

      {flyoutGroup && flyout && (
        <div
          role="menu"
          aria-label={flyoutGroup.label ?? undefined}
          className="tenant-sidebar-flyout"
          style={{ top: Math.min(flyout.top, window.innerHeight - 48 - flyoutGroup.items.length * 34) }}
          onMouseEnter={() => closeTimer.current && clearTimeout(closeTimer.current)}
          onMouseLeave={scheduleClose}
          onKeyDown={(e) => e.key === 'Escape' && setFlyout(null)}
          onClick={(e) => {
            if ((e.target as HTMLElement).closest('a')) setFlyout(null);
          }}
        >
          <div style={{ padding: '6px 14px 6px', fontSize: 11, textTransform: 'uppercase', letterSpacing: 1, color: '#9ca3af' }}>{flyoutGroup.label}</div>
          {flyoutGroup.items.map((item) => (
            <NavLink key={item.label} role="menuitem" to={item.to} style={({ isActive: active }) => linkStyle(active, 14)}>
              {item.label}
            </NavLink>
          ))}
        </div>
      )}
    </aside>
  );
}
