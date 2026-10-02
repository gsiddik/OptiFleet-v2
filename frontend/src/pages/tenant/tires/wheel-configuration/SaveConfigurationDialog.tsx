import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { Modal } from '../../../../components/Modal';

export interface SaveRequest {
  vehicle_category_id: string;
  vehicle_type: string;
  truck_configuration_type?: string;
  front_axles: number[];
  rear_axles: number[];
  spare_tires: number;
  /** what the form shows; the server regenerates it and rejects a mismatch */
  config_code: string;
}

export interface ConfigurationVersion {
  id: string;
  version_number: number;
  vehicle_type: string;
  truck_configuration_type: string | null;
  config_code: string;
  status: 'ACTIVE' | 'SUPERSEDED';
  total_axles: number;
  total_wheels: number;
  activated_at: string | null;
}

interface PositionDiff {
  unchanged: string[];
  added: string[];
  removed: string[];
}

interface Blocker {
  position_code: string;
  reason: 'REMOVED' | 'NOT_IN_CONFIGURATION';
  tire_id: string;
  tire_serial_number: string;
  vehicle_id: string;
  vehicle_registration_number: string;
}

interface PreviewResult {
  configuration: { config_code: string; total_axles: number; total_wheels: number };
  active_version: ConfigurationVersion | null;
  changed: boolean;
  diff: PositionDiff;
  blockers: Blocker[];
  can_save: boolean;
}

const API = '/app/wheel-configuration-versions';

/**
 * Save step, mounted once per save attempt: the server computes a dry-run diff (UNCHANGED / ADDED /
 * REMOVED) and lists every tire still installed on a position that would disappear. Saving is only
 * offered when nothing blocks; the server re-checks everything inside the save transaction anyway.
 */
export function SaveConfigurationDialog({ request, onClose, onSaved }: { request: SaveRequest; onClose: () => void; onSaved: (version: ConfigurationVersion) => void }) {
  const [preview, setPreview] = useState<PreviewResult | null>(null);
  const [blockers, setBlockers] = useState<Blocker[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .post(`${API}/preview`, request)
      .then((res) => {
        if (cancelled) return;
        setPreview(res.data.data);
        setBlockers(res.data.data.blockers);
      })
      .catch((e) => !cancelled && setError(extractApiError(e).message))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [request]);

  async function save() {
    setSaving(true);
    setError(null);
    try {
      const res = await apiClient.post(API, request);
      onSaved(res.data.data.version);
    } catch (e) {
      const data = extractApiError(e) as { message: string; blockers?: Blocker[] };
      setError(data.message);
      if (data.blockers) setBlockers(data.blockers);
    } finally {
      setSaving(false);
    }
  }

  const canSave = preview !== null && preview.changed && blockers.length === 0 && !saving;

  return (
    <Modal open title="Save Wheels Configuration" onClose={onClose} width={560}>
      <div data-save-dialog>
        {loading && <p style={{ fontSize: 13, color: '#6b7280' }}>Checking the configuration…</p>}
        {preview && (
          <>
            <p style={{ fontSize: 13, margin: '0 0 12px' }}>
              Config Code <strong>{preview.configuration.config_code}</strong> · {preview.configuration.total_axles} axles · {preview.configuration.total_wheels} wheels
              <br />
              <span style={{ color: '#6b7280' }}>
                {preview.active_version
                  ? `Current: version ${preview.active_version.version_number} (${preview.active_version.config_code}).`
                  : 'No saved configuration for this vehicle category yet.'}
              </span>
            </p>

            {!preview.changed ? (
              <p data-save-unchanged style={{ fontSize: 13, color: '#166534' }}>
                This configuration is already active. Nothing to save.
              </p>
            ) : (
              <div style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
                <DiffRow kind="unchanged" label="Unchanged" codes={preview.diff.unchanged} color="#374151" />
                <DiffRow kind="added" label="Added" codes={preview.diff.added} color="#166534" />
                <DiffRow kind="removed" label="Removed (retired, kept in history)" codes={preview.diff.removed} color="#b45309" />
              </div>
            )}
          </>
        )}

        {blockers.length > 0 && (
          <div data-save-blockers style={{ background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6, padding: 10, marginBottom: 12, fontSize: 13 }}>
            <strong style={{ color: '#b91c1c' }}>Cannot save — tires are still installed on positions this configuration removes.</strong>
            <div style={{ color: '#7f1d1d', margin: '4px 0 8px' }}>Remove or transfer these tires first. Tires are never moved automatically.</div>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 12 }}>
                  <th style={{ padding: '2px 4px' }}>Position</th>
                  <th style={{ padding: '2px 4px' }}>Tire</th>
                  <th style={{ padding: '2px 4px' }}>Vehicle</th>
                </tr>
              </thead>
              <tbody>
                {blockers.map((b) => (
                  <tr key={b.tire_id} data-blocker={b.position_code}>
                    <td style={{ padding: '2px 4px', fontFamily: 'monospace', fontWeight: 700 }}>{b.position_code}</td>
                    <td style={{ padding: '2px 4px' }}>
                      <Link to={`/app/tires/${b.tire_id}`}>{b.tire_serial_number}</Link>
                    </td>
                    <td style={{ padding: '2px 4px' }}>{b.vehicle_registration_number}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {error && blockers.length === 0 && (
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

function DiffRow({ kind, label, codes, color }: { kind: string; label: string; codes: string[]; color: string }) {
  return (
    <div data-diff={kind} style={{ fontSize: 13 }}>
      <div style={{ color, fontWeight: 600 }}>
        {label} ({codes.length})
      </div>
      <div style={{ fontFamily: 'monospace', fontSize: 12, color: '#374151', wordBreak: 'break-word' }}>{codes.length ? codes.join(' ') : '—'}</div>
    </div>
  );
}
