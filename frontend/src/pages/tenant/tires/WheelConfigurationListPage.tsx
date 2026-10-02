import { useLocation, useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WheelConfigurationItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatDateTime } from '../../../utils/date';
import { VEHICLE_TYPES, truckConfigurationTypeOption, vehicleTypeOption } from './wheel-configuration/vehicleTypes';
import { groupPositions, type ConfigurationMaster, type ConfigurationVersion } from './wheel-configuration/masterTypes';

/**
 * Wheel Configuration masters — reusable axle/wheel templates per Vehicle Type (+ Truck
 * Configuration Type), versioned. No vehicle is shown or assigned here; linking vehicles to a
 * configuration is a separate future feature.
 */
export function WheelConfigurationListPage() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const saved = (location.state as { saved?: { id: string; config_code: string; version_number: number } } | null)?.saved ?? null;
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [viewing, setViewing] = useState<string | null>(null);
  const { data, loading, error } = useApiList<ConfigurationMaster>('/app/wheel-configuration-masters', { search: search || undefined, vehicle_type: typeFilter || undefined }, 0);

  const columns: Column<ConfigurationMaster>[] = [
    { key: 'type', header: 'Vehicle Type', render: (m) => vehicleTypeOption(m.vehicle_type)?.label ?? m.vehicle_type },
    { key: 'truck', header: 'Truck Configuration Type', render: (m) => (m.truck_configuration_type ? truckConfigurationTypeOption(m.truck_configuration_type)?.label : '—') },
    { key: 'code', header: 'Config Code', render: (m) => <strong style={{ fontFamily: 'monospace' }}>{m.config_code}</strong> },
    { key: 'version', header: 'Version', render: (m) => m.current_version?.version_number ?? '—' },
    { key: 'axles', header: 'Total Axles', render: (m) => m.current_version?.total_axles ?? '—' },
    { key: 'wheels', header: 'Total Wheels', render: (m) => m.current_version?.total_wheels ?? '—' },
    { key: 'status', header: 'Status', render: (m) => <StatusBadge status={m.status} /> },
    { key: 'updated', header: 'Updated At', render: (m) => formatDateTime(m.updated_at) },
    {
      key: 'actions',
      header: '',
      render: (m) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn-link" onClick={() => setViewing(m.id)}>
            View
          </button>
          {hasPermission('tire.manage') && (
            <button className="btn-link" onClick={() => navigate(`/app/wheel-configurations/${m.id}/edit`)}>
              Edit
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Wheel Configuration</h1>
      {saved && (
        <div data-save-success role="status" style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, padding: '8px 12px', marginBottom: 14 }}>
          Saved configuration {saved.config_code} (version {saved.version_number}).
        </div>
      )}
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('tire.manage') ? (
            // Wheel positions are generated from a wheels configuration (owner decision): there is no
            // manual one-by-one creation.
            <button className="btn-primary" onClick={() => navigate('/app/wheel-configurations/new')}>
              New Wheels Configuration
            </button>
          ) : null
        }
      >
        <select aria-label="Vehicle Type filter" value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} style={{ ...inputStyle, maxWidth: 220 }}>
          <option value="">All vehicle types</option>
          {VEHICLE_TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </select>
      </Toolbar>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No wheel configurations yet." />}
      {!error && !loading && data.length > 0 && (
        <div data-master-list>
          <Table columns={columns} rows={data} />
        </div>
      )}

      {viewing && <ConfigurationDetailModal masterId={viewing} onClose={() => setViewing(null)} />}

      <LegacyCategoryPositions />
    </div>
  );
}

/** Versions of one configuration (newest first) with each version's generated positions and diff. */
function ConfigurationDetailModal({ masterId, onClose }: { masterId: string; onClose: () => void }) {
  const [master, setMaster] = useState<ConfigurationMaster | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [versionId, setVersionId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get(`/app/wheel-configuration-masters/${masterId}`)
      .then((res) => !cancelled && setMaster(res.data.data))
      .catch((e) => !cancelled && setError(extractApiError(e).message));
    return () => {
      cancelled = true;
    };
  }, [masterId]);

  const versions = master?.versions ?? [];
  const version: ConfigurationVersion | undefined = versions.find((v) => v.id === versionId) ?? versions[0];
  const title = master ? `${vehicleTypeOption(master.vehicle_type)?.label ?? master.vehicle_type}${master.truck_configuration_type ? ` · ${truckConfigurationTypeOption(master.truck_configuration_type)?.label}` : ''} · ${master.config_code}` : 'Wheel Configuration';

  return (
    <Modal open title={title} onClose={onClose} width={600}>
      <div data-config-detail>
        {error && <ErrorState message={error} />}
        {!error && !master && <LoadingState />}
        {version && (
          <>
            <FormField label="Version">
              <select aria-label="Version" value={version.id} onChange={(e) => setVersionId(e.target.value)} style={inputStyle}>
                {versions.map((v) => (
                  <option key={v.id} value={v.id}>
                    Version {v.version_number} · {v.config_code} · {v.status}
                  </option>
                ))}
              </select>
            </FormField>
            <p style={{ fontSize: 13, margin: '0 0 8px' }}>
              {version.total_axles} axles · {version.total_wheels} wheels · {version.spare_tires} spare · saved {formatDateTime(version.created_at)}
            </p>
            <table data-version-positions style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginBottom: 10 }}>
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
              <div data-version-diff style={{ fontSize: 12, background: '#f9fafb', borderRadius: 6, padding: 8 }}>
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
          </>
        )}
      </div>
    </Modal>
  );
}

/**
 * Pre-existing per-category wheel positions (created before Wheel Configuration masters existed;
 * tire installation still validates against them). Kept read/edit/delete-able, collapsed, until the
 * future vehicle → configuration assignment feature replaces them.
 */
function LegacyCategoryPositions() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [editing, setEditing] = useState<WheelConfigurationItem | null>(null);
  const [deleting, setDeleting] = useState<WheelConfigurationItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const { data, loading, error } = useApiList<WheelConfigurationItem>('/app/wheel-configurations', {}, reloadKey);

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/wheel-configurations/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<WheelConfigurationItem>[] = [
    { key: 'category', header: 'Vehicle Category', render: (w) => w.vehicle_category?.name ?? w.vehicle_category_id },
    { key: 'position', header: 'Position Code', render: (w) => w.position_code },
    { key: 'label', header: 'Label', render: (w) => w.label },
    { key: 'axle', header: 'Axle #', render: (w) => w.axle_number ?? '—' },
    {
      key: 'actions',
      header: '',
      render: (w) =>
        w.tenant_id && hasPermission('tire.manage') ? (
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn-link" onClick={() => setEditing(w)}>
              Edit
            </button>
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(w)}>
              Delete
            </button>
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#9ca3af' }}>{w.tenant_id ? '' : 'Platform default'}</span>
        ),
    },
  ];

  if (!loading && !error && data.length === 0) return null;

  return (
    <details data-legacy-positions style={{ marginTop: 24 }}>
      <summary style={{ cursor: 'pointer', fontSize: 14, fontWeight: 600, color: '#374151' }}>Legacy category wheel positions ({data.length})</summary>
      <p style={{ fontSize: 12, color: '#6b7280' }}>Positions created before Wheel Configuration templates. Tire installation still checks against them.</p>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && <Table columns={columns} rows={data} />}

      {editing && (
        <EditModal
          config={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Delete Wheel Position"
        message={deleteError ?? `Delete position "${deleting?.label}"? This cannot be undone.`}
        confirmLabel="Delete"
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
        onConfirm={confirmDelete}
      />
    </details>
  );
}

function EditModal({ config, onClose, onSaved }: { config: WheelConfigurationItem; onClose: () => void; onSaved: () => void }) {
  const [positionCode, setPositionCode] = useState(config.position_code);
  const [label, setLabel] = useState(config.label);
  const [axleNumber, setAxleNumber] = useState(config.axle_number != null ? String(config.axle_number) : '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/wheel-configurations/${config.id}`, {
        position_code: positionCode, label, axle_number: axleNumber || null,
      });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Edit Wheel Position" onClose={onClose}>
      <FormField label="Position Code" errors={errors.position_code}>
        <input value={positionCode} onChange={(e) => setPositionCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Label" errors={errors.label}>
        <input value={label} onChange={(e) => setLabel(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Axle Number" errors={errors.axle_number}>
        <NumericInput value={axleNumber} onChange={(e) => setAxleNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !positionCode || !label} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
