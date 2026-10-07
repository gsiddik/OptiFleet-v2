import { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { FormField, inputStyle } from '../../../../components/FormField';
import { Modal } from '../../../../components/Modal';
import { NumericInput } from '../../../../components/NumericInput';
import { t } from '../../../../i18n/i18n';
import { BaselineSettings } from '../BaselineSettings';
import { ColumnChart, DataTable, HBarChart, Kpi, Legend, Pill, ScatterPlot, StateBox, Unavailable, type Column } from '../components';
import { count, monthLabel, money } from '../format';
import { codeLabel, col, itemTypeLabel } from '../labels';
import { stockColumns } from './currentWidgets';
import { NEUTRAL, SERIES, STATUS } from '../palette';
import type { WidgetDefinition } from '../WidgetCard';

type Row = Record<string, unknown>;
type CostSums = { PARTS: string; LABOR: string | null; EXTERNAL_PAID: string; total: string };

// ------------------------------------------------------------------ operating cost (FN-07 / FN-08)

/** Fixed slots: color follows the cost component, never its rank. */
const COST_SERIES = [
  { key: 'PARTS', color: SERIES[0] },
  { key: 'LABOR', color: SERIES[3] },
  { key: 'EXTERNAL_PAID', color: SERIES[2] },
] as const;

const componentLabel = (key: string) => t(`dashboard.labels.opCost_${key}`);
const costSeries = () => COST_SERIES.map((s) => ({ key: s.key, label: componentLabel(s.key), color: s.color }));
/** Chart values per component; an unavailable component stays null (no bar, "Unavailable" in the tooltip), never 0. */
const costNumbers = (r: { PARTS: string | null; LABOR: string | null; EXTERNAL_PAID: string | null }) => ({
  PARTS: r.PARTS === null ? null : Number(r.PARTS), LABOR: r.LABOR === null ? null : Number(r.LABOR), EXTERNAL_PAID: r.EXTERNAL_PAID === null ? null : Number(r.EXTERNAL_PAID),
});
const costColumns = (): Column<Row>[] => [
  ...COST_SERIES.map((s) => ({ key: s.key, label: `dashboard.labels.opCost_${s.key}`, kind: 'money' as const, unavailable: s.key === 'LABOR' })),
  { key: 'total', label: col('total'), kind: 'money' },
];

/** Whether a row's mechanic cost is recorded in full: otherwise its total covers recorded costs only. */
const costCompletenessColumn = (): Column<Row> => ({
  key: 'cost_complete', label: col('costCompleteness'),
  value: (r) => t(r.cost_complete ? 'dashboard.labels.costComplete' : 'dashboard.labels.costRecordedOnly'),
});
const laborStatusColumn = (): Column<Row> => ({ key: 'labor_status', label: col('mechanicCostStatus'), value: (r) => t(`dashboard.labels.laborStatus_${r.labor_status}`) });

const lineColumns: Column<Row>[] = [
  { key: 'on', label: col('date'), kind: 'date' },
  { key: 'component', label: col('costComponent'), value: (r) => componentLabel(String(r.component)) },
  { key: 'description', label: col('description') },
  { key: 'quantity', label: col('quantity'), kind: 'num' },
  { key: 'unit_cost', label: col('unitCost'), kind: 'money' },
  { key: 'hours', label: col('hours') },
  { key: 'effective_rate', label: col('hourlyRate'), kind: 'money' },
  { key: 'amount', label: col('amount'), kind: 'money' },
];

const workOrderColumns: Column<Row>[] = [
  {
    key: 'wo_number', label: col('workOrder'),
    drill: (r) => ({ title: String(r.wo_number ?? '—'), params: { work_order_id: r.work_order_id }, columns: lineColumns }),
  },
  ...costColumns(),
  { key: 'work_time_state', label: col('workHistory'), value: (r) => t(`dashboard.labels.workTime_${r.work_time_state}`) },
  laborStatusColumn(),
];

const anomalyColumns: Column<Row>[] = [
  { key: 'kind', label: col('anomalyKind'), value: (r) => t(`dashboard.labels.paymentAnomaly_${r.kind}`) },
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
  { key: 'document', label: col('document') },
  { key: 'payment_date', label: col('paymentDate'), kind: 'date' },
  { key: 'expected', label: col('expectedAmount'), kind: 'money' },
  { key: 'paid', label: col('paidAmount'), kind: 'money' },
];

const rankingColumns: Column<Row>[] = [
  {
    key: 'registration_number', label: col('vehicle'),
    drill: (r) => ({ title: String(r.registration_number ?? '—'), params: { vehicle_id: r.vehicle_id }, columns: workOrderColumns }),
  },
  { key: 'category_name', label: col('category') },
  { key: 'branch_name', label: col('branch') },
  ...costColumns(),
  costCompletenessColumn(),
];

type Option = { id: string; name: string };

const FN07: WidgetDefinition<{ totals: CostSums; vehicles: (CostSums & Row)[]; vehicles_total: number; vehicles_with_cost: number; vehicles_cost_incomplete: number; payment_anomalies: number; options: { categories: Option[]; vehicles: (Option & { category_id: string | null })[] } }> = {
  size: 'xl',
  controls: (data, value, set) => data && (
    <>
      <label>
        {t('dashboard.filters.vehicleCategory')}
        <select value={value.vehicle_category_id ?? ''} onChange={(e) => { set('vehicle_category_id', e.target.value); set('vehicle_id', ''); }}>
          <option value="">{t('dashboard.filters.all')}</option>
          {data.options.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </label>
      <label>
        {t('dashboard.filters.vehicle')}
        <select value={value.vehicle_id ?? ''} onChange={(e) => set('vehicle_id', e.target.value)}>
          <option value="">{t('dashboard.filters.all')}</option>
          {data.options.vehicles.filter((v) => !value.vehicle_category_id || v.category_id === value.vehicle_category_id)
            .map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
        </select>
      </label>
    </>
  ),
  render: (env, ctx) => {
    const incomplete = env.data.vehicles_cost_incomplete > 0;
    const partial = env.basis?.completeness?.status !== 'COMPLETE' && env.basis?.completeness != null;
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={money(env.data.totals.total, env.currency)} label={t(partial ? 'dashboard.widgets.fn07.kpiTotalRecorded' : 'dashboard.widgets.fn07.kpiTotal')} />
          <Kpi value={`${count(env.data.vehicles_with_cost)} / ${count(env.data.vehicles_total)}`} label={t('dashboard.widgets.fn07.kpiVehicles')} />
        </div>
        {Number(env.data.totals.total) === 0 ? <StateBox>{t('dashboard.widgets.fn07.empty')}</StateBox> : (
          <HBarChart unit="money" currency={env.currency} labelWidth={118} categoryKey="vehicle" series={costSeries()}
            data={env.data.vehicles.filter((v) => Number(v.total) > 0).map((v) => ({
              vehicle: `${String(v.registration_number ?? '—')}${v.cost_complete ? '' : ' *'}`, vehicle_id: v.vehicle_id, ...costNumbers(v),
            }))}
            onSelect={(row) => ctx.openDetail(String(row.vehicle).replace(/ \*$/, ''), { vehicle_id: row.vehicle_id }, workOrderColumns)} />
        )}
        <Legend items={costSeries().map((s) => ({ key: s.key, label: s.label, color: s.color, value: money((env.data.totals as Record<string, string>)[s.key], env.currency) }))} />
        {incomplete && <span className="dash-kpi-sub" role="note">{t('dashboard.widgets.fn07.recordedOnlyNote', { count: env.data.vehicles_cost_incomplete })}</span>}
        {env.data.payment_anomalies > 0 && (
          <button type="button" className="dash-link-btn" onClick={() => ctx.openDetail(t('dashboard.widgets.fn07.anomaliesTitle'), { view: 'payment_anomalies' }, anomalyColumns)}>
            {t('dashboard.widgets.fn07.anomaliesLink', { count: env.data.payment_anomalies })}
          </button>
        )}
        <span className="dash-kpi-sub">{t('dashboard.widgets.fn07.note')}</span>
      </>
    );
  },
  table: (env) => ({ columns: rankingColumns, rows: env.data.vehicles.map((v) => ({ ...v, id: String(v.vehicle_id) })) }),
  detail: { columns: rankingColumns },
};

const FN08: WidgetDefinition<{ months: (CostSums & { month: string; is_current: boolean })[]; totals: CostSums }> = {
  size: 'xl',
  isEmpty: (d) => Number(d.totals.total) === 0,
  render: (env, ctx) => {
    const current = env.data.months.find((m) => m.is_current);
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={money(env.data.totals.total, env.currency)} label={t('dashboard.widgets.fn08.kpiTotal')} />
          {(['PARTS', 'LABOR', 'EXTERNAL_PAID'] as const).map((k) => {
            const share = Number(env.data.totals.total) > 0 ? (Number(env.data.totals[k]) * 100) / Number(env.data.totals.total) : 0;
            return <Kpi key={k} value={`${share.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`} label={componentLabel(k)} />;
          })}
        </div>
        <ColumnChart unit="money" currency={env.currency} categoryKey="month" height={240}
          data={env.data.months.map((m) => ({ month: m.month, ...costNumbers(m) }))} series={costSeries()}
          labelFormatter={(m) => monthLabel(m)} highlight={current?.month}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, [
            { key: 'on', label: col('date'), kind: 'date' },
            { key: 'component', label: col('costComponent'), value: (r) => componentLabel(String(r.component)) },
            { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
            { key: 'description', label: col('description') },
            { key: 'amount', label: col('amount'), kind: 'money' },
          ])} />
        <Legend items={costSeries().map((s) => ({ key: s.key, label: s.label, color: s.color }))} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'month', label: col('month') }, ...costColumns()],
    rows: env.data.months.map((m) => ({ ...m, id: m.month, month: monthLabel(m.month, 'long') })),
  }),
};

// ------------------------------------------------------------------ mechanic performance (WS-07)

type MechanicRow = {
  worker_id: string; worker_name: string | null; employee_code: string | null; workshop_name: string | null;
  wo_handled: number; wo_completed: number; valid_samples: number; completed_total: number; excluded_samples: number; avg_state: 'AVAILABLE' | 'UNAVAILABLE'; avg_hours: string | null;
  baseline_hours: string | null; diff_hours: string | null; ratio: string | null;
  status: 'MEETS' | 'ABOVE' | 'INSUFFICIENT_SAMPLE' | 'NO_BASELINE';
};

const KPI_STATUS: Record<MechanicRow['status'], { color: string; tone: 'good' | 'serious' | 'neutral' }> = {
  MEETS: { color: STATUS.good, tone: 'good' },
  ABOVE: { color: STATUS.serious, tone: 'serious' },
  INSUFFICIENT_SAMPLE: { color: NEUTRAL, tone: 'neutral' },
  NO_BASELINE: { color: NEUTRAL, tone: 'neutral' },
};
const kpiStatusLabel = (s: string) => t(`dashboard.labels.kpi_${s}`);

const mechanicWoColumns: Column<Row>[] = [
  { key: 'wo_number', label: col('workOrder'), link: (r) => `/app/work-orders/${r.work_order_id}` },
  { key: 'registration_number', label: col('vehicle'), link: (r) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null) },
  { key: 'completed_at', label: col('completedAt'), kind: 'datetime' },
  { key: 'worker_hours', label: col('workHours'), unavailable: true },
  { key: 'rework_cycles', label: col('reworkCycles'), kind: 'num', unavailable: true },
  { key: 'work_time_state', label: col('workHistory'), value: (r) => t(`dashboard.labels.workTime_${r.work_time_state}`) },
  { key: 'valid_sample', label: col('countsAsSample'), kind: 'bool' },
];

const mechanicColumns: Column<Row>[] = [
  { key: 'worker_name', label: col('mechanic'), drill: (r) => ({ title: String(r.worker_name ?? '—'), params: { worker_id: r.worker_id }, columns: mechanicWoColumns }) },
  { key: 'workshop_name', label: col('workshop') },
  { key: 'wo_handled', label: col('woHandled'), kind: 'num' },
  { key: 'wo_completed', label: col('woCompleted'), kind: 'num' },
  { key: 'valid_samples', label: col('validSamples'), kind: 'num' },
  { key: 'excluded_samples', label: col('excludedSamples'), kind: 'num' },
  { key: 'avg_hours', label: col('avgHours'), unavailable: true },
  { key: 'baseline_hours', label: col('baselineHours') },
  { key: 'diff_hours', label: col('diffHours') },
  { key: 'ratio', label: col('ratio') },
  { key: 'status', label: col('kpiStatus'), value: (r) => kpiStatusLabel(String(r.status)) },
];

function BaselineNotice({ canManage, missing, onSaved }: { canManage: boolean; missing: boolean; onSaved: () => void }) {
  const [open, setOpen] = useState(false);
  if (!missing && !canManage) return null;
  return (
    <div className="dash-notice" role={missing ? 'status' : undefined}>
      {missing && <span>{t('dashboard.widgets.ws07.baselineNotSet')}</span>}
      {canManage
        ? <button type="button" className="dash-link-btn" onClick={() => setOpen(true)}>{t('dashboard.widgets.ws07.setBaselines')}</button>
        : <span>{t('dashboard.widgets.ws07.askAuthorized')}</span>}
      <BaselineSettings open={open} onClose={() => setOpen(false)} onSaved={onSaved} />
    </div>
  );
}

const WS07: WidgetDefinition<{ maintenance_type: string; baseline_hours: string | null; min_samples: number; can_manage_baseline: boolean;
  types: { maintenance_type: string; baseline_hours: string | null; valid_samples: number }[]; mechanics: MechanicRow[] }> = {
  size: 'xl',
  controls: (data, value, set) => data && (
    <label>
      {t('dashboard.filters.maintenanceType')}
      <select value={value.maintenance_type ?? data.maintenance_type} onChange={(e) => set('maintenance_type', e.target.value)}>
        {data.types.map((ty) => (
          <option key={ty.maintenance_type} value={ty.maintenance_type}>
            {codeLabel(ty.maintenance_type, 'type')} ({t('dashboard.widgets.ws07.samples', { count: ty.valid_samples })})
          </option>
        ))}
      </select>
    </label>
  ),
  render: (env, ctx) => {
    const d = env.data;
    const plotted = d.mechanics.filter((m) => m.avg_hours !== null);
    const meets = d.mechanics.filter((m) => m.status === 'MEETS').length;
    const above = d.mechanics.filter((m) => m.status === 'ABOVE').length;
    return (
      <>
        <BaselineNotice canManage={d.can_manage_baseline} missing={d.baseline_hours === null} onSaved={ctx.reload} />
        <div className="dash-kpi-row">
          <Kpi value={d.baseline_hours === null ? '—' : t('dashboard.units.hours', { value: d.baseline_hours })} label={t('dashboard.widgets.ws07.kpiBaseline', { type: codeLabel(d.maintenance_type, 'type') })} />
          <Kpi value={count(meets)} label={kpiStatusLabel('MEETS')} tone={meets > 0 ? 'good' : undefined} />
          <Kpi value={count(above)} label={kpiStatusLabel('ABOVE')} tone={above > 0 ? 'serious' : undefined} />
        </div>
        {d.mechanics.length === 0 ? <StateBox>{t('dashboard.widgets.ws07.empty')}</StateBox> : (
          <>
            {plotted.length === 0 && <StateBox>{t('dashboard.widgets.ws07.noValidSamples')}</StateBox>}
            {plotted.length > 0 && (
              <ScatterPlot xLabel={t('dashboard.widgets.ws07.axisX')} yLabel={t('dashboard.widgets.ws07.axisY')}
                reference={d.baseline_hours === null ? null : Number(d.baseline_hours)} referenceLabel={t('dashboard.widgets.ws07.baselineLine')}
                points={plotted.map((m) => ({
                  id: m.worker_id, label: m.worker_name ?? '—', x: m.wo_completed, y: Number(m.avg_hours), color: KPI_STATUS[m.status].color,
                  lines: [
                    t('dashboard.widgets.ws07.tipAvg', { value: m.avg_hours, n: m.valid_samples }),
                    ...(m.excluded_samples > 0 ? [t('dashboard.widgets.ws07.tipExcluded', { count: m.excluded_samples, total: m.completed_total })] : []),
                    kpiStatusLabel(m.status),
                  ],
                }))}
                onSelect={(p) => ctx.openDetail(p.label, { worker_id: p.id }, mechanicWoColumns)} />
            )}
            <Legend items={(['MEETS', 'ABOVE', 'INSUFFICIENT_SAMPLE'] as const).map((s) => ({ key: s, label: kpiStatusLabel(s), color: KPI_STATUS[s].color }))} />
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
              {d.mechanics.slice(0, 8).map((m) => (
                <Pill key={m.worker_id} tone={KPI_STATUS[m.status].tone === 'neutral' ? 'neutral' : KPI_STATUS[m.status].tone}>
                  {m.worker_name ?? '—'} · {m.avg_hours === null ? t('dashboard.states.unavailable') : t('dashboard.units.hours', { value: m.avg_hours })} · {kpiStatusLabel(m.status)}
                  {m.excluded_samples > 0 ? ` · ${t('dashboard.widgets.ws07.excludedShort', { count: m.excluded_samples })}` : ''}
                </Pill>
              ))}
            </div>
          </>
        )}
        <span className="dash-kpi-sub">{t('dashboard.widgets.ws07.note', { n: d.min_samples })}</span>
      </>
    );
  },
  table: (env) => ({ columns: mechanicColumns, rows: env.data.mechanics.map((m) => ({ ...m, id: m.worker_id })) }),
};

// ------------------------------------------------------------------ rework / first-pass rate (WS-08)

type ReworkSummary = { completed: number; first_pass: number; with_rework: number; rework_cycles: number; first_pass_rate: number | null };

const reworkColumns: Column<Row>[] = [
  { key: 'wo_number', label: col('workOrder'), link: (r) => `/app/work-orders/${r.id}` },
  { key: 'registration_number', label: col('vehicle'), link: (r) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null) },
  { key: 'workshop_name', label: col('workshop') },
  { key: 'maintenance_type', label: col('maintenanceType'), value: (r) => codeLabel(String(r.maintenance_type), 'type') },
  { key: 'completed_at', label: col('completedAt'), kind: 'datetime' },
  { key: 'rework_cycles', label: col('reworkCycles'), kind: 'num' },
];

const WS08: WidgetDefinition<{ totals: ReworkSummary; months: (ReworkSummary & { month: string; is_current: boolean })[]; workshops: (ReworkSummary & { workshop_id: string; workshop_name: string })[] }> = {
  size: 'm',
  isEmpty: (d) => d.totals.completed === 0,
  render: (env, ctx) => {
    const d = env.data;
    const rate = d.totals.first_pass_rate;
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={rate === null ? '—' : `${rate.toLocaleString(undefined, { maximumFractionDigits: 1 })}%`} label={t('dashboard.widgets.ws08.kpiRate')}
            tone={rate !== null && rate < 90 ? 'warning' : undefined} />
          <Kpi value={count(d.totals.with_rework)} label={t('dashboard.widgets.ws08.kpiRework', { n: d.totals.completed })} />
        </div>
        <ColumnChart unit="count" categoryKey="month" height={200}
          data={d.months.map((m) => ({ month: m.month, first_pass: m.first_pass, with_rework: m.with_rework }))}
          series={[{ key: 'first_pass', label: t('dashboard.widgets.ws08.firstPass'), color: SERIES[0] }, { key: 'with_rework', label: t('dashboard.widgets.ws08.withRework'), color: SERIES[1] }]}
          labelFormatter={(m) => monthLabel(m)} highlight={d.months.find((m) => m.is_current)?.month}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month, rework_only: 1 }, reworkColumns)} />
        <Legend items={[{ key: 'fp', label: t('dashboard.widgets.ws08.firstPass'), color: SERIES[0] }, { key: 'rw', label: t('dashboard.widgets.ws08.withRework'), color: SERIES[1] }]} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'workshop_name', label: col('workshop') }, { key: 'completed', label: col('woCompleted'), kind: 'num' },
      { key: 'with_rework', label: col('withRework'), kind: 'num' }, { key: 'first_pass_rate', label: col('firstPassRate') }],
    rows: env.data.workshops.map((w) => ({ ...w, id: w.workshop_id, first_pass_rate: w.first_pass_rate === null ? '—' : `${w.first_pass_rate}%` })),
  }),
  detail: { params: { rework_only: 1 }, columns: reworkColumns },
};

// ------------------------------------------------------------------ WH-02 low stock (replaces the earlier renderer)

type StockRow = Row & { id: string; product_name: string; warehouse_name: string; on_hand: string; reorder_point: string | null; uom: string | null; state: string };

/** Sets the reorder point through the existing Warehouse Stock threshold endpoint (inventory.adjust). */
function ThresholdDialog({ row, onClose, onSaved }: { row: StockRow | null; onClose: () => void; onSaved: () => void }) {
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  if (!row) return null;
  const save = async () => {
    setSaving(true);
    setError(null);
    try {
      await apiClient.put(`/app/inventory/${row.id}/thresholds`, { reorder_point: value === '' ? null : value });
      onSaved();
      onClose();
    } catch (e) {
      const err = extractApiError(e);
      setError(err.errors?.reorder_point?.[0] ?? err.message ?? t('dashboard.states.loadFailed'));
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal open title={t('dashboard.widgets.wh02.thresholdTitle', { product: row.product_name, warehouse: row.warehouse_name })} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('dashboard.widgets.wh02.thresholdHelp')}</p>
      <FormField label={`${t('dashboard.columns.threshold')}${row.uom ? ` (${row.uom})` : ''}`} errors={error ? [error] : undefined}>
        <NumericInput step="0.0001" min="0" value={value} placeholder={row.reorder_point ?? t('dashboard.labels.stockNotSet')}
          onChange={(e) => setValue(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button type="button" className="btn-secondary" onClick={onClose}>{t('common.actions.cancel')}</button>
        <button type="button" className="btn-primary" disabled={saving} onClick={save}>{t('common.actions.save')}</button>
      </div>
    </Modal>
  );
}

function LowStockBody({ data, reload, openNotSet, openAll }: { data: WH02Data; reload: () => void; openNotSet: () => void; openAll: () => void }) {
  const [editing, setEditing] = useState<StockRow | null>(null);
  const columns: Column<Row>[] = [
    ...stockColumns.filter((c) => ['product_name', 'sku', 'warehouse_name', 'on_hand', 'reorder_point', 'shortage', 'uom', 'ratio'].includes(c.key)),
    { key: 'state', label: col('state'), value: (r) => `${codeLabel(String(r.state), 'stock')}${r.needed_by_work_order ? ` · ${t('dashboard.widgets.wh02.neededFlag')}` : ''}` },
  ];
  return (
    <>
      <div className="dash-kpi-row">
        <Kpi value={count(data.by_state.OUT)} label={codeLabel('OUT', 'stock')} tone={data.by_state.OUT > 0 ? 'critical' : undefined} />
        <Kpi value={count(data.by_state.LOW)} label={codeLabel('LOW', 'stock')} tone={data.by_state.LOW > 0 ? 'warning' : undefined} />
        <Kpi value={count(data.needed_by_work_orders)} label={t('dashboard.widgets.wh02.kpiNeeded')} tone={data.needed_by_work_orders > 0 ? 'critical' : undefined} />
      </div>
      {data.by_state.NOT_SET > 0 && (
        <div className="dash-notice">
          <span>{t('dashboard.widgets.wh02.notSet', { n: data.by_state.NOT_SET })}</span>
          <button type="button" className="dash-link-btn" onClick={openNotSet}>{t('dashboard.actions.viewDetails')}</button>
          {data.can_manage_threshold
            ? <Link className="dash-link-btn" to="/app/inventory">{t('dashboard.widgets.wh02.openSettings')}</Link>
            : <span>{t('dashboard.widgets.wh02.askAuthorized')}</span>}
        </div>
      )}
      {data.items.length === 0 ? <StateBox>{t('dashboard.widgets.wh02.empty')}</StateBox> : (
        <div style={{ display: 'grid', gap: 6 }}>
          <DataTable rows={data.items} columns={columns} caption={t('dashboard.widgets.wh02.title')} />
          {data.can_manage_threshold && (
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }} aria-label={t('dashboard.widgets.wh02.setThreshold')}>
              {(data.items as StockRow[]).map((r) => (
                <button key={r.id} type="button" className="dash-chip-btn" onClick={() => setEditing(r)}>
                  {t('dashboard.widgets.wh02.setThresholdFor', { product: r.product_name, warehouse: r.warehouse_name })}
                </button>
              ))}
            </div>
          )}
        </div>
      )}
      <button type="button" className="dash-link-btn" style={{ justifySelf: 'start' }} onClick={openAll}>{t('dashboard.widgets.wh02.showAll')}</button>
      <ThresholdDialog row={editing} onClose={() => setEditing(null)} onSaved={reload} />
    </>
  );
}

type WH02Data = { count: number; by_state: { OUT: number; LOW: number; NOT_SET: number }; needed_by_work_orders: number; items: Row[];
  can_manage_threshold: boolean; options: { uoms: string[]; item_types: string[] } };

const WH02: WidgetDefinition<WH02Data> = {
  size: 'xl',
  isEmpty: (d) => d.count === 0 && d.by_state.NOT_SET === 0,
  controls: (data, value, set) => data && (
    <>
      <label>
        {t('dashboard.filters.uom')}
        <select value={value.uom ?? ''} onChange={(e) => set('uom', e.target.value)}>
          <option value="">{t('dashboard.filters.all')}</option>
          {data.options.uoms.map((u) => <option key={u} value={u}>{u}</option>)}
        </select>
      </label>
      <label>
        {t('dashboard.filters.itemType')}
        <select value={value.item_type ?? ''} onChange={(e) => set('item_type', e.target.value)}>
          <option value="">{t('dashboard.filters.all')}</option>
          {data.options.item_types.map((it) => <option key={it} value={it}>{itemTypeLabel(it)}</option>)}
        </select>
      </label>
    </>
  ),
  render: (env, ctx) => (
    <LowStockBody data={env.data} reload={ctx.reload}
      openNotSet={() => ctx.openDetail(codeLabel('NOT_SET', 'stock'), { state: 'NOT_SET' }, stockColumns)}
      openAll={() => ctx.openDetail('', {}, stockColumns)} />
  ),
  detail: { columns: stockColumns },
};

// ------------------------------------------------------------------ FN-05 inventory value by item type / warehouse

type ItemTypeRow = { item_type: string; sku_count: number; value: string; quantities: { uom: string | null; quantity: string }[]; warehouses: { warehouse_id: string; warehouse_name: string; value: string }[] };
type PendingValuation = { skus: number; used_skus: number; no_cost_skus: number; quantities: { reason: 'USED_STOCK' | 'NO_UNIT_COST'; item_type: string; uom: string | null; quantity: string }[] };
type FN05Data = { total: string; valued_skus: number; pending_valuation: PendingValuation; warehouses: { warehouse_id: string; warehouse_name: string; value: string; sku_count: number }[]; item_types: ItemTypeRow[]; in_transit: { transfers: number; value: string } };

const inventoryValueColumns: Column<Row>[] = [{ key: 'product_name', label: col('product') }, { key: 'sku', label: col('sku') },
  { key: 'item_type', label: col('itemType'), value: (r) => itemTypeLabel(String(r.item_type ?? '')) },
  { key: 'warehouse_name', label: col('warehouse') }, { key: 'quantity_on_hand', label: col('onHand'), kind: 'num' },
  { key: 'average_unit_cost', label: col('averageUnitCost'), kind: 'money' }, { key: 'value', label: col('value'), kind: 'money' }];

const pendingColumns: Column<Row>[] = [
  { key: 'product_name', label: col('product') }, { key: 'sku', label: col('sku') },
  { key: 'item_type', label: col('itemType'), value: (r) => itemTypeLabel(String(r.item_type ?? '')) },
  { key: 'warehouse_name', label: col('warehouse') },
  { key: 'reason', label: col('reason'), value: (r) => t(`dashboard.labels.pendingReason_${r.reason}`) },
  { key: 'quantity_on_hand', label: col('onHand'), kind: 'num' }, { key: 'uom', label: col('uom') },
  { key: 'value', label: col('value'), kind: 'money', unavailable: true },
];

const quantityText = (q: ItemTypeRow['quantities']) => q.map((x) => `${count(x.quantity)} ${x.uom ?? ''}`.trim()).join(' · ');

function InventoryValueBody({ env, openDetail, openPending }: { env: { data: FN05Data; currency: string }; openDetail: (title: string, params: Record<string, unknown>) => void; openPending: () => void }) {
  const [view, setView] = useState<'item_type' | 'warehouse'>('item_type');
  const d = env.data;
  return (
    <>
      <div className="dash-kpi-row">
        <Kpi value={money(d.total, env.currency)} label={t('dashboard.widgets.fn05.kpi')} />
        {d.pending_valuation.skus > 0 && <Kpi value={count(d.pending_valuation.skus)} label={t('dashboard.widgets.fn05.pendingKpi')} tone="warning" />}
        {d.in_transit.transfers > 0 && <Kpi value={money(d.in_transit.value, env.currency)} label={t('dashboard.widgets.fn05.inTransit', { n: d.in_transit.transfers })} />}
      </div>
      <div className="dash-segmented" role="group" aria-label={t('dashboard.widgets.fn05.groupBy')}>
        {(['item_type', 'warehouse'] as const).map((v) => (
          <button key={v} type="button" aria-pressed={view === v} onClick={() => setView(v)}>{t(`dashboard.widgets.fn05.by_${v}`)}</button>
        ))}
      </div>
      {view === 'item_type' ? (
        <HBarChart unit="money" currency={env.currency} labelWidth={120}
          data={d.item_types.map((r) => ({ type: itemTypeLabel(r.item_type), item_type: r.item_type, value: Number(r.value) }))}
          categoryKey="type" series={[{ key: 'value', label: t('dashboard.columns.value'), color: SERIES[0] }]}
          onSelect={(row) => openDetail(String(row.type), { item_type: row.item_type })} />
      ) : (
        <HBarChart unit="money" currency={env.currency}
          data={d.warehouses.slice(0, 8).map((w) => ({ warehouse: w.warehouse_name, warehouse_id: w.warehouse_id, value: Number(w.value) }))}
          categoryKey="warehouse" series={[{ key: 'value', label: t('dashboard.columns.value'), color: SERIES[0] }]}
          onSelect={(row) => openDetail(String(row.warehouse), { warehouse_id: row.warehouse_id })} />
      )}
      {d.pending_valuation.skus > 0 && (
        <div className="dash-notice" role="note">
          <span>{t('dashboard.widgets.fn05.pendingNote', { used: d.pending_valuation.used_skus, nocost: d.pending_valuation.no_cost_skus })}
            {' '}{d.pending_valuation.quantities.map((q) => `${t(`dashboard.labels.pendingReason_${q.reason}`)}: ${count(q.quantity)} ${q.uom ?? ''}`.trim()).join(' · ')}</span>
          <button type="button" className="dash-link-btn" onClick={openPending}>{t('dashboard.widgets.fn05.pendingLink')}</button>
        </div>
      )}
      <span className="dash-kpi-sub">{t('dashboard.widgets.fn05.note')}</span>
    </>
  );
}

const FN05: WidgetDefinition<FN05Data> = {
  size: 'm',
  isEmpty: (d) => d.item_types.length === 0 && d.warehouses.length === 0,
  render: (env, ctx) => <InventoryValueBody env={env} openDetail={(title, params) => ctx.openDetail(title, params, inventoryValueColumns)}
    openPending={() => ctx.openDetail(t('dashboard.widgets.fn05.pendingTitle'), { view: 'pending_valuation' }, pendingColumns)} />,
  table: (env) => ({
    columns: [{ key: 'item_type', label: col('itemType') }, { key: 'sku_count', label: col('skuCount'), kind: 'num' },
      { key: 'quantity', label: col('quantityPerUom') }, { key: 'value', label: col('value'), kind: 'money' }, { key: 'by_warehouse', label: col('byWarehouse') }],
    rows: env.data.item_types.map((r) => ({
      id: r.item_type, item_type: itemTypeLabel(r.item_type), sku_count: r.sku_count, quantity: quantityText(r.quantities), value: r.value,
      by_warehouse: r.warehouses.map((w) => `${w.warehouse_name}: ${money(w.value, env.currency)}`).join(' · '),
    })),
  }),
  detail: { columns: inventoryValueColumns },
};

// ------------------------------------------------------------------ WH-06 most used spareparts & tires

type UsedRow = { product_id: string; sku: string | null; product_name: string; item_type: string; uom: string | null; used_times: number; work_orders: number; events: number; quantity: string; vehicles: number };

const usedEventColumns: Column<Row>[] = [
  { key: 'consumed_at', label: col('consumedAt'), kind: 'datetime' },
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
  { key: 'registration_number', label: col('vehicle'), link: (r) => (r.vehicle_id ? `/app/vehicles/${r.vehicle_id}` : null) },
  { key: 'warehouse_name', label: col('warehouse') },
  { key: 'quantity', label: col('quantity'), kind: 'num' },
  { key: 'source', label: col('source'), value: (r) => t(`dashboard.labels.usedSource_${r.source}`) },
];

const usedColumns: Column<Row>[] = [
  { key: 'product_name', label: col('product'), drill: (r) => ({ title: String(r.product_name), params: { product_id: r.product_id }, columns: usedEventColumns }) },
  { key: 'sku', label: col('sku') },
  { key: 'item_type', label: col('itemType'), value: (r) => itemTypeLabel(String(r.item_type ?? '')) },
  { key: 'used_times', label: col('usedTimes'), kind: 'num' },
  { key: 'quantity', label: col('consumedQuantity'), kind: 'num' },
  { key: 'uom', label: col('uom') },
  { key: 'work_orders', label: col('workOrders'), kind: 'num' },
  { key: 'vehicles', label: col('vehicles'), kind: 'num' },
];

const WH06: WidgetDefinition<{ products: UsedRow[]; products_total: number; events: number; options: { item_types: string[]; categories: Option[] } }> = {
  size: 'xl',
  isEmpty: (d) => d.products.length === 0,
  controls: (data, value, set) => data && (
    <>
      <label>
        {t('dashboard.filters.itemType')}
        <select value={value.item_type ?? ''} onChange={(e) => set('item_type', e.target.value)}>
          <option value="">{t('dashboard.filters.all')}</option>
          {data.options.item_types.map((it) => <option key={it} value={it}>{itemTypeLabel(it)}</option>)}
        </select>
      </label>
      <label>
        {t('dashboard.filters.productCategory')}
        <select value={value.product_category_id ?? ''} onChange={(e) => set('product_category_id', e.target.value)}>
          <option value="">{t('dashboard.filters.all')}</option>
          {data.options.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </label>
    </>
  ),
  render: (env, ctx) => (
    <>
      <HBarChart unit="count" labelWidth={130} categoryKey="product"
        data={env.data.products.map((p) => ({ product: p.product_name, product_id: p.product_id, used_times: p.used_times }))}
        series={[{ key: 'used_times', label: t('dashboard.columns.usedTimes'), color: SERIES[0] }]}
        onSelect={(row) => ctx.openDetail(String(row.product), { product_id: row.product_id }, usedEventColumns)} />
      <DataTable rows={env.data.products as unknown as Row[]} columns={usedColumns.filter((c) => ['product_name', 'item_type', 'used_times', 'quantity', 'uom', 'vehicles'].includes(c.key))} />
      <span className="dash-kpi-sub">{t('dashboard.widgets.wh06.note')}</span>
    </>
  ),
  table: (env) => ({ columns: usedColumns, rows: env.data.products.map((p) => ({ ...p, id: p.product_id })) }),
  detail: { columns: usedColumns },
};

// ------------------------------------------------------------------ WH-07 part fulfilment lead time

type LeadStats = { requests: number; median_hours: number | null; p90_hours: number | null };

const leadColumns: Column<Row>[] = [
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
  { key: 'warehouse_name', label: col('warehouse') },
  { key: 'requested_at', label: col('requestedAt'), kind: 'datetime' },
  { key: 'issued_at', label: col('issuedAt'), kind: 'datetime' },
  { key: 'hours', label: col('hours') },
];
const openRequestColumns: Column<Row>[] = [
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
  { key: 'warehouse_name', label: col('warehouse') },
  { key: 'status', label: col('status'), value: (r) => codeLabel(String(r.status)) },
  { key: 'requested_at', label: col('requestedAt'), kind: 'datetime' },
  { key: 'age_hours', label: col('ageHours') },
];
const hoursText = (v: number | null) => (v === null ? '—' : t('dashboard.units.hours', { value: v.toLocaleString(undefined, { maximumFractionDigits: 1 }) }));

const WH07: WidgetDefinition<{ totals: LeadStats; months: (LeadStats & { month: string; is_current: boolean })[]; warehouses: (LeadStats & { warehouse_id: string; warehouse_name: string })[];
  open: { requests: number; oldest_hours: number | null }; waiting_part: { episodes: number; work_orders: number; hours: number; median_hours: number | null } | null }> = {
  size: 'm',
  isEmpty: (d) => d.totals.requests === 0 && d.open.requests === 0,
  render: (env, ctx) => {
    const d = env.data;
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={hoursText(d.totals.median_hours)} label={t('dashboard.widgets.wh07.kpiMedian', { n: d.totals.requests })} />
          <Kpi value={hoursText(d.totals.p90_hours)} label={t('dashboard.widgets.wh07.kpiP90')} />
          <Kpi value={count(d.open.requests)} label={t('dashboard.widgets.wh07.kpiOpen')} tone={d.open.requests > 0 ? 'warning' : undefined} />
        </div>
        <ColumnChart unit="count" categoryKey="month" height={180}
          data={d.months.map((m) => ({ month: m.month, median_hours: m.median_hours }))}
          series={[{ key: 'median_hours', label: t('dashboard.widgets.wh07.medianHours'), color: SERIES[0] }]}
          labelFormatter={(m) => monthLabel(m)} highlight={d.months.find((m) => m.is_current)?.month}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, leadColumns)} />
        {d.waiting_part && (
          <span className="dash-kpi-sub">{t('dashboard.widgets.wh07.waitingPart', { n: d.waiting_part.work_orders, hours: d.waiting_part.hours, median: d.waiting_part.median_hours ?? '—' })}</span>
        )}
        {d.open.requests > 0 && (
          <button type="button" className="dash-link-btn" style={{ justifySelf: 'start' }} onClick={() => ctx.openDetail(t('dashboard.widgets.wh07.kpiOpen'), { open: 1 }, openRequestColumns)}>
            {t('dashboard.widgets.wh07.showOpen')}
          </button>
        )}
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'warehouse_name', label: col('warehouse') }, { key: 'requests', label: col('requests'), kind: 'num' },
      { key: 'median', label: col('medianHours') }, { key: 'p90', label: col('p90Hours') }],
    rows: env.data.warehouses.map((w) => ({ id: w.warehouse_id, warehouse_name: w.warehouse_name, requests: w.requests, median: hoursText(w.median_hours), p90: hoursText(w.p90_hours) })),
  }),
  detail: { columns: leadColumns },
};

// ------------------------------------------------------------------ FL-07 installed components

const installedItemColumns: Column<Row>[] = [
  { key: 'item_type', label: col('itemType'), value: (r) => itemTypeLabel(String(r.item_type ?? '')) },
  { key: 'product_name', label: col('product'), link: (r) => (r.tire_id ? `/app/tires/${r.tire_id}` : null) },
  { key: 'serial_number', label: col('serialNumber') },
  { key: 'position', label: col('position') },
  { key: 'installed_at', label: col('installedAt'), kind: 'date' },
  { key: 'wo_number', label: col('workOrder'), link: (r) => (r.work_order_id ? `/app/work-orders/${r.work_order_id}` : null) },
  { key: 'cost', label: col('installationCost'), kind: 'money', unavailable: true },
  { key: 'cost_basis', label: col('costBasis'), value: (r) => t(`dashboard.labels.costBasis_${r.cost_basis}`) },
];
const installedVehicleColumns: Column<Row>[] = [
  { key: 'registration_number', label: col('vehicle'), drill: (r) => ({ title: String(r.registration_number ?? '—'), params: { vehicle_id: r.vehicle_id }, columns: installedItemColumns }) },
  { key: 'items', label: col('installedItems'), kind: 'num' },
  { key: 'unvalued', label: col('withoutCost'), kind: 'num' },
  { key: 'value', label: col('installationCost'), kind: 'money', unavailable: true },
];

type InstalledGroup = { items: number; unvalued: number; value: string | null };
type Untracked = { lines: number; products: number; quantities: { uom: string | null; quantity: string }[] };

const FL07: WidgetDefinition<{ values_visible: boolean; untracked: Untracked; totals: InstalledGroup & { vehicles: number }; by_type: (InstalledGroup & { item_type: string })[];
  vehicles: (InstalledGroup & { vehicle_id: string; registration_number: string })[] }> = {
  size: 'm',
  isEmpty: (d) => d.totals.items === 0,
  render: (env, ctx) => {
    const d = env.data;
    return (
      <>
        <div className="dash-kpi-row">
          {d.values_visible && <Kpi value={d.totals.value === null ? <Unavailable /> : money(d.totals.value, env.currency)} label={t('dashboard.widgets.fl07.kpiValue')} />}
          <Kpi value={count(d.totals.items)} label={t('dashboard.widgets.fl07.kpiItems', { n: d.totals.vehicles })} />
          <Kpi value={count(d.totals.unvalued)} label={t('dashboard.widgets.fl07.kpiUnvalued')} />
        </div>
        <HBarChart unit={d.values_visible ? 'money' : 'count'} currency={env.currency} labelWidth={110} categoryKey="vehicle"
          data={d.vehicles.map((v) => ({ vehicle: v.registration_number, vehicle_id: v.vehicle_id, value: d.values_visible ? (v.value === null ? null : Number(v.value)) : v.items }))}
          series={[{ key: 'value', label: d.values_visible ? t('dashboard.columns.installationCost') : t('dashboard.columns.installedItems'), color: SERIES[0] }]}
          onSelect={(row) => ctx.openDetail(String(row.vehicle), { vehicle_id: row.vehicle_id }, installedItemColumns)} />
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {d.by_type.map((g) => (
            <Pill key={g.item_type} tone="neutral">
              {itemTypeLabel(g.item_type)}: {count(g.items)}{d.values_visible && g.items > g.unvalued ? ` · ${money(g.value, env.currency)}` : ''}
              {g.unvalued > 0 ? ` · ${t('dashboard.widgets.fl07.unvaluedShort', { n: g.unvalued })}` : ''}
            </Pill>
          ))}
        </div>
        {d.untracked.lines > 0 && (
          <div className="dash-notice" role="note">
            {t('dashboard.widgets.fl07.untracked', { lines: d.untracked.lines, products: d.untracked.products })}{' '}
            {d.untracked.quantities.map((q) => `${count(q.quantity)} ${q.uom ?? ''}`.trim()).join(' · ')}
          </div>
        )}
        <span className="dash-kpi-sub">{t('dashboard.widgets.fl07.note')}</span>
      </>
    );
  },
  table: (env) => ({ columns: installedVehicleColumns, rows: env.data.vehicles.map((v) => ({ ...v, id: v.vehicle_id })) }),
  detail: { columns: installedVehicleColumns },
};

// ------------------------------------------------------------------ PR-05 procurement cycle

type CycleStats = { orders: number; with_pr: number; pr_to_po: number | null; po_to_gr: number | null; pr_to_gr: number | null };
const daysText = (v: number | null) => (v === null ? '—' : t('dashboard.units.days', { count: v }));

const cycleColumns: Column<Row>[] = [
  { key: 'po_number', label: col('purchaseOrder'), link: (r) => `/app/purchase-orders/${r.id}` },
  { key: 'pr_number', label: col('purchaseRequest'), link: (r) => (r.pr_id ? `/app/purchase-requests/${r.pr_id}` : null) },
  { key: 'vendor_name', label: col('vendor') },
  { key: 'pr_created_on', label: col('prCreated'), kind: 'date' },
  { key: 'order_date', label: col('orderDate'), kind: 'date' },
  { key: 'first_receipt_on', label: col('firstReceipt'), kind: 'date' },
  { key: 'pr_to_po', label: col('prToPoDays'), kind: 'num' },
  { key: 'po_to_gr', label: col('poToGrDays'), kind: 'num' },
  { key: 'pr_to_gr', label: col('prToGrDays'), kind: 'num' },
];

const PR05: WidgetDefinition<{ totals: CycleStats; months: (CycleStats & { month: string; is_current: boolean })[]; vendors: (CycleStats & { partner_id: string; vendor_name: string })[] }> = {
  size: 'm',
  isEmpty: (d) => d.totals.orders === 0,
  render: (env, ctx) => {
    const d = env.data;
    return (
      <>
        <div className="dash-kpi-row">
          <Kpi value={daysText(d.totals.pr_to_po)} label={t('dashboard.widgets.pr05.kpiPrPo')} />
          <Kpi value={daysText(d.totals.po_to_gr)} label={t('dashboard.widgets.pr05.kpiPoGr')} />
          <Kpi value={daysText(d.totals.pr_to_gr)} label={t('dashboard.widgets.pr05.kpiTotal', { n: d.totals.orders })} />
        </div>
        <ColumnChart unit="count" categoryKey="month" height={180} grouped
          data={d.months.map((m) => ({ month: m.month, pr_to_po: m.pr_to_po, po_to_gr: m.po_to_gr }))}
          series={[{ key: 'pr_to_po', label: t('dashboard.widgets.pr05.kpiPrPo'), color: SERIES[0] }, { key: 'po_to_gr', label: t('dashboard.widgets.pr05.kpiPoGr'), color: SERIES[1] }]}
          labelFormatter={(m) => monthLabel(m)} highlight={d.months.find((m) => m.is_current)?.month}
          onSelect={(month) => ctx.openDetail(monthLabel(month, 'long'), { month }, cycleColumns)} />
        <Legend items={[{ key: 'a', label: t('dashboard.widgets.pr05.kpiPrPo'), color: SERIES[0] }, { key: 'b', label: t('dashboard.widgets.pr05.kpiPoGr'), color: SERIES[1] }]} />
      </>
    );
  },
  table: (env) => ({
    columns: [{ key: 'vendor_name', label: col('vendor') }, { key: 'orders', label: col('orders'), kind: 'num' },
      { key: 'pr_to_po', label: col('prToPoDays') }, { key: 'po_to_gr', label: col('poToGrDays') }, { key: 'pr_to_gr', label: col('prToGrDays') }],
    rows: env.data.vendors.map((v) => ({ id: v.partner_id, vendor_name: v.vendor_name, orders: v.orders, pr_to_po: daysText(v.pr_to_po), po_to_gr: daysText(v.po_to_gr), pr_to_gr: daysText(v.pr_to_gr) })),
  }),
  detail: { columns: cycleColumns },
};

export const OPS_WIDGETS: Record<string, WidgetDefinition> = {
  'FN-07': FN07,
  'FN-08': FN08,
  'WS-07': WS07,
  'WS-08': WS08,
  'WH-02': WH02,
  'FN-05': FN05,
  'WH-06': WH06,
  'WH-07': WH07,
  'FL-07': FL07,
  'PR-05': PR05,
};
