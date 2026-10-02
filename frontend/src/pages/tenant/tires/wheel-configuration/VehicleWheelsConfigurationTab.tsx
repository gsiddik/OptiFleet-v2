import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { ErrorState, LoadingState } from '../../../../components/States';
import { formatDate, formatDateTime } from '../../../../utils/date';
import { bodyStyleFor, truckConfigurationTypeOption, vehicleTypeOption, type VehicleType } from './vehicleTypes';
import { WheelConfigurationPreview } from './WheelConfigurationPreview';
import { PositionPanel } from './PositionPanel';
import type { VehicleWheelConfiguration } from './masterTypes';

/**
 * Vehicle Detail → Wheels Configuration. Shows the configuration VERSION mapped to this vehicle
 * (assigned only through the configuration's Vehicle Mapping page) and lets the user pick a
 * position on the preview to see — or register — the tire on it.
 */
export function VehicleWheelsConfigurationTab({ vehicleId }: { vehicleId: string }) {
  const navigate = useNavigate();
  const [data, setData] = useState<VehicleWheelConfiguration | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);

  const load = useCallback(() => {
    apiClient
      .get(`/app/vehicles/${vehicleId}/wheel-configuration`)
      .then((res) => setData(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [vehicleId]);
  useEffect(load, [load]);

  const installed = useMemo(() => new Set((data?.installations ?? []).map((i) => i.position_code)), [data]);

  if (error) return <ErrorState message={error} />;
  if (!data) return <LoadingState />;

  const mapping = data.mapping;
  if (!mapping) {
    const missing = [!data.vehicle.vehicle_type && 'Vehicle Type', data.vehicle.axle_count == null && 'Axles', data.vehicle.wheel_count == null && 'Wheels'].filter(Boolean);
    return (
      <div className="card" data-wheels-empty style={{ textAlign: 'center', padding: '36px 20px' }}>
        <h3 style={{ margin: '0 0 6px', fontSize: 16 }}>No Wheels Configuration Assigned</h3>
        <p style={{ fontSize: 13, color: '#6b7280', margin: '0 auto 14px', maxWidth: 520 }}>
          A wheels configuration is assigned from the configuration&apos;s Vehicle Mapping page. Open the list to find a configuration that matches this vehicle.
          {missing.length > 0 && <> Complete {missing.join(', ')} in Overview → Edit Specifications first so the vehicle can be matched.</>}
        </p>
        <button className="btn-primary" onClick={() => navigate(`/app/wheel-configurations?vehicle=${vehicleId}`)}>
          Open Wheels Configuration List
        </button>
      </div>
    );
  }

  const version = mapping.version;
  const master = mapping.master;
  const selectedPosition = version.positions.find((p) => p.position_code === selected) ?? null;
  const installation = data.installations.find((i) => i.position_code === selected) ?? null;

  return (
    <div data-wheels-mapped>
      <div className="card" data-wheels-summary style={{ marginBottom: 16 }}>
        <div style={{ display: 'flex', gap: 28, flexWrap: 'wrap', fontSize: 13, alignItems: 'flex-end' }}>
          <Stat label="Config Code" value={<span style={{ fontFamily: 'monospace' }}>{version.config_code}</span>} />
          <Stat label="Vehicle Type" value={vehicleTypeOption(master.vehicle_type)?.label ?? master.vehicle_type} />
          {master.truck_configuration_type && <Stat label="Truck Configuration Type" value={truckConfigurationTypeOption(master.truck_configuration_type)?.label ?? master.truck_configuration_type} />}
          <Stat label="Total Axles" value={version.total_axles} />
          <Stat label="Total Wheels" value={version.total_wheels} />
          <Stat label="Spare Tires" value={version.spare_tires} />
          <Stat label="Version" value={`v${version.version_number}`} />
          <Link to={`/app/wheel-configurations/${master.id}`} style={{ fontSize: 13, marginLeft: 'auto' }}>
            Open configuration
          </Link>
        </div>
        {!mapping.is_current_version && (
          <p style={{ fontSize: 12, color: '#92400e', margin: '10px 0 0' }}>
            This configuration has a newer version ({master.config_code}). The vehicle stays on v{version.version_number} until it is updated in Vehicle Mapping.
          </p>
        )}
      </div>

      <div className="vehicle-wheels-layout" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(340px, 100%), 1fr))', gap: 16, alignItems: 'start' }}>
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Preview</h3>
          <WheelConfigurationPreview
            bodyStyle={bodyStyleFor(master.vehicle_type as VehicleType, master.truck_configuration_type)}
            input={{ front: version.front_axles, rear: version.rear_axles, spareTires: version.spare_tires }}
            selectedCode={selected}
            onPositionSelect={setSelected}
            installedCodes={installed}
          />
          <p style={{ fontSize: 11, color: '#6b7280', marginBottom: 0 }}>
            Tap a tire to view or register it. <span style={{ color: '#16a34a' }}>●</span> green outline = tire registered ({installed.size} of {version.positions.length} positions).
          </p>
        </div>
        <div className="card" data-position-panel-card>
          {selectedPosition ? (
            <PositionPanel key={selectedPosition.position_code} vehicleId={vehicleId} position={selectedPosition} installation={installation} onSaved={load} />
          ) : (
            <p style={{ fontSize: 13, color: '#6b7280', margin: 0 }}>Select a wheel position on the preview to see its tire.</p>
          )}
        </div>
      </div>

      {data.history.length > 0 && (
        <details style={{ marginTop: 16 }} data-mapping-history>
          <summary style={{ cursor: 'pointer', fontSize: 13, fontWeight: 600, color: '#374151' }}>Configuration history ({data.history.length})</summary>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginTop: 8 }}>
            <tbody>
              {data.history.map((h) => (
                <tr key={h.id} style={{ borderTop: '1px solid #f3f4f6' }}>
                  <td style={{ padding: '4px 6px', fontFamily: 'monospace' }}>{h.config_code}</td>
                  <td style={{ padding: '4px 6px' }}>v{h.version_number}</td>
                  <td style={{ padding: '4px 6px' }}>{formatDateTime(h.mapped_at)}</td>
                  <td style={{ padding: '4px 6px' }}>{h.status === 'ACTIVE' ? 'Current' : `Ended ${formatDate(h.ended_at)} (${h.end_reason === 'VERSION_UPDATED' ? 'updated to newer version' : 'unmapped'})`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <div style={{ color: '#6b7280', fontSize: 12 }}>{label}</div>
      <div style={{ fontWeight: 600, fontSize: 15 }}>{value}</div>
    </div>
  );
}
