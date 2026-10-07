import { t } from '../../../../i18n/i18n';
import { ColumnChart, DataTable, HBarChart, Kpi, Legend, Pill, StateBox, type Column } from '../components';
import { count, monthLabel, money } from '../format';
import { codeLabel, col } from '../labels';
import { SERIES } from '../palette';
import type { WidgetDefinition } from '../WidgetCard';
import { SOURCE_COLUMNS } from './currentWidgets';
import { payableColumns } from './financeWidgets';

type Row = Record<string, unknown>;
type Month = { month: string; is_current: boolean };

const currentOf = (months: Month[]) => months.find((m) => m.is_current)?.month;

// ------------------------------------------------------------------ FL-04 / FL-05 breakdowns

const SEVERITY_SERIES = [
  { key: 'MINOR', color: SERIES[0] },
  { key: 'MAJOR', color: SERIES[3] },
  { key: 'IMMOBILIZED', color: SERIES[7] },
];
const severitySeries = () => SEVERITY_SERIES.map((s) => ({ ...s, label: codeLabel(s.key, 'severity') }));

const breakdownListColumns: Column<Row>[] = [
  { key: 'registration_number', label: col('vehicle'), link: (r) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null) },
  { key: 'branch_name', label: col('branch') },
  { key: 'severity', label: col('severity'), value: (r) => codeLabel(String(r.severity), 'severity') },
  { key: 'status', label: col('status'), value: (r) => codeLabel(String(r.status)), link: (r) => `/app/breakdowns/${r.id}` },
  { key: 'reported_at', label: col('reportedAt'), kind: 'datetime' },
  { key: 'resolved_at', label: col('resolvedAt'), kind: 'datetime' },
];

const FL04: WidgetDefinition<{ months: (Month & Record<string, number>)[]; total: number }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <Kpi value={count(env.data.total)} label={t('dashboard.widgets.fl04.kpi')} />
      <ColumnChart unit="count" categoryKey="month" height={220} data={env.data.months} series={severitySeries()}
        labelFormatter={(m) => monthLabel(m)} highlight={currentOf(env.data.months)}
        onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, breakdownListColumns)} />
      <Legend items={severitySeries().map((s) => ({ key: s.key, label: s.label, color: s.color }))} />
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, ...SEVERITY_SERIES.map((s) => ({ key: s.key, label: codeLabel(s.key, 'severity'), kind: 'num' as const })),
      { key: 'total', label: col('total'), kind: 'num' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
  detail: { columns: breakdownListColumns },
};

const FL05: WidgetDefinition<{ vehicles: { vehicle_id: string; registration_number: string; branch_name: string; total: number; immobilized: number }[] }> = {
  size: 'm',
  isEmpty: (d) => d.vehicles.length === 0,
  render: (env, ctx) => (
    <HBarChart unit="count" labelWidth={110} categoryKey="vehicle"
      data={env.data.vehicles.map((v) => ({ vehicle: v.registration_number ?? '—', vehicle_id: v.vehicle_id, total: v.total }))}
      series={[{ key: 'total', label: t('dashboard.widgets.fl05.series'), color: SERIES[0] }]}
      onSelect={(row) => ctx.openDetail(String(row.vehicle), { vehicle_id: row.vehicle_id }, breakdownListColumns)} />
  ),
  table: (env) => ({
    columns: [{ key: 'registration_number', label: col('vehicle') }, { key: 'branch_name', label: col('branch') },
      { key: 'total', label: col('count'), kind: 'num' }, { key: 'immobilized', label: codeLabel('IMMOBILIZED', 'severity'), kind: 'num' }],
    rows: env.data.vehicles.map((v) => ({ ...v, id: v.vehicle_id })),
  }),
};

// ------------------------------------------------------------------ WS-03 / WS-04

const TYPE_SERIES = [
  { key: 'PREVENTIVE', color: SERIES[2] },
  { key: 'CORRECTIVE', color: SERIES[0] },
  { key: 'BREAKDOWN', color: SERIES[7] },
  { key: 'INSPECTION', color: SERIES[3] },
  { key: 'CAMPAIGN', color: SERIES[6] },
];
const typeSeries = () => TYPE_SERIES.map((s) => ({ ...s, label: codeLabel(s.key, 'type') }));

const completedColumns: Column<Row>[] = [
  { key: 'wo_number', label: col('workOrder'), link: (r) => `/app/work-orders/${r.id}` },
  { key: 'maintenance_type', label: col('type'), value: (r) => codeLabel(String(r.maintenance_type), 'type') },
  { key: 'registration_number', label: col('vehicle'), link: (r) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null) },
  { key: 'workshop_name', label: col('workshop') },
  { key: 'started_at', label: col('startedAt'), kind: 'datetime' },
  { key: 'completed_at', label: col('completedAt'), kind: 'datetime' },
];

const WS03: WidgetDefinition<{ months: (Month & Record<string, number>)[]; total: number }> = {
  size: 'm',
  isEmpty: (d) => d.total === 0,
  render: (env, ctx) => (
    <>
      <Kpi value={count(env.data.total)} label={t('dashboard.widgets.ws03.kpi')} />
      <ColumnChart unit="count" categoryKey="month" height={220} data={env.data.months} series={typeSeries()}
        labelFormatter={(m) => monthLabel(m)} highlight={currentOf(env.data.months)}
        onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, completedColumns)} />
      <Legend items={typeSeries().map((s) => ({ key: s.key, label: s.label, color: s.color }))} />
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, ...TYPE_SERIES.map((s) => ({ key: s.key, label: codeLabel(s.key, 'type'), kind: 'num' as const })),
      { key: 'total', label: col('total'), kind: 'num' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
  detail: { columns: completedColumns },
};

const WS04: WidgetDefinition<{ months: (Month & { work_orders: number; median_hours: number | null })[]; median_hours: number | null; work_orders: number; not_measurable: number }> = {
  size: 'm',
  isEmpty: (d) => d.work_orders === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={t('dashboard.units.hours', { value: count(env.data.median_hours) })} label={t('dashboard.widgets.ws04.kpi')} />
        <Kpi value={count(env.data.work_orders)} label={t('dashboard.widgets.ws04.kpiCount')} />
      </div>
      <ColumnChart unit="count" categoryKey="month" height={200}
        data={env.data.months.map((m) => ({ month: m.month, median_hours: m.median_hours }))}
        series={[{ key: 'median_hours', label: t('dashboard.widgets.ws04.series'), color: SERIES[0] }]}
        labelFormatter={(m) => monthLabel(m)} highlight={currentOf(env.data.months)}
        onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, [...completedColumns, { key: 'duration_hours', label: col('durationHours'), kind: 'num' }])} />
      <span className="dash-kpi-sub">{t('dashboard.widgets.ws04.note')}</span>
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, { key: 'median_hours', label: t('dashboard.widgets.ws04.series'), kind: 'num' },
      { key: 'work_orders', label: col('workOrders'), kind: 'num' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
};

// ------------------------------------------------------------------ WH-04 / WH-05

const movementColumns: Column<Row>[] = [
  { key: 'movement_type', label: col('movementType'), value: (r) => t(`dashboard.labels.movement_${r.movement_type}`) },
  { key: 'direction', label: col('direction'), value: (r) => t(`dashboard.labels.direction_${r.direction}`) },
  { key: 'movements', label: col('count'), kind: 'num' },
  { key: 'quantity', label: col('quantity'), kind: 'num' },
  { key: 'value', label: col('value'), kind: 'money' },
  { key: 'unvalued', label: col('unvalued'), kind: 'num' },
];

const WH04: WidgetDefinition<{ months: (Month & { value_in: string; value_out: string })[]; total_in: string; total_out: string }> = {
  size: 'xl',
  isEmpty: (d) => Number(d.total_in) === 0 && Number(d.total_out) === 0,
  render: (env, ctx) => {
    const s = [{ key: 'value_in', label: t('dashboard.widgets.wh04.in'), color: SERIES[0] }, { key: 'value_out', label: t('dashboard.widgets.wh04.out'), color: SERIES[1] }];
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={money(env.data.total_in, env.currency)} label={t('dashboard.widgets.wh04.in')} />
          <Kpi value={money(env.data.total_out, env.currency)} label={t('dashboard.widgets.wh04.out')} />
        </div>
        <ColumnChart unit="money" currency={env.currency} categoryKey="month" height={230} grouped series={s}
          data={env.data.months.map((m) => ({ month: m.month, value_in: Number(m.value_in), value_out: Number(m.value_out) }))}
          labelFormatter={(m) => monthLabel(m)} highlight={currentOf(env.data.months)}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, movementColumns)} />
        <Legend items={s.map((x) => ({ key: x.key, label: x.label, color: x.color }))} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, { key: 'value_in', label: t('dashboard.widgets.wh04.in'), kind: 'money' },
      { key: 'value_out', label: t('dashboard.widgets.wh04.out'), kind: 'money' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
};

const slowColumns: Column<Row>[] = [
  { key: 'product_name', label: col('product') }, { key: 'sku', label: col('sku') }, { key: 'warehouse_name', label: col('warehouse') },
  { key: 'quantity_on_hand', label: col('onHand'), kind: 'num' }, { key: 'value', label: col('value'), kind: 'money' },
  { key: 'last_movement_at', label: col('lastMovement'), kind: 'datetime' }, { key: 'idle_days', label: col('idleDays'), kind: 'num' },
];

const WH05: WidgetDefinition<{ idle_days: number; slow: { count: number; value: string }; no_history: { count: number; value: string }; items: Row[] }> = {
  size: 'm',
  isEmpty: (d) => d.slow.count + d.no_history.count === 0,
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={money(env.data.slow.value, env.currency)} label={t('dashboard.widgets.wh05.kpiSlow', { n: env.data.slow.count, days: env.data.idle_days })} />
        <button type="button" className="dash-link-btn" style={{ color: 'inherit', textAlign: 'left' }}
          onClick={() => ctx.openDetail(t('dashboard.widgets.wh05.noHistory'), { state: 'NO_HISTORY' }, slowColumns)}>
          <Kpi value={money(env.data.no_history.value, env.currency)} label={t('dashboard.widgets.wh05.kpiNoHistory', { n: env.data.no_history.count })} />
        </button>
      </div>
      {env.data.items.length > 0 && <DataTable rows={env.data.items} columns={slowColumns.filter((c) => ['product_name', 'warehouse_name', 'value', 'idle_days'].includes(c.key))} />}
    </>
  ),
  detail: { params: { state: 'SLOW' }, columns: slowColumns },
};

// ------------------------------------------------------------------ PR-03 / PR-04

const poValueColumns: Column<Row>[] = [
  { key: 'po_number', label: col('purchaseOrder'), link: (r) => `/app/purchase-orders/${r.id}` },
  { key: 'status', label: col('status'), value: (r) => codeLabel(String(r.status)) },
  { key: 'vendor_name', label: col('vendor') }, { key: 'warehouse_name', label: col('warehouse') },
  { key: 'order_date', label: col('orderDate'), kind: 'date' }, { key: 'total', label: col('value'), kind: 'money' },
];

const PR03: WidgetDefinition<{ months: (Month & { count: number; amount: string })[]; total: string; orders: number }> = {
  size: 'm',
  isEmpty: (d) => d.orders === 0,
  render: (env, ctx) => (
    <>
      <Kpi value={money(env.data.total, env.currency)} label={t('dashboard.widgets.pr03.kpi', { n: env.data.orders })} />
      <ColumnChart unit="money" currency={env.currency} categoryKey="month" height={200}
        data={env.data.months.map((m) => ({ month: m.month, amount: Number(m.amount) }))}
        series={[{ key: 'amount', label: t('dashboard.widgets.pr03.series'), color: SERIES[0] }]}
        labelFormatter={(m) => monthLabel(m)} highlight={currentOf(env.data.months)}
        onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, poValueColumns)} />
    </>
  ),
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, { key: 'count', label: col('count'), kind: 'num' }, { key: 'amount', label: col('value'), kind: 'money' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
};

const rate = (value: number | null, num: number | string, den: number | string) =>
  value === null ? '—' : `${count(value)}% (${count(num)}/${count(den)})`;

const PR04: WidgetDefinition<{ vendors: Row[] }> = {
  size: 'm',
  isEmpty: (d) => d.vendors.length === 0,
  render: (env) => (
    <DataTable rows={env.data.vendors} columns={[
      { key: 'vendor_name', label: col('vendor') },
      { key: 'receipts', label: col('receipts'), kind: 'num' },
      { key: 'on_time_rate', label: col('onTime'), value: (r) => rate(r.on_time_rate as number | null, r.on_time as number, r.with_due_date as number) },
      { key: 'accepted_rate', label: col('acceptedQty'), value: (r) => rate(r.accepted_rate as number | null, r.accepted_qty as string, r.received_qty as string) },
    ]} />
  ),
};

// ------------------------------------------------------------------ TR-04

const TIRE_COST_SERIES = [{ key: 'RETREAD', color: SERIES[6] }, { key: 'REPAIR', color: SERIES[4] }];
const tireCostColumns: Column<Row>[] = [
  { key: 'serial_number', label: col('serial'), link: (r) => `/app/tires/${r.tire_id}` },
  { key: 'type', label: col('type'), value: (r) => codeLabel(String(r.type), 'tireRecommendation') },
  { key: 'cycle_number', label: col('cycle'), kind: 'num' }, { key: 'vendor_name', label: col('vendor') },
  { key: 'received_at', label: col('receivedAt'), kind: 'date' }, { key: 'cost', label: col('value'), kind: 'money' },
];

const TR04: WidgetDefinition<{ months: (Month & { RETREAD: string; REPAIR: string; cycles: number })[]; retread_total: string; repair_total: string; cycles: number }> = {
  size: 'm',
  isEmpty: (d) => d.cycles === 0,
  render: (env, ctx) => {
    const s = TIRE_COST_SERIES.map((x) => ({ ...x, label: codeLabel(x.key, 'tireRecommendation') }));
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={money(env.data.retread_total, env.currency)} label={codeLabel('RETREAD', 'tireRecommendation')} />
          <Kpi value={money(env.data.repair_total, env.currency)} label={codeLabel('REPAIR', 'tireRecommendation')} />
        </div>
        <ColumnChart unit="money" currency={env.currency} categoryKey="month" height={200} series={s}
          data={env.data.months.map((m) => ({ month: m.month, RETREAD: Number(m.RETREAD), REPAIR: Number(m.REPAIR) }))}
          labelFormatter={(m) => monthLabel(m)} highlight={currentOf(env.data.months)}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, tireCostColumns)} />
        <Legend items={s.map((x) => ({ key: x.key, label: x.label, color: x.color }))} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, { key: 'RETREAD', label: codeLabel('RETREAD', 'tireRecommendation'), kind: 'money' },
      { key: 'REPAIR', label: codeLabel('REPAIR', 'tireRecommendation'), kind: 'money' }, { key: 'cycles', label: col('count'), kind: 'num' }],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
};

// ------------------------------------------------------------------ AL-01

const SEVERITY_TONE: Record<string, 'critical' | 'serious' | 'warning'> = { critical: 'critical', high: 'serious', medium: 'warning' };

const AL01: WidgetDefinition<{ items: { type: string; severity: string; count: number; amount: string | null; widget: string; params: Record<string, unknown> }[]; total: number }> = {
  size: 'xl',
  render: (env, ctx) => (env.data.items.length === 0 ? <StateBox>{t('dashboard.widgets.al01.empty')}</StateBox> : (
    <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 6 }}>
      {env.data.items.map((item) => {
        const label = t(`dashboard.alerts.${item.type}`, { n: item.count });
        const columns = item.widget === 'FN-04' ? payableColumns : SOURCE_COLUMNS[item.widget];
        return (
          <li key={item.type} style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', padding: '8px 10px', border: '1px solid #f3f4f6', borderRadius: 8 }}>
            <Pill tone={SEVERITY_TONE[item.severity]}>{t(`dashboard.severity.${item.severity}`)}</Pill>
            <span style={{ flex: '1 1 240px', fontSize: 14 }}>
              {label}
              {item.amount && <strong style={{ marginLeft: 6 }}>{money(item.amount, env.currency)}</strong>}
            </span>
            {columns && (
              <button type="button" className="dash-link-btn" onClick={() => ctx.openDetail(label, item.params, columns, item.widget)}>
                {t('dashboard.actions.viewDetails')}
              </button>
            )}
          </li>
        );
      })}
    </ul>
  )),
};


export const TREND_WIDGETS: Record<string, WidgetDefinition> = {
  'FL-04': FL04, 'FL-05': FL05, 'WS-03': WS03, 'WS-04': WS04, 'WH-04': WH04, 'WH-05': WH05, 'PR-03': PR03, 'PR-04': PR04, 'TR-04': TR04, 'AL-01': AL01,
};
