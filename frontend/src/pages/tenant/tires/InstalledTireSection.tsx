import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { describePositionCode } from '../../../utils/tirePosition';
import type { TireInstalledSummary } from '../../../types';
import { formatHours, formatKm } from './operations/tireOperationFormat';
import { WheelConfigurationPreview } from './wheel-configuration/WheelConfigurationPreview';
import { bodyStyleFor, type VehicleType } from './wheel-configuration/vehicleTypes';

const HIGHLIGHT = '#a8321f';

/**
 * Serial Detail of an installed tire: its position, usage and tread depth (left) and the vehicle's
 * Wheels Configuration with the position highlighted (right, read-only). Every value — including
 * whether the tread is below the product's reference — comes from the backend.
 */
export function InstalledTireSection({ installed }: { installed: TireInstalledSummary }) {
  const { configuration } = installed;
  const below = installed.tread_below_reference === true;

  return (
    <div className="split-layout" data-installed-tire style={{ marginBottom: 16 }}>
      <section className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Installed Tire</h3>
        <dl style={{ display: 'grid', gridTemplateColumns: 'minmax(150px, max-content) 1fr', gap: '10px 14px', fontSize: 14, margin: 0 }}>
          <Fact label="Vehicle">
            <Link to={`/app/vehicles/${installed.vehicle.id}?tab=wheels`}>{installed.vehicle.registration_number ?? '—'}</Link>
          </Fact>
          <Fact label="Position">
            <div data-position-code={installed.position_code}>
              <div style={{ fontWeight: 700, fontSize: 18 }}>{installed.position_code}</div>
              <div style={{ color: '#6b7280', fontSize: 13 }}>{describePositionCode(installed.position_code)}</div>
            </div>
          </Fact>
          <Fact label="Usage KM">
            <span data-usage-km>{formatKm(installed.usage_km)}</span>
          </Fact>
          <Fact label="Usage Time / Hours Meter">
            <span data-usage-hours>{formatHours(installed.usage_hours)}</span>
          </Fact>
          <Fact label="Current Tread Depth">
            <div data-tread>
              <span style={{ fontVariantNumeric: 'tabular-nums', color: below ? '#b91c1c' : undefined, fontWeight: below ? 700 : undefined }}>
                {installed.current_tread_depth_mm != null ? `${installed.current_tread_depth_mm} mm` : '—'}
              </span>
              <span style={{ color: '#6b7280' }}> / {installed.reference_tread_depth_mm != null ? `${installed.reference_tread_depth_mm} mm` : '—'}</span>
              <div style={{ color: '#6b7280', fontSize: 12 }}>Current / Reference</div>
              {below && (
                <div role="alert" data-tread-warning style={{ color: '#b91c1c', fontSize: 13, marginTop: 4 }}>
                  (The Tread Depth is below standard, <strong>NEED TO CHECK</strong>)
                </div>
              )}
            </div>
          </Fact>
        </dl>
      </section>
      <section className="card" data-installed-preview>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Preview</h3>
        {configuration ? (
          <>
            <WheelConfigurationPreview
              readOnly
              bodyStyle={bodyStyleFor(configuration.vehicle_type as VehicleType, configuration.truck_configuration_type)}
              input={{ front: configuration.front_axles, rear: configuration.rear_axles, spareTires: configuration.spare_tires }}
              positionColors={{ [installed.position_code]: HIGHLIGHT }}
            />
            <p style={{ fontSize: 12, color: '#6b7280', marginBottom: 0 }}>
              Wheels Configuration {configuration.config_code} (v{configuration.version_number}) — this tire is on <strong style={{ color: HIGHLIGHT }}>{installed.position_code}</strong>.
            </p>
          </>
        ) : (
          <p style={{ fontSize: 13, color: '#6b7280' }}>This vehicle has no Wheels Configuration mapped.</p>
        )}
      </section>
    </div>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt style={{ color: '#6b7280' }}>{label}</dt>
      <dd style={{ margin: 0 }}>{children}</dd>
    </>
  );
}
