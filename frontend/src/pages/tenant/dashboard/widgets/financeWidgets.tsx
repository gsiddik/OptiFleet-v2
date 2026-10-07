import { t } from '../../../../i18n/i18n';
import { ColumnChart, HBarChart, Kpi, Legend, StateBox, type Column } from '../components';
import { count, monthLabel, money } from '../format';
import { col } from '../labels';
import { SERIES, STATUS } from '../palette';
import { formatDate } from '../../../../utils/date';
import type { WidgetDefinition } from '../WidgetCard';

type Row = Record<string, unknown>;
type SourceSums = { PARTS: string; EXTERNAL_SERVICE: string; EXTERNAL_WO: string; total: string };

// ------------------------------------------------------------------ service cost (FN-01/02/03)

/** Fixed series slots: color follows the cost source, never its rank. */
const SOURCE_SERIES = [
  { key: 'PARTS', color: SERIES[0] },
  { key: 'EXTERNAL_SERVICE', color: SERIES[1] },
  { key: 'EXTERNAL_WO', color: SERIES[2] },
] as const;

const sourceLabel = (key: string) => t(`dashboard.labels.costSource_${key}`);
const series = () => SOURCE_SERIES.map((s) => ({ key: s.key, label: sourceLabel(s.key), color: s.color }));
const toNumbers = (row: SourceSums) => ({ PARTS: Number(row.PARTS), EXTERNAL_SERVICE: Number(row.EXTERNAL_SERVICE), EXTERNAL_WO: Number(row.EXTERNAL_WO) });

const sourceColumns = (): Column<Row>[] => [
  ...SOURCE_SERIES.map((s) => ({ key: s.key, label: `dashboard.labels.costSource_${s.key}`, kind: 'money' as const })),
  { key: 'total', label: col('total'), kind: 'money' },
];

const transactionLink = (r: Row) => {
  if (r.source === 'EXTERNAL_SERVICE') return `/app/workshop-invoices/${r.document_id}`;
  return r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null;
};

const transactionColumns: Column<Row>[] = [
  { key: 'recognized_on', label: col('recognizedOn'), kind: 'date' },
  { key: 'source', label: col('costSource'), value: (r) => sourceLabel(String(r.source)) },
  { key: 'document_number', label: col('document'), link: transactionLink },
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
  { key: 'registration_number', label: col('vehicle'), link: (r) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null) },
  { key: 'vendor_name', label: col('vendor') },
  { key: 'amount', label: col('amount'), kind: 'money' },
];

/** Per-vehicle rows; the vehicle cell drills into its transactions (same period / month). */
const vehicleColumns = (drillWidget?: string): Column<Row>[] => [
  {
    key: 'registration_number', label: col('vehicle'),
    drill: (r) => (r.vehicle_id ? { title: String(r.registration_number ?? '—'), params: { vehicle_id: r.vehicle_id }, columns: transactionColumns, widgetId: drillWidget } : null),
  },
  { key: 'branch_name', label: col('branch') },
  { key: 'work_orders', label: col('workOrders'), kind: 'num' },
  ...sourceColumns(),
];

function PendingNote({ pending, currency }: { pending: { parts_on_open_work_orders: { work_orders: number; amount: string }; uninvoiced_memos: { count: number; estimated_amount: string } }; currency: string }) {
  return (
    <div className="dash-kpi-sub" style={{ display: 'grid', gap: 2 }}>
      <span>{t('dashboard.widgets.fn01.pendingParts', { n: pending.parts_on_open_work_orders.work_orders, amount: money(pending.parts_on_open_work_orders.amount, currency) })}</span>
      <span>{t('dashboard.widgets.fn01.pendingMemos', { n: pending.uninvoiced_memos.count, amount: money(pending.uninvoiced_memos.estimated_amount, currency) })}</span>
      <span>{t('dashboard.widgets.fn01.laborExcluded')}</span>
    </div>
  );
}

const FN01: WidgetDefinition<{ months: (SourceSums & { month: string; is_current: boolean; work_orders: number; vehicles: number })[]; totals: SourceSums; vehicles: number; pending: Parameters<typeof PendingNote>[0]['pending'] }> = {
  size: 'l',
  render: (env, ctx) => {
    const current = env.data.months.find((m) => m.is_current);
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={money(env.data.totals.total, env.currency)} label={t('dashboard.widgets.fn01.kpiTotal', { months: env.data.months.length - 1 })} />
          {current && <Kpi value={money(current.total, env.currency)} label={t('dashboard.widgets.fn01.kpiCurrent')} />}
          <Kpi value={count(env.data.vehicles)} label={t('dashboard.widgets.fn01.kpiVehicles')} />
        </div>
        {Number(env.data.totals.total) === 0 ? <StateBox>{t('dashboard.widgets.fn01.empty')}</StateBox> : (
        <ColumnChart unit="money" currency={env.currency} categoryKey="month" height={260}
          data={env.data.months.map((m) => ({ month: m.month, ...toNumbers(m) }))}
          series={series()} labelFormatter={(m) => monthLabel(m)} highlight={current?.month}
          footer={(m) => {
            const row = env.data.months.find((x) => x.month === m);
            return row ? (
              <div className="dash-chart-tooltip-row" style={{ color: '#6b7280', marginTop: 4 }}>
                <span>{row.is_current ? t('dashboard.widgets.fn01.runningMonth') : t('dashboard.widgets.fn01.workOrdersVehicles', { wo: row.work_orders, vehicles: row.vehicles })}</span>
                <span />
              </div>
            ) : null;
          }}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, vehicleColumns())} />
        )}
        <Legend items={series().map((s) => ({ key: s.key, label: s.label, color: s.color, value: money((env.data.totals as Record<string, string>)[s.key], env.currency) }))} />
        <PendingNote pending={env.data.pending} currency={env.currency} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, ...sourceColumns(), { key: 'work_orders', label: col('workOrders'), kind: 'num' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') + (m.is_current ? ` (${t('dashboard.widgets.fn01.runningMonth')})` : '') })),
  }),
};

const FN02: WidgetDefinition<{ vehicles: (SourceSums & Row)[]; other: { vehicles: number; total: string } | null; totals: SourceSums }> = {
  size: 'm',
  isEmpty: (d) => d.vehicles.length === 0,
  render: (env, ctx) => (
    <>
      <HBarChart unit="money" currency={env.currency} labelWidth={110}
        data={env.data.vehicles.map((v) => ({ vehicle: String(v.registration_number ?? '—'), vehicle_id: v.vehicle_id, ...toNumbers(v) }))}
        categoryKey="vehicle" series={series()}
        onSelect={(row) => ctx.openDetail(String(row.vehicle), { vehicle_id: row.vehicle_id }, transactionColumns)} />
      <Legend items={series().map((s) => ({ key: s.key, label: s.label, color: s.color }))} />
      {env.data.other && <span className="dash-kpi-sub">{t('dashboard.widgets.fn02.other', { n: env.data.other.vehicles, amount: money(env.data.other.total, env.currency) })}</span>}
      <span className="dash-kpi-sub">{t('dashboard.widgets.fn02.total', { amount: money(env.data.totals.total, env.currency) })}</span>
    </>
  ),
  table: (env) => ({ columns: vehicleColumns(), rows: env.data.vehicles.map((v) => ({ ...v, id: String(v.vehicle_id) })) }),
  detail: { columns: vehicleColumns() },
};

const FN03: WidgetDefinition<{ branches: (SourceSums & { branch_id: string; branch_name: string; vehicles: number })[]; totals: SourceSums }> = {
  size: 'm',
  isEmpty: (d) => d.branches.length === 0,
  render: (env, ctx) => (
    <>
      <HBarChart unit="money" currency={env.currency}
        data={env.data.branches.map((b) => ({ branch: b.branch_name ?? '—', branch_id: b.branch_id, ...toNumbers(b) }))}
        categoryKey="branch" series={series()}
        onSelect={(row) => ctx.openDetail(String(row.branch), { attributed_branch_id: row.branch_id }, vehicleColumns('FN-02'))} />
      <Legend items={series().map((s) => ({ key: s.key, label: s.label, color: s.color }))} />
      <span className="dash-kpi-sub">{t('dashboard.widgets.fn02.total', { amount: money(env.data.totals.total, env.currency) })}</span>
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'branch_name', label: col('branch') }, { key: 'vehicles', label: col('vehicles'), kind: 'num' }, ...sourceColumns()],
    rows: env.data.branches.map((b) => ({ ...b, id: b.branch_id })),
  }),
};

// ------------------------------------------------------------------ FN-04 payables aging

const PAYABLE_SERIES = [
  { key: 'VENDOR_INVOICE', color: SERIES[0] },
  { key: 'SERVICE_INVOICE', color: SERIES[1] },
  { key: 'EXTERNAL_WO', color: SERIES[2] },
] as const;
const AGING_BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'no_due'];

const payableLink = (r: Row) => {
  if (r.source === 'SERVICE_INVOICE') return `/app/workshop-invoices/${r.document_id}`;
  if (r.source === 'EXTERNAL_WO') return r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null;
  return '/app/vendor-invoice-references';
};

const payableColumns: Column<Row>[] = [
  { key: 'source', label: col('payableSource'), value: (r) => t(`dashboard.labels.payable_${r.source}`) },
  { key: 'document_number', label: col('document'), link: payableLink },
  { key: 'vendor_name', label: col('vendor') },
  { key: 'invoice_date', label: col('invoiceDate'), kind: 'date' },
  { key: 'due_date', label: col('dueDate'), value: (r) => (r.due_date ? formatDate(String(r.due_date)) : r.payment_term ? `${t('dashboard.widgets.fn04.termPrefix')} ${r.payment_term}` : null) },
  { key: 'days_overdue', label: col('daysOverdue'), kind: 'num' },
  { key: 'outstanding', label: col('outstanding'), kind: 'money' },
];

const FN04: WidgetDefinition<{ sources: string[]; buckets: (Row & { bucket: string; total: string; count: number })[]; total: string; overdue: string; invoices: number }> = {
  size: 'm',
  isEmpty: (d) => d.invoices === 0,
  render: (env, ctx) => {
    const s = PAYABLE_SERIES.filter((p) => env.data.sources.includes(p.key)).map((p) => ({ key: p.key, label: t(`dashboard.labels.payable_${p.key}`), color: p.color }));
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={money(env.data.total, env.currency)} label={t('dashboard.widgets.fn04.kpiTotal', { n: env.data.invoices })} />
          <Kpi value={money(env.data.overdue, env.currency)} label={t('dashboard.widgets.fn04.kpiOverdue')} tone={Number(env.data.overdue) > 0 ? 'critical' : undefined} />
        </div>
        <ColumnChart unit="money" currency={env.currency} categoryKey="bucket" height={220}
          data={env.data.buckets.map((b) => ({ bucket: b.bucket, ...Object.fromEntries(s.map((x) => [x.key, Number(b[x.key] ?? 0)])) }))}
          series={s} labelFormatter={(b) => t(`dashboard.buckets.pay_${b}`)} tickFormatter={(b) => t(`dashboard.buckets.payShort_${b}`)} allTicks
          onSelect={(bucket) => ctx.openDetail(t(`dashboard.buckets.pay_${bucket}`), { bucket }, payableColumns)} />
        <Legend items={s.map((x) => ({ key: x.key, label: x.label, color: x.color }))} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'bucket', label: col('bucket') }, ...env.data.sources.map((k) => ({ key: k, label: `dashboard.labels.payable_${k}`, kind: 'money' as const })),
      { key: 'total', label: col('total'), kind: 'money' }, { key: 'count', label: col('count'), kind: 'num' }],
    rows: AGING_BUCKETS.map((b) => ({ ...(env.data.buckets.find((x) => x.bucket === b) ?? {}), id: b, bucket: t(`dashboard.buckets.pay_${b}`) })),
  }),
  detail: { columns: payableColumns },
};

// ------------------------------------------------------------------ FN-06 refunds

const refundColumns: Column<Row>[] = [
  { key: 'return_number', label: col('return') },
  { key: 'po_number', label: col('purchaseOrder'), link: (r) => (r.purchase_order_id ? `/app/purchase-orders/${r.purchase_order_id}` : null) },
  { key: 'vendor_name', label: col('vendor') },
  { key: 'returned_at', label: col('returnedAt'), kind: 'datetime' },
  { key: 'vendor_decided_at', label: col('acceptedAt'), kind: 'datetime' },
  { key: 'refunded_amount', label: col('refundAmount'), kind: 'money' },
];

const FN06: WidgetDefinition<{ months: { month: string; is_current: boolean; count: number; amount: string }[]; accepted_total: string; accepted_count: number; pending_requests: number }> = {
  size: 'm',
  isEmpty: (d) => d.accepted_count === 0 && d.pending_requests === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={money(env.data.accepted_total, env.currency)} label={t('dashboard.widgets.fn06.kpiAccepted', { n: env.data.accepted_count })} />
        <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }}
          onClick={() => ctx.openDetail(t('dashboard.widgets.fn06.kpiPending'), { view: 'pending' }, refundColumns)}>
          <Kpi value={count(env.data.pending_requests)} label={t('dashboard.widgets.fn06.kpiPending')} tone={env.data.pending_requests > 0 ? 'warning' : undefined} />
        </button>
      </div>
      <ColumnChart unit="money" currency={env.currency} categoryKey="month" height={180}
        data={env.data.months.map((m) => ({ month: m.month, amount: Number(m.amount) }))}
        series={[{ key: 'amount', label: t('dashboard.widgets.fn06.series'), color: STATUS.good }]}
        labelFormatter={(m) => monthLabel(m)} highlight={env.data.months.find((m) => m.is_current)?.month}
        onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { view: 'accepted', month }, refundColumns)} />
      <span className="dash-kpi-sub">{t('dashboard.widgets.fn06.note')}</span>
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, { key: 'count', label: col('count'), kind: 'num' }, { key: 'amount', label: col('refundAmount'), kind: 'money' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
  detail: { params: { view: 'accepted' }, columns: refundColumns },
};

export const FINANCE_WIDGETS: Record<string, WidgetDefinition> = { 'FN-01': FN01, 'FN-02': FN02, 'FN-03': FN03, 'FN-04': FN04, 'FN-06': FN06 };
