import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { formatDate } from '../../../../utils/date';
import type { TireCard } from './tireOperationTypes';
import { REPLACEMENT_COLOR, ROTATION_RETURN_COLOR, formatKm } from './tireOperationFormat';

/**
 * Rounded card with the facts of the tire on one position (Installed Tire / To be Rotated /
 * Rotating With). `children` hosts the per-type input (Replacing With dropdown, tread depth).
 */
export function TireOperationCard({
  title,
  code,
  tire,
  accent,
  vehicleId,
  children,
  onRemove,
}: {
  title: string;
  code: string;
  tire: TireCard | null;
  accent: string;
  vehicleId?: string;
  children?: ReactNode;
  onRemove?: () => void;
}) {
  return (
    <section data-operation-card={code} data-card-title={title} style={{ border: `1px solid ${accent}`, borderLeft: `5px solid ${accent}`, borderRadius: 12, padding: '10px 14px', background: '#fff' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 8, marginBottom: 8 }}>
        <h4 style={{ margin: 0, fontSize: 13, color: accent, textTransform: 'uppercase', letterSpacing: 0.4 }}>{title}</h4>
        {onRemove && (
          <button type="button" className="btn-link" onClick={onRemove} style={{ fontSize: 12 }} aria-label={`Remove ${code}`}>
            Remove
          </button>
        )}
      </div>
      {tire ? (
        <dl style={{ display: 'grid', gridTemplateColumns: 'minmax(130px, max-content) 1fr', gap: '4px 12px', fontSize: 13, margin: 0 }}>
          <Fact label="Tire Position" value={<PositionLabel code={code} variant="stacked" />} />
          <Fact label="Current Serial Number" value={<span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{tire.serial_number}</span>} />
          <Fact label="Last Tire Operations Date" value={tire.last_operation_date ? formatDate(tire.last_operation_date) : '—'} />
          <Fact label="Last Tire Operations Time" value={tire.last_operation_time ?? '—'} />
          <Fact label="Last KM at Tire Operations" value={formatKm(tire.last_operation_odometer)} />
          <Fact label="Usage KM" value={formatKm(tire.usage_km)} />
          <Fact label="Usage Time / Hours Meter" value={tire.usage_hours ?? '—'} />
          <Fact label="Last Tread Depth" value={tire.last_tread_depth_mm != null ? `${tire.last_tread_depth_mm} mm` : '—'} />
        </dl>
      ) : (
        <MissingTireNotice code={code} vehicleId={vehicleId} />
      )}
      {children && <div style={{ marginTop: 10 }}>{children}</div>}
    </section>
  );
}

/** A selected position without tire data: the operation cannot continue until it is completed. */
export function MissingTireNotice({ code, vehicleId }: { code: string; vehicleId?: string }) {
  return (
    <div role="alert" data-missing-tire={code} style={{ fontSize: 13, color: '#991b1b', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, padding: '8px 10px' }}>
      <PositionLabel code={code} /> has no tire data yet. Complete it in{' '}
      {vehicleId ? <Link to={`/app/vehicles/${vehicleId}?tab=wheels`}>Vehicle Details → Wheels Configuration</Link> : 'Vehicle Details → Wheels Configuration'} before continuing.
    </div>
  );
}

function Fact({ label, value }: { label: string; value: ReactNode }) {
  return (
    <>
      <dt style={{ color: '#6b7280' }}>{label}</dt>
      <dd style={{ margin: 0 }}>{value}</dd>
    </>
  );
}

/** Installed Tire ↓ Replacing With. */
export function ReplacementArrow({ color = REPLACEMENT_COLOR }: { color?: string }) {
  return (
    <div aria-hidden="true" data-replacement-arrow style={{ display: 'flex', justifyContent: 'center', margin: '2px 0' }}>
      <svg width="28" height="30" viewBox="0 0 28 30">
        <line x1="14" y1="2" x2="14" y2="22" stroke={color} strokeWidth="3" strokeLinecap="round" />
        <path d="M5 17 L14 28 L23 17" fill="none" stroke={color} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </div>
  );
}

/** To be Rotated ↘ Rotating With, and back ↖ — two curved arrows in two colours. */
export function RotationArrows({ color }: { color: string }) {
  return (
    <div aria-hidden="true" data-rotation-arrows style={{ display: 'flex', justifyContent: 'center', margin: '2px 0' }}>
      <svg width="200" height="52" viewBox="0 0 200 52">
        <defs>
          <marker id={`ra-${color.slice(1)}`} markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto">
            <path d="M0 0 L8 4 L0 8 Z" fill={color} />
          </marker>
          <marker id="ra-return" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto">
            <path d="M0 0 L8 4 L0 8 Z" fill={ROTATION_RETURN_COLOR} />
          </marker>
        </defs>
        {/* ↘ To be Rotated → Rotating With (pair colour) */}
        <path d="M44 4 C 52 36, 104 40, 136 44" fill="none" stroke={color} strokeWidth="3" markerEnd={`url(#ra-${color.slice(1)})`} />
        {/* ↖ Rotating With → To be Rotated (neutral colour) */}
        <path d="M160 46 C 156 14, 104 10, 68 6" fill="none" stroke={ROTATION_RETURN_COLOR} strokeWidth="3" markerEnd="url(#ra-return)" />
      </svg>
    </div>
  );
}
