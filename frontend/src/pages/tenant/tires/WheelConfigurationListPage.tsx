import { useLocation, useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
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
