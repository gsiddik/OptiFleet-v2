import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { Modal } from '../../../../components/Modal';
import { groupPositions, type ConfigurationMaster, type ConfigurationVersion, type GeneratedPosition, type PositionDiff, type SaveRequest } from './masterTypes';
import { truckConfigurationTypeOption, vehicleTypeOption } from './vehicleTypes';

interface PreviewResult {
  configuration: { config_code: string; total_axles: number; total_wheels: number; positions: GeneratedPosition[] };
  current_version: ConfigurationVersion | null;
  changed: boolean;
  diff: PositionDiff | null;
  duplicate: { id: string; config_code: string } | null;
  mapped_vehicle_count: number;
}

const API = '/app/wheel-configuration-masters';

/**
 * Configuration-level Save confirmation, mounted once per save attempt. Shows what will be saved —
 * Vehicle Type, Truck Configuration Type, the server-generated Config Code, totals and the generated
 * position list — and, when editing a saved configuration, the position diff against its current
 * version. No vehicle or tire is involved: this saves a reusable configuration template.
 */
export function SaveConfigurationDialog({
  request,
  masterId,
  onClose,
  onSaved,
}: {
  request: SaveRequest;
  /** set when editing a saved configuration (creates a new version) */
  masterId: string | null;
  onClose: () => void;
  onSaved: (master: ConfigurationMaster, version: ConfigurationVersion) => void;
}) {
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .post(`${API}/preview`, masterId ? { ...request, wheel_configuration_master_id: masterId } : request)
      .then((res) => !cancelled && setPreview(res.data.data))
      .catch((e) => !cancelled && setError(firstError(e)))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [request, masterId]);

  async function save() {
    setSaving(true);
    setError(null);
    try {
      const res = masterId ? await apiClient.put(`${API}/${masterId}`, request) : await apiClient.post(API, request);
      onSaved(res.data.data.master, res.data.data.version);
    } catch (e) {
      setError(firstError(e));
    } finally {
      setSaving(false);
    }
  }

  const truckType = request.truck_configuration_type ? truckConfigurationTypeOption(request.truck_configuration_type)?.label : null;
  const canSave = preview !== null && preview.changed && !preview.duplicate && !saving;

  return (
    <Modal open title="Save Wheels Configuration" onClose={onClose} width={560}>
      <div data-save-dialog>
        <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 14px', fontSize: 13, margin: '0 0 12px' }}>
          <dt style={{ color: '#6b7280' }}>Vehicle Type</dt>
          <dd style={{ margin: 0 }}>{vehicleTypeOption(request.vehicle_type)?.label ?? request.vehicle_type}</dd>
          {truckType && (
            <>
              <dt style={{ color: '#6b7280' }}>Configuration Type</dt>
              <dd style={{ margin: 0 }}>{truckType}</dd>
            </>
          )}
          <dt style={{ color: '#6b7280' }}>Config Code</dt>
          <dd data-save-code style={{ margin: 0, fontWeight: 700 }}>
            {preview?.configuration.config_code ?? request.config_code}
          </dd>
          {preview && (
            <>
              <dt style={{ color: '#6b7280' }}>Total Axles</dt>
              <dd style={{ margin: 0 }}>{preview.configuration.total_axles}</dd>
              <dt style={{ color: '#6b7280' }}>Total Wheels</dt>
              <dd style={{ margin: 0 }}>{preview.configuration.total_wheels}</dd>
            </>
          )}
          {preview?.current_version && (
            <>
              <dt style={{ color: '#6b7280' }}>Current Version</dt>
              <dd style={{ margin: 0 }}>
                Version {preview.current_version.version_number} ({preview.current_version.config_code}){preview.changed ? ` → Version ${preview.current_version.version_number + 1}` : ''}
              </dd>
            </>
          )}
        </dl>

        {loading && <p style={{ fontSize: 13, color: '#6b7280' }}>Generating positions…</p>}

        {preview && (
          <>
            <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4 }}>Generated Positions ({preview.configuration.positions.length})</div>
            <table data-generated-positions style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 12 }}>
              <tbody>
                {groupPositions(preview.configuration.positions).map((row) => (
                  <tr key={row.label} style={{ borderTop: '1px solid #f3f4f6' }}>
                    <td style={{ padding: '3px 6px', color: '#6b7280', width: 48 }}>{row.label}</td>
                    <td style={{ padding: '3px 6px', fontFamily: 'monospace' }}>{row.codes.join(' ')}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            {preview.diff && preview.changed && (
              <div data-save-diff style={{ display: 'grid', gap: 6, marginBottom: 12, padding: 10, background: '#f9fafb', borderRadius: 6 }}>
                <div style={{ fontSize: 12, color: '#6b7280' }}>Changes to the position list of this configuration (the current version keeps its positions as history):</div>
                <DiffRow kind="unchanged" label="Unchanged" codes={preview.diff.unchanged} color="#374151" />
                <DiffRow kind="added" label="Added" codes={preview.diff.added} color="#166534" />
                <DiffRow kind="removed" label="Removed" codes={preview.diff.removed} color="#b45309" />
              </div>
            )}

            {preview.changed && preview.mapped_vehicle_count > 0 && (
              <p data-save-mapped-impact style={{ fontSize: 12, color: '#92400e', margin: '0 0 12px' }}>
                {preview.mapped_vehicle_count} mapped vehicle(s) stay on their current version until updated in Vehicle Mapping.
              </p>
            )}
            {!preview.changed && (
              <p data-save-unchanged style={{ fontSize: 13, color: '#166534' }}>
                No changes — this is already the current version.
              </p>
            )}
            {preview.duplicate && (
              <div data-save-duplicate role="alert" style={{ fontSize: 13, color: '#b91c1c', marginBottom: 12 }}>
                Configuration {preview.duplicate.config_code} already exists for this vehicle type. <Link to={`/app/wheel-configurations/${preview.duplicate.id}/edit`}>Edit that configuration</Link> instead.
              </div>
            )}
          </>
        )}

        {error && (
          <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginBottom: 12 }}>
            {error}
          </div>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button className="btn-secondary" onClick={onClose}>
            {preview && !preview.changed ? 'Close' : 'Cancel'}
          </button>
          {(!preview || preview.changed) && (
            <button className="btn-primary" onClick={save} disabled={!canSave}>
              {saving ? 'Saving…' : 'Confirm Save'}
            </button>
          )}
        </div>
      </div>
    </Modal>
  );
}

function firstError(e: unknown): string {
  const data = extractApiError(e);
  return Object.values(data.errors ?? {})[0]?.[0] ?? data.message;
}

function DiffRow({ kind, label, codes, color }: { kind: string; label: string; codes: string[]; color: string }) {
  return (
    <div data-diff={kind} style={{ fontSize: 13 }}>
      <span style={{ color, fontWeight: 600 }}>
        {label} ({codes.length}):
      </span>{' '}
      <span style={{ fontFamily: 'monospace', fontSize: 12, color: '#374151', wordBreak: 'break-word' }}>{codes.length ? codes.join(' ') : '—'}</span>
    </div>
  );
}
