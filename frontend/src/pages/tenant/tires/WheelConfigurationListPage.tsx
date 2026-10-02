import { useLocation, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { inputStyle } from '../../../components/FormField';
import { Pagination } from '../../../components/Pagination';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import { VEHICLE_TYPES, truckConfigurationTypeOption, vehicleTypeOption } from './wheel-configuration/vehicleTypes';
import type { ConfigurationMaster } from './wheel-configuration/masterTypes';

/**
 * Wheel Configuration masters — reusable axle/wheel templates per Vehicle Type (+ Truck
 * Configuration Type), versioned. Vehicles are linked to a configuration through its Vehicle
 * Mapping page (the only mapping flow).
 */
export function WheelConfigurationListPage() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const saved = (location.state as { saved?: { id: string; config_code: string; version_number: number } } | null)?.saved ?? null;
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<ConfigurationMaster>('/app/wheel-configuration-masters', { search: search || undefined, vehicle_type: typeFilter || undefined, page }, 0);

  const columns: Column<ConfigurationMaster>[] = [
    { key: 'type', header: 'Vehicle Type', render: (m) => vehicleTypeOption(m.vehicle_type)?.label ?? m.vehicle_type },
    { key: 'truck', header: 'Truck Configuration Type', render: (m) => (m.truck_configuration_type ? truckConfigurationTypeOption(m.truck_configuration_type)?.label : '—') },
    { key: 'code', header: 'Config Code', render: (m) => <strong style={{ fontFamily: 'monospace' }}>{m.config_code}</strong> },
    { key: 'wheels', header: 'Total Wheels', render: (m) => m.current_version?.total_wheels ?? '—' },
    { key: 'spare', header: 'Spare Tire', render: (m) => m.current_version?.spare_tires ?? '—' },
    { key: 'version', header: 'Version', render: (m) => m.current_version?.version_number ?? '—' },
    { key: 'status', header: 'Status', render: (m) => <StatusBadge status={m.status} /> },
    {
      key: 'actions',
      header: 'Action',
      render: (m) => (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <button className="btn-link" onClick={() => navigate(`/app/wheel-configurations/${m.id}`)}>
            View Detail
          </button>
          {hasPermission('tire.manage') && (
            <button className="btn-link" onClick={() => navigate(`/app/wheel-configurations/${m.id}/edit`)}>
              Edit
            </button>
          )}
          <button className="btn-link" onClick={() => navigate(`/app/wheel-configurations/${m.id}/vehicle-mapping`)}>
            Vehicle Mapping
          </button>
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
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
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
        <select
          aria-label="Vehicle Type filter"
          value={typeFilter}
          onChange={(e) => {
            setTypeFilter(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, maxWidth: 220 }}
        >
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
        <div data-master-list style={{ overflowX: 'auto' }}>
          <Table columns={columns} rows={data} />
        </div>
      )}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
