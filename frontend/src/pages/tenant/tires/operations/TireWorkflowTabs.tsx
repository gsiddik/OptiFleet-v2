import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Pagination } from '../../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../../components/States';
import { StatusBadge } from '../../../../components/StatusBadge';
import { Table, type Column } from '../../../../components/Table';
import { Toolbar } from '../../../../components/Toolbar';
import { useApiList } from '../../../../hooks/useApiList';
import { useAuth } from '../../../../auth/AuthContext';
import { formatDateTime } from '../../../../utils/date';
import type { TireActivityItem, TireActivityType, TireItem } from '../../../../types';

export interface WorkflowTab {
  key: string;
  label: string;
  /** Any one of these shows the tab; the backend still authorises every action. */
  permissions: string[];
  /** Tires this step applies to (current_status values), e.g. IN_STOCK + RESERVED for Installation. */
  statuses: string[];
  candidatesTitle: string;
  actionLabel: string;
  /** Section of the physical tire page that performs the step. */
  anchor: string;
  activityTypes: TireActivityType[];
  activityTitle: string;
}

/**
 * A tire workflow page (Tire Operations, Used Tire Management): one tab per step, shown only with
 * the step's permission. Each tab lists the tires the step applies to — the action opens the
 * physical tire page at that step, where the existing, backend-validated forms run — and the
 * step's recent activity. The active tab is kept in ?tab= so it can be linked to.
 */
export function TireWorkflowTabs({ title, intro, tabs }: { title: string; intro: string; tabs: WorkflowTab[] }) {
  const { hasPermission } = useAuth();
  const [params, setParams] = useSearchParams();
  const visible = tabs.filter((t) => t.permissions.some(hasPermission));
  const active = visible.find((t) => t.key === params.get('tab')) ?? visible[0];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 6 }}>{title}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 14 }}>{intro}</p>
      <div role="tablist" aria-label={title} style={{ display: 'flex', marginBottom: 14, borderBottom: '1px solid #e5e7eb', flexWrap: 'wrap' }}>
        {visible.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={active?.key === t.key}
            onClick={() => setParams({ tab: t.key }, { replace: true })}
            style={{
              padding: '8px 16px',
              fontSize: 14,
              fontWeight: 600,
              background: 'none',
              border: 'none',
              borderBottom: active?.key === t.key ? '2px solid #1d4ed8' : '2px solid transparent',
              color: active?.key === t.key ? '#1d4ed8' : '#6b7280',
              cursor: 'pointer',
            }}
          >
            {t.label}
          </button>
        ))}
      </div>
      {active ? <WorkflowTabPanel key={active.key} tab={active} /> : <EmptyState label="You have no tire workflow permissions." />}
    </div>
  );
}

function WorkflowTabPanel({ tab }: { tab: WorkflowTab }) {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<TireItem>('/app/tires', { current_status: tab.statuses.join(','), search: search || undefined, page, per_page: 10 }, 0);

  const columns: Column<TireItem>[] = [
    { key: 'serial', header: 'Serial', render: (t) => <Link to={`/app/tires/${t.id}`}>{t.serial_number}</Link> },
    { key: 'product', header: 'Product', render: (t) => t.product?.name ?? '—' },
    { key: 'vehicle', header: 'Vehicle', render: (t) => t.current_vehicle?.registration_number ?? '—' },
    { key: 'position', header: 'Position', render: (t) => t.current_position ?? '—' },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.current_status} /> },
    { key: 'action', header: 'Action', render: (t) => <Link to={`/app/tires/${t.id}#${tab.anchor}`}>{tab.actionLabel}</Link> },
  ];

  return (
    <div role="tabpanel" data-workflow-tab={tab.key}>
      <section className="card" style={{ marginBottom: 16 }} data-workflow-candidates>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>
          {tab.candidatesTitle} {meta && <span style={{ color: '#6b7280', fontWeight: 400 }}>({meta.total})</span>}
        </h3>
        <Toolbar
          search={search}
          onSearchChange={(value) => {
            setSearch(value);
            setPage(1);
          }}
        />
        {error && <ErrorState message={error} />}
        {!error && loading && <LoadingState />}
        {!error && !loading && data.length === 0 && <EmptyState label="No tires." />}
        {!error && !loading && data.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <Table columns={columns} rows={data} />
          </div>
        )}
        {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
      </section>
      <section className="card" data-workflow-activity>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{tab.activityTitle}</h3>
        <TireActivityTable types={tab.activityTypes} perPage={10} />
      </section>
    </div>
  );
}

const ACTIVITY_LABELS: Record<TireActivityType, string> = {
  INSTALLATION: 'Installation',
  ROTATION: 'Rotation',
  INSPECTION: 'Inspection',
  REMOVAL: 'Removal',
  RETREAD: 'Retread',
  REPAIR: 'Repair',
  SCRAP: 'Scrapped',
};

/** Tire History events (newest first), server-side paginated; shared by History and the workflow tabs. */
export function TireActivityTable({ types, search, perPage = 20 }: { types: TireActivityType[]; search?: string; perPage?: number }) {
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<TireActivityItem>('/app/tire-activity', { type: types.length ? types : undefined, search: search || undefined, page, per_page: perPage }, 0);
  const rows = data.map((e) => ({ ...e, id: `${e.type}-${e.id}` }));

  const columns: Column<TireActivityItem>[] = [
    { key: 'when', header: 'When', render: (e) => formatDateTime(e.occurred_at) },
    { key: 'event', header: 'Event', render: (e) => ACTIVITY_LABELS[e.type] },
    { key: 'serial', header: 'Serial', render: (e) => <Link to={`/app/tires/${e.tire_id}`}>{e.serial_number}</Link> },
    { key: 'product', header: 'Product', render: (e) => (e.product_name ? <Link to={`/app/tires/products/${e.product_id}`}>{e.product_name}</Link> : '—') },
    { key: 'vehicle', header: 'Vehicle', render: (e) => (e.vehicle_id ? <Link to={`/app/vehicles/${e.vehicle_id}`}>{e.registration_number ?? '—'}</Link> : '—') },
    { key: 'position', header: 'Position', render: (e) => e.position ?? '—' },
    { key: 'odometer', header: 'KM', render: (e) => e.odometer ?? '—' },
    { key: 'status', header: 'Result', render: (e) => [e.status, e.detail].filter(Boolean).join(' · ') || '—' },
  ];

  if (error) return <ErrorState message={error} />;
  if (loading && rows.length === 0) return <LoadingState />;
  if (rows.length === 0) return <EmptyState label="No activity yet." />;
  return (
    <div data-tire-activity>
      <div style={{ overflowX: 'auto' }}>
        <Table columns={columns} rows={rows} />
      </div>
      {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
