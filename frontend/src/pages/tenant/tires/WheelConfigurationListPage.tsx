import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useCallback, useEffect, useRef, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { ScrollTable } from '../../../components/ScrollTable';
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
  // Arrived from Vehicle Detail → Wheels Configuration: list only configurations the backend says
  // this vehicle is compatible with; assignment still happens on the Vehicle Mapping page.
  const [searchParams] = useSearchParams();
  const vehicleId = searchParams.get('vehicle');
  const [vehicleLabel, setVehicleLabel] = useState<string | null>(null);
  useEffect(() => {
    if (!vehicleId) return;
    let cancelled = false;
    apiClient
      .get(`/app/vehicles/${vehicleId}`)
      .then((res) => !cancelled && setVehicleLabel(res.data.data.registration_number))
      .catch(() => !cancelled && setVehicleLabel(null));
    return () => {
      cancelled = true;
    };
  }, [vehicleId]);
  const { data, meta, loading, error } = useApiList<ConfigurationMaster>(
    '/app/wheel-configuration-masters',
    { search: search || undefined, vehicle_type: typeFilter || undefined, compatible_vehicle_id: vehicleId || undefined, page },
    0,
  );
  const [expanded, setExpanded] = useState<ReadonlySet<string>>(new Set());
  const toggle = (id: string) =>
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  const mappingLink = (masterId: string) => `/app/wheel-configurations/${masterId}/vehicle-mapping${vehicleId ? `?vehicle=${vehicleId}` : ''}`;

  const columns: Column<ConfigurationMaster>[] = [
    { key: 'type', header: 'Vehicle Type', render: (m) => vehicleTypeOption(m.vehicle_type)?.label ?? m.vehicle_type },
    { key: 'truck', header: 'Truck Configuration Type', render: (m) => (m.truck_configuration_type ? truckConfigurationTypeOption(m.truck_configuration_type)?.label : '—') },
    { key: 'code', header: 'Config Code', render: (m) => <strong style={{ fontFamily: 'monospace' }}>{m.config_code}</strong> },
    { key: 'wheels', header: 'Total Wheels', render: (m) => m.current_version?.total_wheels ?? '—' },
    { key: 'spare', header: 'Spare Tire', render: (m) => m.current_version?.spare_tires ?? '—' },
    { key: 'version', header: 'Version', render: (m) => m.current_version?.version_number ?? '—' },
    {
      key: 'vehicles',
      header: 'Number of Vehicle',
      render: (m) =>
        (m.mapped_vehicle_count ?? 0) > 0 ? (
          <button
            type="button"
            className="btn-link"
            aria-expanded={expanded.has(m.id)}
            aria-controls={`mapped-${m.id}`}
            data-toggle-vehicles={m.id}
            onClick={() => toggle(m.id)}
            style={{ fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 6 }}
          >
            <span aria-hidden="true" style={{ display: 'inline-block', transition: 'transform 0.15s', transform: expanded.has(m.id) ? 'rotate(90deg)' : 'none' }}>
              ▸
            </span>
            {m.mapped_vehicle_count}
          </button>
        ) : (
          <span style={{ color: '#9ca3af' }}>0</span>
        ),
    },
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
          <button className="btn-link" onClick={() => navigate(mappingLink(m.id))}>
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
      {vehicleId && (
        <div data-vehicle-context role="status" style={{ fontSize: 13, color: '#1e3a8a', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 6, padding: '8px 12px', marginBottom: 14, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
          <span>
            Choosing a configuration for vehicle <strong>{vehicleLabel ?? 'selected vehicle'}</strong> — showing only compatible configurations. Open <em>Vehicle Mapping</em> to assign it.
          </span>
          <button className="btn-link" onClick={() => navigate(`/app/vehicles/${vehicleId}`)}>
            Back to vehicle
          </button>
          <button className="btn-link" onClick={() => navigate('/app/wheel-configurations')}>
            Show all configurations
          </button>
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
      {!error && !loading && data.length === 0 && <EmptyState label={vehicleId ? 'No configuration matches this vehicle’s type, axles and wheels.' : 'No wheel configurations yet.'} />}
      {!error && !loading && data.length > 0 && (
        <div data-master-list style={{ overflowX: 'auto' }}>
          <Table columns={columns} rows={data} expandedIds={expanded} renderExpanded={(m) => <MappedVehicles masterId={m.id} total={m.mapped_vehicle_count ?? 0} />} />
        </div>
      )}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}

interface MappedVehicle {
  mapping_id: string;
  id: string;
  registration_number: string;
  brand: string | null;
  model: string | null;
  vehicle_type: string | null;
  branch_name: string | null;
  version_number: number;
  config_code: string;
}

/** Vehicles mapped to one configuration: at most 5 rows visible, more pages loaded on scroll. */
function MappedVehicles({ masterId, total }: { masterId: string; total: number }) {
  const [rows, setRows] = useState<MappedVehicle[] | null>(null);
  const [lastPage, setLastPage] = useState(1);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const loading = useRef(false);

  const fetchPage = useCallback(
    (next: number) => {
      loading.current = true;
      return apiClient
        .get(`/app/wheel-configuration-masters/${masterId}/mapped-vehicles`, { params: { page: next, per_page: 25 } })
        .then((res) => {
          setRows((prev) => (next === 1 ? res.data.data : [...(prev ?? []), ...res.data.data]));
          setPage(res.data.meta.current_page);
          setLastPage(res.data.meta.last_page);
        })
        .catch((e) => setError(extractApiError(e).message))
        .finally(() => {
          loading.current = false;
        });
    },
    [masterId],
  );
  useEffect(() => {
    fetchPage(1);
  }, [fetchPage]);

  if (error) return <ErrorState message={error} />;
  return (
    <div id={`mapped-${masterId}`} data-mapped-vehicles={masterId}>
      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
        Mapped vehicles {rows ? `(${rows.length} of ${total} shown)` : ''}
      </div>
      <ScrollTable
        dataAttr={`mapped-${masterId}`}
        rows={rows ?? []}
        rowKey={(v) => v.mapping_id}
        emptyLabel={rows === null ? 'Loading…' : 'No mapped vehicles.'}
        onReachEnd={() => !loading.current && page < lastPage && fetchPage(page + 1)}
        columns={[
          { header: 'Registration Number', cell: (v) => <Link to={`/app/vehicles/${v.id}`}>{v.registration_number}</Link> },
          { header: 'Brand / Model', cell: (v) => [v.brand, v.model].filter(Boolean).join(' ') || '—' },
          { header: 'Vehicle Type', cell: (v) => v.vehicle_type ?? '—' },
          { header: 'Branch', cell: (v) => v.branch_name ?? '—' },
          { header: 'Version', cell: (v) => `v${v.version_number}` },
        ]}
      />
    </div>
  );
}
