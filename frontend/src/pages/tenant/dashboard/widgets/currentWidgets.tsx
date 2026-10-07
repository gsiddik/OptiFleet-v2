import { t } from '../../../../i18n/i18n';
import { ColumnChart, DataTable, HBarChart, Kpi, SegmentBar, type Column } from '../components';
import { count } from '../format';
import { codeLabel, col, documentTypeLabel, itemTypeLabel } from '../labels';
import { NEUTRAL, ORDINAL_BLUE, SERIES, STATUS } from '../palette';
import type { WidgetDefinition } from '../WidgetCard';

type Row = Record<string, unknown>;
type Counts = Record<string, number>;

// ------------------------------------------------------------------ shared columns / links

const link = {
  vehicle: (r: Row) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null),
  workOrder: (r: Row) => (r.id ? `/app/work-orders/${r.id}` : null),
  breakdown: (r: Row) => (r.id ? `/app/breakdowns/${r.id}` : null),
  request: (r: Row) => (r.id ? `/app/maintenance-requests/${r.id}` : null),
  po: (r: Row) => (r.id ? `/app/purchase-orders/${r.id}` : null),
  transfer: (r: Row) => (r.id ? `/app/stock-transfers/${r.id}` : null),
  tire: (r: Row) => (r.tire_id ? `/app/tires/${r.tire_id}` : null),
};

const vehicleCol: Column<Row> = { key: 'registration_number', label: col('vehicle'), link: link.vehicle };
const statusCol: Column<Row> = { key: 'status', label: col('status'), value: (r) => codeLabel(String(r.status)) };

const woColumns: Column<Row>[] = [
  { key: 'wo_number', label: col('workOrder'), link: link.workOrder },
  statusCol,
  vehicleCol,
  { key: 'workshop_name', label: col('workshop') },
  { key: 'created_at', label: col('createdAt'), kind: 'datetime' },
  { key: 'age_days', label: col('age'), kind: 'days' },
];

/** Status distribution → segments with fixed categorical slots (color follows the status). */
function segments(counts: Counts, colors: Record<string, string>, context?: Parameters<typeof codeLabel>[1]) {
  return Object.keys(colors).map((key) => ({ key, label: codeLabel(key, context), value: counts[key] ?? 0, color: colors[key] }));
}

function countTable(counts: Counts, context?: Parameters<typeof codeLabel>[1]) {
  return {
    columns: [{ key: 'label', label: col('category') }, { key: 'value', label: col('count'), kind: 'num' as const }],
    rows: Object.entries(counts).map(([k, v]) => ({ id: k, label: codeLabel(k, context), value: v })),
  };
}

const sum = (c: Counts) => Object.values(c).reduce((a, b) => a + b, 0);

// ------------------------------------------------------------------ FL — fleet

const FLEET_COLORS = { ACTIVE: STATUS.good, IN_MAINTENANCE: SERIES[0], BREAKDOWN: STATUS.critical, OUT_OF_SERVICE: STATUS.serious, INACTIVE: NEUTRAL };

const fleetDetailColumns: Column<Row>[] = [{ key: 'registration_number', label: col('vehicle'), link: (r) => `/app/vehicles/${r.id}` }, { key: 'brand', label: col('brand') }, { key: 'model', label: col('model') },
  { key: 'branch_name', label: col('branch') }, statusCol];

const FL01: WidgetDefinition<{ total: number; by_status: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.total)} label={t('dashboard.widgets.fl01.kpiTotal')} />
        <Kpi value={count(env.data.by_status.ACTIVE)} label={codeLabel('ACTIVE')} />
        <Kpi value={count(env.data.by_status.BREAKDOWN)} label={codeLabel('BREAKDOWN')} tone={env.data.by_status.BREAKDOWN > 0 ? 'critical' : undefined} />
      </div>
      <SegmentBar segments={segments(env.data.by_status, FLEET_COLORS)} ariaLabel={t('dashboard.widgets.fl01.title')}
        onSelect={(status) => ctx.openDetail(codeLabel(status), { status }, fleetDetailColumns)} />
    </>
  ),
  table: (env) => countTable(env.data.by_status),
  detail: { columns: fleetDetailColumns },
};

const FL02: WidgetDefinition<{ branches: { branch_id: string; branch_name: string; total: number; by_status: Counts }[] }> = {
  size: 'm',
  isEmpty: (d) => d.branches.length === 0,
  render: (env, ctx) => (
    <HBarChart unit="count"
      data={env.data.branches.map((b) => ({ ...b.by_status, branch: b.branch_name ?? '—', branch_id: b.branch_id }))}
      categoryKey="branch"
      series={Object.entries(FLEET_COLORS).map(([key, color]) => ({ key, label: codeLabel(key), color }))}
      onSelect={(row) => ctx.openDetail(String(row.branch), { branch_id: row.branch_id }, fleetDetailColumns)} />
  ),
  table: (env) => ({
    columns: [{ key: 'branch_name', label: col('branch') }, ...Object.keys(FLEET_COLORS).map((k) => ({ key: k, label: codeLabel(k), kind: 'num' as const })),
      { key: 'total', label: col('total'), kind: 'num' as const }],
    rows: env.data.branches.map((b) => ({ id: b.branch_id, branch_name: b.branch_name, total: b.total, ...b.by_status })),
  }),
};

const breakdownColumns: Column<Row>[] = [
  vehicleCol,
  { key: 'branch_name', label: col('branch') },
  { key: 'severity', label: col('severity'), value: (r) => codeLabel(String(r.severity), 'severity') },
  { key: 'status', label: col('status'), value: (r) => codeLabel(String(r.status)), link: link.breakdown },
  { key: 'downtime_start_at', label: col('downtimeStart'), kind: 'datetime', value: (r) => r.downtime_start_at ?? r.reported_at },
  { key: 'running_hours', label: col('runningHours'), kind: 'num' },
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
];

const FL03: WidgetDefinition<{ count: number; by_severity: Counts; longest: Row[] }> = {
  size: 'm',
  render: (env) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.count)} label={t('dashboard.widgets.fl03.kpiOpen')} tone={env.data.count > 0 ? 'critical' : undefined} />
        <Kpi value={count(env.data.by_severity.IMMOBILIZED)} label={codeLabel('IMMOBILIZED', 'severity')} />
      </div>
      {env.data.count === 0 ? <div className="dash-kpi-sub">{t('dashboard.widgets.fl03.empty')}</div> : (
        <DataTable rows={env.data.longest} columns={breakdownColumns.filter((c) => ['registration_number', 'severity', 'running_hours'].includes(c.key))} />
      )}
      {env.data.count > 0 && <span className="dash-kpi-sub">{t('dashboard.widgets.fl03.note')}</span>}
    </>
  ),
  detail: { columns: breakdownColumns },
};

const DOC_BUCKETS = ['expired', 'd30', 'd60', 'd90'] as const;
const DOC_COLORS = [STATUS.critical, STATUS.serious, STATUS.warning, ORDINAL_BLUE[0]];

const docColumns: Column<Row>[] = [vehicleCol, { key: 'branch_name', label: col('branch') },
  { key: 'document_type', label: col('documentType'), value: (r) => documentTypeLabel(String(r.document_type)) },
  { key: 'document_number', label: col('documentNumber') }, { key: 'deadline', label: col('deadline'), kind: 'date' },
  { key: 'days_left', label: col('daysLeft'), kind: 'num' }];

const FL06: WidgetDefinition<{ expiry: Counts; extension: Counts }> = {
  size: 'm',
  isEmpty: (d) => sum(d.expiry) + sum(d.extension) === 0,
  render: (env, ctx) => (
    <div style={{ display: 'grid', gap: 14 }}>
      {(['expiry', 'extension'] as const).map((measure) => (
        <div key={measure} style={{ display: 'grid', gap: 6 }}>
          <strong style={{ fontSize: 13 }}>{t(`dashboard.widgets.fl06.${measure}`)}</strong>
          <SegmentBar ariaLabel={t(`dashboard.widgets.fl06.${measure}`)}
            segments={DOC_BUCKETS.map((b, i) => ({ key: b, label: t(`dashboard.buckets.doc_${b}`), value: env.data[measure][b] ?? 0, color: DOC_COLORS[i] }))}
            onSelect={(bucket) => ctx.openDetail(`${t(`dashboard.widgets.fl06.${measure}`)} · ${t(`dashboard.buckets.doc_${bucket}`)}`, { measure, bucket }, docColumns)} />
        </div>
      ))}
    </div>
  ),
  table: (env) => ({
    columns: [{ key: 'bucket', label: col('bucket') }, { key: 'expiry', label: t('dashboard.widgets.fl06.expiry'), kind: 'num' },
      { key: 'extension', label: t('dashboard.widgets.fl06.extension'), kind: 'num' }],
    rows: DOC_BUCKETS.map((b) => ({ id: b, bucket: t(`dashboard.buckets.doc_${b}`), expiry: env.data.expiry[b], extension: env.data.extension[b] })),
  }),
  detail: { params: { measure: 'expiry' }, columns: docColumns },
};

// ------------------------------------------------------------------ MT — maintenance

const SCHEDULE_COLORS = { UPCOMING: SERIES[0], DUE_SOON: STATUS.warning, DUE: STATUS.serious, OVERDUE: STATUS.critical };
const scheduleColumns: Column<Row>[] = [vehicleCol, { key: 'package_name', label: col('package') }, { key: 'branch_name', label: col('branch') },
  statusCol, { key: 'next_due_date', label: col('dueDate'), kind: 'date' }, { key: 'days_overdue', label: col('daysOverdue'), kind: 'num' },
  { key: 'next_due_odometer', label: col('dueOdometer'), kind: 'num' }, { key: 'current_odometer', label: col('currentOdometer'), kind: 'num' },
  { key: 'km_over', label: col('kmOver'), kind: 'num' }];

const MT01: WidgetDefinition<{ total: number; by_status: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.by_status.OVERDUE)} label={codeLabel('OVERDUE')} tone={env.data.by_status.OVERDUE > 0 ? 'critical' : undefined} />
        <Kpi value={count(env.data.by_status.DUE)} label={codeLabel('DUE')} />
        <Kpi value={count(env.data.by_status.DUE_SOON)} label={codeLabel('DUE_SOON')} />
      </div>
      <SegmentBar segments={segments(env.data.by_status, SCHEDULE_COLORS)} ariaLabel={t('dashboard.widgets.mt01.title')}
        onSelect={(status) => ctx.openDetail(codeLabel(status), { status }, scheduleColumns)} />
    </>
  ),
  table: (env) => countTable(env.data.by_status),
  detail: { columns: scheduleColumns },
};

const MT02: WidgetDefinition<{ count: number; items: Row[] }> = {
  size: 'm',
  isEmpty: (d) => d.count === 0,
  render: (env) => (
    <>
      <Kpi value={count(env.data.count)} label={t('dashboard.widgets.mt02.kpi')} tone="critical" />
      <DataTable rows={env.data.items} columns={scheduleColumns.filter((c) => ['registration_number', 'package_name', 'days_overdue', 'km_over'].includes(c.key))} />
    </>
  ),
  detail: { columns: scheduleColumns },
};

const REQUEST_COLORS = { DRAFT: NEUTRAL, SUBMITTED: SERIES[0], UNDER_REVIEW: SERIES[3], APPROVED: SERIES[2] };
const requestColumns: Column<Row>[] = [{ key: 'request_number', label: col('request'), link: link.request }, statusCol,
  { key: 'priority', label: col('priority'), value: (r) => codeLabel(String(r.priority)) }, vehicleCol, { key: 'branch_name', label: col('branch') },
  { key: 'created_at', label: col('createdAt'), kind: 'datetime' }];

const MT03: WidgetDefinition<{ total: number; by_status: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <Kpi value={count(env.data.total)} label={t('dashboard.widgets.mt03.kpi')} />
      <SegmentBar segments={segments(env.data.by_status, REQUEST_COLORS)} ariaLabel={t('dashboard.widgets.mt03.title')}
        onSelect={(status) => ctx.openDetail(codeLabel(status), { status }, requestColumns)} />
    </>
  ),
  table: (env) => countTable(env.data.by_status),
  detail: { columns: requestColumns },
};

// ------------------------------------------------------------------ WS — workshop

const WO_STATUS_ORDER = ['SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'EXTERNAL', 'REWORK', 'QC_PENDING'];

const WS01: WidgetDefinition<{ total: number; by_status: Counts; draft: number }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0 && d.draft === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.total)} label={t('dashboard.widgets.ws01.kpiOpen')} />
        <Kpi value={count(env.data.by_status.WAITING_PART)} label={codeLabel('WAITING_PART')} tone={env.data.by_status.WAITING_PART > 0 ? 'warning' : undefined} />
        <Kpi value={count(env.data.by_status.QC_PENDING)} label={codeLabel('QC_PENDING')} />
        <Kpi value={count(env.data.draft)} label={codeLabel('DRAFT')} />
      </div>
      <HBarChart unit="count" labelWidth={130}
        data={WO_STATUS_ORDER.filter((s) => (env.data.by_status[s] ?? 0) > 0).map((s) => ({ status: s, label: codeLabel(s), value: env.data.by_status[s] ?? 0 }))}
        categoryKey="label" series={[{ key: 'value', label: t('dashboard.columns.workOrders'), color: SERIES[0] }]}
        onSelect={(row) => ctx.openDetail(String(row.label), { status: row.status }, woColumns)} />
    </>
  ),
  table: (env) => countTable({ ...env.data.by_status, DRAFT: env.data.draft }),
  detail: { columns: woColumns },
};

const AGING = ['d0_3', 'd4_7', 'd8_14', 'd15_30', 'd30_plus'];
/** Fixed thresholds (owner decision): green ≤ 7 days, amber 8–14, red > 14. */
const AGING_COLORS: Record<string, string> = { d0_3: STATUS.good, d4_7: STATUS.good, d8_14: STATUS.warning, d15_30: STATUS.critical, d30_plus: STATUS.critical };

const WS02: WidgetDefinition<{ total: number; buckets: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.total)} label={t('dashboard.widgets.ws02.kpiOpen')} />
        <Kpi value={count((env.data.buckets.d15_30 ?? 0) + (env.data.buckets.d30_plus ?? 0))} label={t('dashboard.widgets.ws02.kpiOver14')}
          tone={(env.data.buckets.d15_30 ?? 0) + (env.data.buckets.d30_plus ?? 0) > 0 ? 'critical' : undefined} />
      </div>
      <ColumnChart unit="count" categoryKey="bucket" height={200}
        data={AGING.map((b) => ({ bucket: b, value: env.data.buckets[b] ?? 0 }))}
        labelFormatter={(b) => t(`dashboard.buckets.${b}`)} colorFor={(b) => AGING_COLORS[b]}
        series={[{ key: 'value', label: t('dashboard.columns.workOrders'), color: SERIES[0] }]}
        onSelect={(bucket) => ctx.openDetail(t(`dashboard.buckets.${bucket}`), { bucket }, woColumns)} />
      <span className="dash-kpi-sub">{t('dashboard.widgets.ws02.legend')}</span>
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'bucket', label: col('age') }, { key: 'value', label: col('count'), kind: 'num' }],
    rows: AGING.map((b) => ({ id: b, bucket: t(`dashboard.buckets.${b}`), value: env.data.buckets[b] ?? 0 })),
  }),
  detail: { columns: woColumns },
};

const WORKSPACE_COLORS = { AVAILABLE: STATUS.good, RESERVED: SERIES[0], OCCUPIED: SERIES[6], BLOCKED: STATUS.critical, UNDER_MAINTENANCE: STATUS.warning };

const WS05: WidgetDefinition<{ total: number; by_status: Counts; workshops: { workshop_id: string; workshop_name: string; by_status: Counts; total: number }[] }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.by_status.AVAILABLE)} label={codeLabel('AVAILABLE')} />
        <Kpi value={count(env.data.by_status.OCCUPIED)} label={codeLabel('OCCUPIED')} />
        <Kpi value={count(env.data.total)} label={t('dashboard.widgets.ws05.kpiTotal')} />
      </div>
      {env.data.workshops.length > 1 ? (
        <HBarChart unit="count" data={env.data.workshops.map((w) => ({ ...w.by_status, workshop: w.workshop_name }))} categoryKey="workshop"
          series={Object.entries(WORKSPACE_COLORS).map(([key, color]) => ({ key, label: codeLabel(key), color }))} />
      ) : (
        <SegmentBar segments={segments(env.data.by_status, WORKSPACE_COLORS)} ariaLabel={t('dashboard.widgets.ws05.title')} />
      )}
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'workshop_name', label: col('workshop') }, ...Object.keys(WORKSPACE_COLORS).map((k) => ({ key: k, label: codeLabel(k), kind: 'num' as const }))],
    rows: env.data.workshops.map((w) => ({ id: w.workshop_id, workshop_name: w.workshop_name, ...w.by_status })),
  }),
};

const waitingColumns: Column<Row>[] = [...woColumns.filter((c) => c.key !== 'age_days' && c.key !== 'status'),
  { key: 'oldest_requested_at', label: col('partRequestedAt'), kind: 'datetime' }, { key: 'waiting_days', label: col('waiting'), kind: 'days' },
  { key: 'pending_items', label: col('pendingItems'), kind: 'num' }];

const WS06: WidgetDefinition<{ count: number; items: Row[] }> = {
  size: 'm',
  isEmpty: (d) => d.count === 0,
  render: (env) => (
    <>
      <Kpi value={count(env.data.count)} label={t('dashboard.widgets.ws06.kpi')} tone="warning" />
      <DataTable rows={env.data.items} columns={waitingColumns.filter((c) => ['wo_number', 'registration_number', 'waiting_days', 'pending_items'].includes(c.key))} />
    </>
  ),
  detail: { columns: waitingColumns },
};

// ------------------------------------------------------------------ WH — warehouse

const STOCK_COLORS = { OUT: STATUS.critical, LOW: STATUS.warning, NORMAL: STATUS.good, NOT_SET: NEUTRAL };
export const stockColumns: Column<Row>[] = [{ key: 'product_name', label: col('product') }, { key: 'sku', label: col('sku') },
  { key: 'item_type', label: col('itemType'), value: (r) => itemTypeLabel(String(r.item_type ?? '')) },
  { key: 'warehouse_name', label: col('warehouse') }, { key: 'state', label: col('state'), value: (r) => codeLabel(String(r.state), 'stock') },
  { key: 'on_hand', label: col('onHand'), kind: 'num' }, { key: 'reorder_point', label: col('threshold'), kind: 'num' },
  { key: 'shortage', label: col('shortage'), kind: 'num' }, { key: 'uom', label: col('uom') },
  { key: 'ratio', label: col('ratioToThreshold'), value: (r) => (r.ratio == null ? null : `${r.ratio}%`) },
  { key: 'needed_by_work_order', label: col('neededByWorkOrder'), kind: 'bool' }];

const WH01: WidgetDefinition<{ total: number; by_state: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <Kpi value={count(env.data.by_state.OUT + env.data.by_state.LOW)} label={t('dashboard.widgets.wh01.kpiCritical')}
        tone={env.data.by_state.OUT > 0 ? 'critical' : undefined} />
      <SegmentBar segments={segments(env.data.by_state, STOCK_COLORS, 'stock')} ariaLabel={t('dashboard.widgets.wh01.title')}
        onSelect={(state) => (state === 'OUT' || state === 'LOW') && ctx.openDetail(codeLabel(state, 'stock'), { state }, stockColumns)} />
    </>
  ),
  table: (env) => countTable(env.data.by_state, 'stock'),
};


const transferColumns: Column<Row>[] = [{ key: 'transfer_number', label: col('transfer'), link: link.transfer }, statusCol,
  { key: 'from_warehouse', label: col('fromWarehouse') }, { key: 'to_warehouse', label: col('toWarehouse') },
  { key: 'dispatched_at', label: col('dispatchedAt'), kind: 'datetime' }, { key: 'days_in_transit', label: col('daysInTransit'), kind: 'num' },
  { key: 'received_at', label: col('receivedAt'), kind: 'datetime' }];

const WH03: WidgetDefinition<{ open_total: number; open_by_status: Counts; in_transit: number; in_transit_over_threshold: number; threshold_days: number; received_with_discrepancy: number; discrepancy_window_days: number }> = {
  size: 'm',
  isEmpty: (d) => d.open_total + d.in_transit + d.received_with_discrepancy === 0,
  render: (env, ctx) => (
    <div className="dash-kpi-row">
      <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }} onClick={() => ctx.openDetail(t('dashboard.widgets.wh03.open'), { view: 'open' }, transferColumns)}>
        <Kpi value={count(env.data.open_total)} label={t('dashboard.widgets.wh03.open')} />
      </button>
      <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }} onClick={() => ctx.openDetail(t('dashboard.widgets.wh03.inTransit'), { view: 'in_transit' }, transferColumns)}>
        <Kpi value={count(env.data.in_transit)} label={t('dashboard.widgets.wh03.inTransitOver', { days: env.data.threshold_days, n: env.data.in_transit_over_threshold })}
          tone={env.data.in_transit_over_threshold > 0 ? 'warning' : undefined} />
      </button>
      <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }} onClick={() => ctx.openDetail(t('dashboard.widgets.wh03.discrepancy'), { view: 'discrepancy' }, transferColumns)}>
        <Kpi value={count(env.data.received_with_discrepancy)} label={t('dashboard.widgets.wh03.discrepancyWindow', { days: env.data.discrepancy_window_days })}
          tone={env.data.received_with_discrepancy > 0 ? 'serious' : undefined} />
      </button>
    </div>
  ),
};

// ------------------------------------------------------------------ PR — procurement

const PR_COLORS = { DRAFT: NEUTRAL, SUBMITTED: SERIES[0], UNDER_REVIEW: SERIES[3], APPROVED: SERIES[2] };
const PO_COLORS = { DRAFT: NEUTRAL, SUBMITTED: SERIES[0], PENDING_APPROVAL: SERIES[3], APPROVED: SERIES[2], ISSUED: SERIES[6], PARTIALLY_RECEIVED: SERIES[4] };

const PR01: WidgetDefinition<{ purchase_requests: Counts | null; purchase_orders: Counts | null; goods_receipt_pending: number | null }> = {
  size: 'm',
  isEmpty: (d) => sum(d.purchase_requests ?? {}) + sum(d.purchase_orders ?? {}) === 0,
  render: (env) => (
    <div style={{ display: 'grid', gap: 14 }}>
      {env.data.purchase_requests && (
        <div style={{ display: 'grid', gap: 6 }}>
          <strong style={{ fontSize: 13 }}>{t('dashboard.widgets.pr01.requests', { n: sum(env.data.purchase_requests) })}</strong>
          <SegmentBar segments={segments(env.data.purchase_requests, PR_COLORS)} ariaLabel={t('dashboard.widgets.pr01.requests', { n: sum(env.data.purchase_requests) })} />
        </div>
      )}
      {env.data.purchase_orders && (
        <div style={{ display: 'grid', gap: 6 }}>
          <strong style={{ fontSize: 13 }}>{t('dashboard.widgets.pr01.orders', { n: sum(env.data.purchase_orders) })}</strong>
          <SegmentBar segments={segments(env.data.purchase_orders, PO_COLORS)} ariaLabel={t('dashboard.widgets.pr01.orders', { n: sum(env.data.purchase_orders) })} />
          <span className="dash-kpi-sub">{t('dashboard.widgets.pr01.grPending', { n: env.data.goods_receipt_pending ?? 0 })}</span>
        </div>
      )}
    </div>
  ),
  table: (env) => ({
    columns: [{ key: 'doc', label: col('document') }, { key: 'label', label: col('status') }, { key: 'value', label: col('count'), kind: 'num' }],
    rows: [
      ...Object.entries(env.data.purchase_requests ?? {}).map(([k, v]) => ({ id: `pr-${k}`, doc: t('dashboard.labels.purchaseRequest'), label: codeLabel(k), value: v })),
      ...Object.entries(env.data.purchase_orders ?? {}).map(([k, v]) => ({ id: `po-${k}`, doc: t('dashboard.labels.purchaseOrder'), label: codeLabel(k), value: v })),
    ],
  }),
};

const latePoColumns: Column<Row>[] = [{ key: 'po_number', label: col('purchaseOrder'), link: link.po }, { key: 'vendor_name', label: col('vendor') },
  { key: 'warehouse_name', label: col('warehouse') }, { key: 'expected_delivery_date', label: col('expectedDelivery'), kind: 'date' },
  { key: 'days_late', label: col('daysLate'), kind: 'num' }, statusCol];

const PR02: WidgetDefinition<{ count: number; items: Row[] }> = {
  size: 'm',
  isEmpty: (d) => d.count === 0,
  render: (env) => (
    <>
      <Kpi value={count(env.data.count)} label={t('dashboard.widgets.pr02.kpi')} tone="serious" />
      <DataTable rows={env.data.items} columns={latePoColumns.filter((c) => ['po_number', 'vendor_name', 'days_late'].includes(c.key))} />
    </>
  ),
  detail: { columns: latePoColumns },
};

// ------------------------------------------------------------------ TR — tires

const TIRE_GROUP_COLORS = { IN_SERVICE: SERIES[0], IN_STOCK: SERIES[2], IN_PROCESS: SERIES[3], AT_VENDOR: SERIES[6] };

const TR01: WidgetDefinition<{ total: number; by_group: Counts; by_status: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env) => (
    <>
      <Kpi value={count(env.data.total)} label={t('dashboard.widgets.tr01.kpi')} />
      <SegmentBar ariaLabel={t('dashboard.widgets.tr01.title')}
        segments={Object.entries(TIRE_GROUP_COLORS).map(([key, color]) => ({ key, label: t(`dashboard.labels.tireGroup_${key}`), value: env.data.by_group[key] ?? 0, color }))} />
    </>
  ),
  table: (env) => countTable(Object.fromEntries(Object.entries(env.data.by_status).filter(([, v]) => v > 0))),
};

const tireDueColumns: Column<Row>[] = [{ key: 'serial_number', label: col('serial'), link: link.tire }, vehicleCol, { key: 'position', label: col('position') },
  { key: 'tread_depth_mm', label: col('treadMm'), kind: 'num' }, { key: 'd_pull_mm', label: col('dPullMm'), kind: 'num' },
  { key: 'inspected_at', label: col('inspectedAt'), kind: 'datetime' }];
const tireUsedColumns: Column<Row>[] = [{ key: 'serial_number', label: col('serial'), link: link.tire },
  { key: 'recommendation', label: col('recommendation'), value: (r) => codeLabel(String(r.recommendation), 'tireRecommendation') },
  { key: 'remaining_tread_percent', label: col('remainingTreadPct'), kind: 'num' }, { key: 'inspected_at', label: col('inspectedAt'), kind: 'datetime' }];

const TR02: WidgetDefinition<{ installed_due: number; installed_items: Row[]; not_assessable: Counts; used_pending: number; used_pending_by_recommendation: Counts }> = {
  size: 'm',
  isEmpty: (d) => d.installed_due + d.used_pending + sum(d.not_assessable) === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }} onClick={() => ctx.openDetail(t('dashboard.widgets.tr02.installed'), { view: 'installed' }, tireDueColumns)}>
          <Kpi value={count(env.data.installed_due)} label={t('dashboard.widgets.tr02.installed')} tone={env.data.installed_due > 0 ? 'critical' : undefined} />
        </button>
        <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }} onClick={() => ctx.openDetail(t('dashboard.widgets.tr02.used'), { view: 'used' }, tireUsedColumns)}>
          <Kpi value={count(env.data.used_pending)} label={t('dashboard.widgets.tr02.used')} />
        </button>
      </div>
      {env.data.installed_items.length > 0 && (
        <DataTable rows={env.data.installed_items} columns={tireDueColumns.filter((c) => ['serial_number', 'registration_number', 'tread_depth_mm', 'd_pull_mm'].includes(c.key))} />
      )}
    </>
  ),
};

const vendorTireColumns: Column<Row>[] = [{ key: 'serial_number', label: col('serial'), link: link.tire },
  { key: 'type', label: col('type'), value: (r) => codeLabel(String(r.type), 'tireRecommendation') }, { key: 'cycle_number', label: col('cycle'), kind: 'num' },
  { key: 'vendor_name', label: col('vendor') }, { key: 'sent_at', label: col('sentAt'), kind: 'date' }, { key: 'days_at_vendor', label: col('daysAtVendor'), kind: 'num' }];

const TR03: WidgetDefinition<{ total: number; retread: number; repair: number; items: Row[] }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(env.data.retread)} label={codeLabel('RETREAD', 'tireRecommendation')} />
        <Kpi value={count(env.data.repair)} label={codeLabel('REPAIR', 'tireRecommendation')} />
      </div>
      <DataTable rows={env.data.items} columns={vendorTireColumns.filter((c) => ['serial_number', 'type', 'vendor_name', 'days_at_vendor'].includes(c.key))} />
    </>
  ),
  detail: { columns: vendorTireColumns },
};

/** Column sets reused by the Action Center drill-downs (same rows as the source widgets). */
export const SOURCE_COLUMNS: Record<string, Column<Row>[]> = {
  'FL-03': breakdownColumns, 'MT-02': scheduleColumns, 'WS-06': waitingColumns, 'WH-02': stockColumns,
  'PR-02': latePoColumns, 'TR-02': tireDueColumns, 'FL-06': docColumns, 'WH-03': transferColumns, 'AL-01': woColumns,
};

export const CURRENT_WIDGETS: Record<string, WidgetDefinition> = {
  'FL-01': FL01, 'FL-02': FL02, 'FL-03': FL03, 'FL-06': FL06,
  'MT-01': MT01, 'MT-02': MT02, 'MT-03': MT03,
  'WS-01': WS01, 'WS-02': WS02, 'WS-05': WS05, 'WS-06': WS06,
  'WH-01': WH01, 'WH-03': WH03,
  'PR-01': PR01, 'PR-02': PR02,
  'TR-01': TR01, 'TR-02': TR02, 'TR-03': TR03,
};

