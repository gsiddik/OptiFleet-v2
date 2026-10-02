import { useEffect, useState, type ReactNode } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { BackButton } from '../../../../components/BackButton';
import { FormField, inputStyle } from '../../../../components/FormField';
import { ErrorState, LoadingState } from '../../../../components/States';
import { StatusBadge } from '../../../../components/StatusBadge';
import { useAuth } from '../../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../../navigation/BreadcrumbLabelContext';
import { formatDateTime } from '../../../../utils/date';
import { bodyStyleFor, truckConfigurationTypeOption, vehicleTypeOption, type VehicleType } from './vehicleTypes';
import { WheelConfigurationPreview } from './WheelConfigurationPreview';
import { groupPositions, type ConfigurationMaster, type ConfigurationVersion } from './masterTypes';

/**
 * Read-only view of a saved Wheel Configuration: every saved field of the selected version, the
 * vehicle preview drawn from that version (same renderer as create/edit) and its generated
 * positions. Older versions stay viewable — they keep their own position list as history.
 */
export function WheelConfigurationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [master, setMaster] = useState<ConfigurationMaster | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [versionId, setVersionId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get(`/app/wheel-configuration-masters/${id}`)
      .then((res) => !cancelled && setMaster(res.data.data))
      .catch((e) => !cancelled && setError(extractApiError(e).message));
    return () => {
      cancelled = true;
    };
  }, [id]);

  useBreadcrumbLabel(master?.id, master?.config_code);

  if (error) return <ErrorState message={error} />;
  if (!master) return <LoadingState />;

  const versions = master.versions ?? [];
  const version: ConfigurationVersion | undefined = versions.find((v) => v.id === versionId) ?? versions.find((v) => v.status === 'ACTIVE') ?? versions[0];
  const truckType = master.truck_configuration_type ? truckConfigurationTypeOption(master.truck_configuration_type)?.label : null;

  return (
    <div>
      <BackButton fallbackTo="/app/wheel-configurations" label="← Back to Wheel Configuration" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          Wheels Configuration <span style={{ fontFamily: 'monospace' }}>{master.config_code}</span>
        </h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn-secondary" onClick={() => navigate(`/app/wheel-configurations/${master.id}/vehicle-mapping`)}>
            Vehicle Mapping
          </button>
          {hasPermission('tire.manage') && (
            <button className="btn-primary" onClick={() => navigate(`/app/wheel-configurations/${master.id}/edit`)}>
              Edit
            </button>
          )}
        </div>
      </div>

      {version && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: 16, alignItems: 'start' }}>
          <div>
            <div className="card" style={{ marginBottom: 16 }}>
              {versions.length > 1 && (
                <FormField label="Version">
                  <select aria-label="Version" value={version.id} onChange={(e) => setVersionId(e.target.value)} style={inputStyle}>
                    {versions.map((v) => (
                      <option key={v.id} value={v.id}>
                        Version {v.version_number} · {v.config_code} · {v.status}
                      </option>
                    ))}
                  </select>
                </FormField>
              )}
              <dl data-config-summary style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', fontSize: 13, margin: 0 }}>
                <Item label="Vehicle Type" value={vehicleTypeOption(master.vehicle_type)?.label ?? master.vehicle_type} />
                <Item label="Truck Configuration Type" value={truckType ?? '—'} />
                <Item label="Config Code" value={<strong style={{ fontFamily: 'monospace' }}>{version.config_code}</strong>} />
                <Item label="Version" value={<span>Version {version.version_number} <StatusBadge status={version.status} /></span>} />
                <Item label="Number of Front Axles" value={version.front_axles.length} />
                <Item label="Front Wheels / Side" value={perAxle(version.front_axles)} />
                <Item label="Number of Rear Axles" value={version.rear_axles.length} />
                <Item label="Rear Wheels / Side" value={perAxle(version.rear_axles)} />
                <Item label="Total Axles" value={version.total_axles} />
                <Item label="Total Wheels" value={version.total_wheels} />
                <Item label="Spare Tires" value={version.spare_tires} />
                <Item label="Saved" value={formatDateTime(version.created_at)} />
                <Item label="Mapped Vehicles" value={master.mapped_vehicle_count ?? 0} />
              </dl>
            </div>

            <div className="card">
              <h3 style={{ marginTop: 0, fontSize: 15 }}>Generated Positions ({version.positions?.length ?? 0})</h3>
              <table data-version-positions style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                <tbody>
                  {groupPositions((version.positions ?? []).map((p) => ({ position_code: p.position_code, group: p.position_group, axle_in_group: p.axle_in_group }))).map((row) => (
                    <tr key={row.label} style={{ borderTop: '1px solid #f3f4f6' }}>
                      <td style={{ padding: '3px 6px', color: '#6b7280', width: 48 }}>{row.label}</td>
                      <td style={{ padding: '3px 6px', fontFamily: 'monospace' }}>{row.codes.join(' ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {version.version_number > 1 && (
                <div data-version-diff style={{ fontSize: 12, background: '#f9fafb', borderRadius: 6, padding: 8, marginTop: 10 }}>
                  <div style={{ color: '#6b7280', marginBottom: 4 }}>Changes from version {version.version_number - 1}:</div>
                  <div>
                    <span style={{ color: '#166534', fontWeight: 600 }}>Added:</span> <code>{version.position_diff.added.join(' ') || '—'}</code>
                  </div>
                  <div>
                    <span style={{ color: '#b45309', fontWeight: 600 }}>Removed:</span> <code>{version.position_diff.removed.join(' ') || '—'}</code>
                  </div>
                  <div>
                    <span style={{ fontWeight: 600 }}>Unchanged:</span> {version.position_diff.unchanged.length} positions
                  </div>
                </div>
              )}
            </div>
          </div>

          <div className="card" style={{ position: 'sticky', top: 12 }}>
            <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Preview</h3>
            <WheelConfigurationPreview
              bodyStyle={bodyStyleFor(master.vehicle_type as VehicleType, master.truck_configuration_type)}
              input={{ front: version.front_axles, rear: version.rear_axles, spareTires: version.spare_tires }}
            />
          </div>
        </div>
      )}
    </div>
  );
}

function perAxle(values: number[]): string {
  return values.length ? values.map((n, i) => `Axle ${i + 1}: ${n}`).join(' · ') : '—';
}

function Item({ label, value }: { label: string; value: ReactNode }) {
  return (
    <>
      <dt style={{ color: '#6b7280' }}>{label}</dt>
      <dd style={{ margin: 0 }}>{value}</dd>
    </>
  );
}
