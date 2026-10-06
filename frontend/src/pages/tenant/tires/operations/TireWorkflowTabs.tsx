import { useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Pagination } from '../../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../../components/States';
import { StatusBadge } from '../../../../components/StatusBadge';
import { Table, type Column } from '../../../../components/Table';
import { Toolbar } from '../../../../components/Toolbar';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { useApiList } from '../../../../hooks/useApiList';
import { useAuth } from '../../../../auth/AuthContext';
import { formatDateTime } from '../../../../utils/date';
import type { TireActivityItem, TireActivityType, TireItem } from '../../../../types';
import { TireHistoryModal } from './TireHistoryModal';
import { formatHours, formatKm } from './tireOperationFormat';
import { statusLabel } from '../../../../i18n/statusRegistry';
import { labelText, t as tt, translatedRecord } from '../../../../i18n/i18n';

export interface WorkflowTab {
  key: string;
  label: string;
  /** Translation key of the tab label. */
  labelKey?: string;
  /** Any one of these shows the tab; the backend still authorises every action. */
  permissions: string[];
  /** Tires this step applies to (current_status values), e.g. IN_STOCK + RESERVED for Installation. */
  statuses: string[];
  candidatesTitle: string;
  actionLabel: string;
  /** Section of the physical tire page that performs the step (per tire when it depends on the status). */
  anchor: string | ((tire: TireItem) => string);
  /** Where the action goes instead of the tire page anchor (e.g. the inspection page). */
  actionHref?: (tire: TireItem) => string;
  /** The serial number opens the Tire History popup instead of the tire page. */
  serialOpensHistory?: boolean;
  activityTypes: TireActivityType[];
  activityTitle: string;
  /** Replaces the generic candidates list (e.g. the Retread cycle panel). */
  panel?: ReactNode;
  /** Activity lists only completed retread / repair cycles. */
  completedCycles?: boolean;
  /** The panel already lists the step's recent records (e.g. Recently Scrapped). */
  hideActivity?: boolean;
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
            {labelText(t)}
          </button>
        ))}
      </div>
      {active ? <WorkflowTabPanel key={active.key} tab={active} /> : <EmptyState label={tt('tire.empty.youNoTireWorkflowPermissions')} />}
    </div>
  );
}

function WorkflowTabPanel({ tab }: { tab: WorkflowTab }) {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [historyOf, setHistoryOf] = useState<TireItem | null>(null);
  const { data, meta, loading, error } = useApiList<TireItem>('/app/tires', { current_status: tab.statuses.join(','), search: search || undefined, page, per_page: 10 }, 0);

  const columns: Column<TireItem>[] = [
    {
      key: 'serial',
      header: tt('tire.fields.serial'),
      render: (t) =>
        tab.serialOpensHistory ? (
          <button type="button" className="btn-link" data-history-open={t.serial_number} onClick={() => setHistoryOf(t)} style={{ fontFamily: 'monospace' }}>
            {t.serial_number}
          </button>
        ) : (
          <Link to={`/app/tires/${t.id}`}>{t.serial_number}</Link>
        ),
    },
    { key: 'product', header: tt('common.fields.product'), render: (t) => t.product?.name ?? '—' },
    { key: 'vehicle', header: tt('common.fields.vehicle'), render: (t) => t.current_vehicle?.registration_number ?? '—' },
    { key: 'position', header: tt('inventory.placeholders.position'), render: (t) => t.current_position ?? '—' },
    { key: 'status', header: tt('common.fields.status'), render: (t) => <StatusBadge status={t.current_status} /> },
    {
      key: 'action',
      header: tt('common.fields.action'),
      render: (t) => <Link to={tab.actionHref ? tab.actionHref(t) : `/app/tires/${t.id}#${typeof tab.anchor === 'function' ? tab.anchor(t) : tab.anchor}`}>{tab.actionLabel}</Link>,
    },
  ];

  return (
    <div role="tabpanel" data-workflow-tab={tab.key}>
      {tab.panel ?? (
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
        {!error && !loading && data.length === 0 && <EmptyState label={tt('tire.empty.noTires')} />}
        {!error && !loading && data.length > 0 && (
          <div style={{ overflowX: 'auto' }}>
            <Table columns={columns} rows={data} />
          </div>
        )}
        {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
      </section>
      )}
      {!tab.hideActivity && (
      <section className="card" data-workflow-activity>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{tab.activityTitle}</h3>
        <TireActivityTable types={tab.activityTypes} perPage={10} completedCycles={tab.completedCycles} />
      </section>
      )}
      {historyOf && <TireHistoryModal tireId={historyOf.id} serial={historyOf.serial_number} onClose={() => setHistoryOf(null)} />}
    </div>
  );
}

const ACTIVITY_LABELS: Record<TireActivityType, string> = translatedRecord({
  INSTALLATION: 'Installation',
  ROTATION: 'Rotation',
  INSPECTION: 'Inspection',
  REMOVAL: 'Removal',
  RETREAD: 'Retread',
  REPAIR: 'Repair',
  SCRAP: 'Scrapped',
}, { INSTALLATION: 'tire.activity.installation', ROTATION: 'tire.activity.rotation', INSPECTION: 'breadcrumb.inspection', REMOVAL: 'tire.activity.removal', RETREAD: 'tire.status.retread', REPAIR: 'inventory.fields.repair', SCRAP: 'tire.status.scrap' });

/** Retread / repair cycle states (the cycle model's own steps). */
const CYCLE_STAGE_LABELS: Record<string, string> = translatedRecord({
  SENT: 'Sent to vendor',
  RECEIVED: 'Received',
  FINAL_INSPECTED: 'Re-inspection',
  APPROVED: 'Completed',
  REJECTED: 'Rejected',
}, { SENT: 'tire.cycleStage.sent', RECEIVED: 'tire.status.received', FINAL_INSPECTED: 'tire.cycleStage.finalInspected', APPROVED: 'tire.status.approved', REJECTED: 'tire.status.rejected' });

function cycleEvent(e: TireActivityItem): string {
  const stage = e.stage ? (CYCLE_STAGE_LABELS[e.stage] ?? e.stage) : null;
  const result = e.stage === 'APPROVED' && e.status && e.status !== e.stage ? ` → ${statusLabel(e.status)}` : '';
  return [ACTIVITY_LABELS[e.type], stage].filter(Boolean).join(' · ') + result;
}

/** Tire History events (newest first), server-side paginated; shared by History and the workflow tabs. */
export function TireActivityTable({ types, search, perPage = 20, completedCycles = false }: { types: TireActivityType[]; search?: string; perPage?: number; completedCycles?: boolean }) {
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<TireActivityItem>(
    '/app/tire-activity',
    { type: types.length ? types : undefined, search: search || undefined, page, per_page: perPage, completed_cycles: completedCycles ? 1 : undefined },
    0,
  );
  const rows = data.map((e) => ({ ...e, id: `${e.type}-${e.id}` }));

  const vehicleCell = (e: TireActivityItem) => (e.vehicle_id ? <Link to={`/app/vehicles/${e.vehicle_id}`}>{e.registration_number ?? '—'}</Link> : '—');

  // Used Tire Management → Retread: the vehicle / position the tire was last on before the cycle and
  // the tire's own usage up to then (backend values; "—" when unknown).
  const cycleColumns: Column<TireActivityItem>[] = [
    { key: 'when', header: tt('common.fields.when'), render: (e) => formatDateTime(e.occurred_at) },
    { key: 'event', header: tt('common.fields.event'), render: (e) => cycleEvent(e) },
    { key: 'serial', header: tt('tire.fields.serial'), render: (e) => <Link to={`/app/tires/${e.tire_id}`}>{e.serial_number}</Link> },
    { key: 'product', header: tt('common.fields.product'), render: (e) => (e.product_name ? <Link to={`/app/tires/products/${e.product_id}`}>{e.product_name}</Link> : '—') },
    { key: 'vehicle', header: tt('common.fields.vehicle'), render: vehicleCell },
    { key: 'position', header: tt('inventory.placeholders.position'), render: (e) => <PositionLabel code={e.position} /> },
    { key: 'usage_km', header: tt('tire.fields.usageKm'), render: (e) => formatKm(e.usage_km) },
    { key: 'usage_hours', header: tt('tire.fields.usageHours'), render: (e) => formatHours(e.usage_hours) },
  ];

  const columns: Column<TireActivityItem>[] = completedCycles ? cycleColumns : [
    { key: 'when', header: tt('common.fields.when'), render: (e) => formatDateTime(e.occurred_at) },
    { key: 'event', header: tt('common.fields.event'), render: (e) => ACTIVITY_LABELS[e.type] },
    { key: 'serial', header: tt('tire.fields.serial'), render: (e) => <Link to={`/app/tires/${e.tire_id}`}>{e.serial_number}</Link> },
    { key: 'product', header: tt('common.fields.product'), render: (e) => (e.product_name ? <Link to={`/app/tires/products/${e.product_id}`}>{e.product_name}</Link> : '—') },
    { key: 'vehicle', header: tt('common.fields.vehicle'), render: vehicleCell },
    { key: 'position', header: tt('inventory.placeholders.position'), render: (e) => e.position ?? '—' },
    { key: 'odometer', header: 'KM', render: (e) => e.odometer ?? '—' },
    { key: 'status', header: tt('tire.fields.result'), render: (e) => [e.status, e.detail].filter(Boolean).join(' · ') || '—' },
  ];

  if (error) return <ErrorState message={error} />;
  if (loading && rows.length === 0) return <LoadingState />;
  if (rows.length === 0) return <EmptyState label={tt('tire.empty.noActivityYet')} />;
  return (
    <div data-tire-activity>
      <div style={{ overflowX: 'auto' }}>
        <Table columns={columns} rows={rows} />
      </div>
      {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
