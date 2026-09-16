import { Link, useLocation } from 'react-router-dom';
import { humanizeSegment, isDynamicSegment, SEGMENT_LABELS } from '../navigation/breadcrumbLabels';
import { useBreadcrumbLabels } from '../navigation/BreadcrumbLabelContext';

interface Crumb {
  key: string;
  label: string;
  href: string | null;
}

/**
 * Breadcrumb derived from the actual current route (never a hardcoded
 * per-page string). Mount once per portal layout (Platform/Tenant) so
 * every page under it gets a breadcrumb automatically and consistently.
 * Dynamic segments (record ids) show a registered record name/code via
 * useBreadcrumbLabel when the page has loaded it, and a safe generic
 * fallback while it hasn't — it never throws or blocks on loading data.
 */
export function Breadcrumb() {
  const location = useLocation();
  const dynamicLabels = useBreadcrumbLabels();

  const segments = location.pathname.split('/').filter(Boolean);
  if (segments.length === 0) return null;

  const portalRoot = segments[0]; // 'platform' | 'app'
  const rootLabel = SEGMENT_LABELS[portalRoot] ?? humanizeSegment(portalRoot);
  const dashboardHref = `/${portalRoot}/dashboard`;

  // If we're exactly on the dashboard, only show the root crumb (current page).
  const onDashboard = segments.length === 2 && segments[1] === 'dashboard';

  const crumbs: Crumb[] = [{ key: 'root', label: rootLabel, href: onDashboard ? null : dashboardHref }];

  let cumulativePath = `/${portalRoot}`;
  let previousLabel = rootLabel;

  const trailSegments = onDashboard ? [] : segments.slice(1);

  trailSegments.forEach((segment, idx) => {
    cumulativePath += `/${segment}`;
    const isLast = idx === trailSegments.length - 1;

    let label: string;
    if (isDynamicSegment(segment)) {
      label = dynamicLabels[segment] ?? `${singularize(previousLabel)} Detail`;
    } else {
      label = SEGMENT_LABELS[segment] ?? humanizeSegment(segment);
    }

    crumbs.push({ key: `${cumulativePath}:${idx}`, label, href: isLast ? null : cumulativePath });
    previousLabel = label;
  });

  return (
    <nav className="breadcrumb" aria-label="Breadcrumb">
      {crumbs.map((crumb, idx) => (
        <span key={crumb.key} style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
          {idx > 0 && (
            <span className="breadcrumb-sep" aria-hidden="true">
              /
            </span>
          )}
          {crumb.href ? (
            <Link to={crumb.href} className="breadcrumb-crumb-label">
              {crumb.label}
            </Link>
          ) : (
            <span className="breadcrumb-current breadcrumb-crumb-label" aria-current="page">
              {crumb.label}
            </span>
          )}
        </span>
      ))}
    </nav>
  );
}

function singularize(label: string): string {
  return label.endsWith('ies') ? `${label.slice(0, -3)}y` : label.endsWith('s') && !label.endsWith('ss') ? label.slice(0, -1) : label;
}
