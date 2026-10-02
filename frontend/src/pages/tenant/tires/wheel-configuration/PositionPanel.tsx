import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../../components/StatusBadge';
import { formatDate } from '../../../../utils/date';
import type { PositionInstallation, VersionPosition } from './masterTypes';

/** Side panel for the selected wheel position: the registered tire, or that none is registered yet. */
export function PositionPanel({ position, installation }: { vehicleId: string; position: VersionPosition; installation: PositionInstallation | null; onSaved: () => void }) {
  return (
    <div data-position-panel={position.position_code}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>
        Position <span style={{ fontFamily: 'monospace' }}>{position.position_code}</span>
      </h3>
      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 12 }}>{position.label}</div>
      {installation ? <InstalledTire installation={installation} /> : <p style={{ fontSize: 13, color: '#6b7280' }}>No tire registered on this position.</p>}
    </div>
  );
}

export function InstalledTire({ installation }: { installation: PositionInstallation }) {
  const product = installation.tire.product;
  return (
    <dl data-installed-tire style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 14px', fontSize: 13, margin: 0 }}>
      <Row label="Tire Position Code" value={<span style={{ fontFamily: 'monospace' }}>{installation.position_code}</span>} />
      <Row label="Product" value={product ? `${product.name}${product.sku ? ` — ${product.sku}` : ''}` : '—'} />
      <Row label="Serial Number" value={<Link to={`/app/tires/${installation.tire.id}`}>{installation.tire.serial_number}</Link>} />
      <Row label="Last Known Installation Date" value={formatDate(installation.installed_date)} />
      <Row label="Last Known Installation Time" value={installation.installed_time} />
      <Row label="Last Known Installation KM" value={installation.installation_odometer ?? '—'} />
      <Row label="Last Known Tread Depth" value={installation.tread_depth_mm != null ? `${installation.tread_depth_mm} mm` : '—'} />
      <Row label="Status" value={<StatusBadge status={installation.tire.current_status} />} />
    </dl>
  );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <>
      <dt style={{ color: '#6b7280' }}>{label}</dt>
      <dd style={{ margin: 0 }}>{value}</dd>
    </>
  );
}
