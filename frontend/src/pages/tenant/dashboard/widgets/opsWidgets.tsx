import { useState } from 'react';
import { t } from '../../../../i18n/i18n';
import { BaselineSettings } from '../BaselineSettings';
import { ColumnChart, HBarChart, Kpi, Legend, Pill, ScatterPlot, StateBox, type Column } from '../components';
import { count, monthLabel, money } from '../format';
import { codeLabel, col } from '../labels';
import { NEUTRAL, SERIES, STATUS } from '../palette';
import type { WidgetDefinition } from '../WidgetCard';

type Row = Record<string, unknown>;
type CostSums = { PARTS: string; LABOR: string; EXTERNAL_PAID: string; total: string };

// ------------------------------------------------------------------ operating cost (FN-07 / FN-08)

/** Fixed slots: color follows the cost component, never its rank. */
const COST_SERIES = [
  { key: 'PARTS', color: SERIES[0] },
  { key: 'LABOR', color: SERIES[3] },
  { key: 'EXTERNAL_PAID', color: SERIES[2] },
] as const;

const componentLabel = (key: string) => t(`dashboard.labels.opCost_${key}`);
const costSeries = () => COST_SERIES.map((s) => ({ key: s.key, label: componentLabel(s.key), color: s.color }));
const costNumbers = (r: CostSums) => ({ PARTS: Number(r.PARTS), LABOR: Number(r.LABOR), EXTERNAL_PAID: Number(r.EXTERNAL_PAID) });
const costColumns = (): Column<Row>[] => [
  ...COST_SERIES.map((s) => ({ key: s.key, label: `dashboard.labels.opCost_${s.key}`, kind: 'money' as const })),
  { key: 'total', label: col('total'), kind: 'money' },
];

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
  { key: 'history_complete', label: col('workHistory'), value: (r) => (r.history_complete ? t('dashboard.labels.historyComplete') : t('dashboard.labels.historyIncomplete')) },
];

const rankingColumns: Column<Row>[] = [
  {
    key: 'registration_number', label: col('vehicle'),
    drill: (r) => ({ title: String(r.registration_number ?? '—'), params: { vehicle_id: r.vehicle_id }, columns: workOrderColumns }),
  },
  { key: 'category_name', label: col('category') },
  { key: 'branch_name', label: col('branch') },
  ...costColumns(),
];

type Option = { id: string; name: string };

const FN07: WidgetDefinition<{ totals: CostSums; vehicles: (CostSums & Row)[]; vehicles_total: number; vehicles_with_cost: number; options: { categories: Option[]; vehicles: (Option & { category_id: string | null })[] } }> = {
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
  render: (env, ctx) => (
    <>
      <div className="dash-kpi-row">
        <Kpi value={money(env.data.totals.total, env.currency)} label={t('dashboard.widgets.fn07.kpiTotal')} />
        <Kpi value={`${count(env.data.vehicles_with_cost)} / ${count(env.data.vehicles_total)}`} label={t('dashboard.widgets.fn07.kpiVehicles')} />
      </div>
      {Number(env.data.totals.total) === 0 ? <StateBox>{t('dashboard.widgets.fn07.empty')}</StateBox> : (
        <HBarChart unit="money" currency={env.currency} labelWidth={110} categoryKey="vehicle" series={costSeries()}
          data={env.data.vehicles.filter((v) => Number(v.total) > 0).map((v) => ({ vehicle: String(v.registration_number ?? '—'), vehicle_id: v.vehicle_id, ...costNumbers(v) }))}
          onSelect={(row) => ctx.openDetail(String(row.vehicle), { vehicle_id: row.vehicle_id }, workOrderColumns)} />
      )}
      <Legend items={costSeries().map((s) => ({ key: s.key, label: s.label, color: s.color, value: money((env.data.totals as Record<string, string>)[s.key], env.currency) }))} />
      <span className="dash-kpi-sub">{t('dashboard.widgets.fn07.note')}</span>
    </>
  ),
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
  wo_handled: number; wo_completed: number; valid_samples: number; avg_hours: string | null;
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
  { key: 'worker_hours', label: col('workHours') },
  { key: 'rework_cycles', label: col('reworkCycles'), kind: 'num' },
  { key: 'history_complete', label: col('workHistory'), value: (r) => (r.history_complete ? t('dashboard.labels.historyComplete') : t('dashboard.labels.historyIncomplete')) },
];

const mechanicColumns: Column<Row>[] = [
  { key: 'worker_name', label: col('mechanic'), drill: (r) => ({ title: String(r.worker_name ?? '—'), params: { worker_id: r.worker_id }, columns: mechanicWoColumns }) },
  { key: 'workshop_name', label: col('workshop') },
  { key: 'wo_handled', label: col('woHandled'), kind: 'num' },
  { key: 'wo_completed', label: col('woCompleted'), kind: 'num' },
  { key: 'valid_samples', label: col('validSamples'), kind: 'num' },
  { key: 'avg_hours', label: col('avgHours') },
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
            {plotted.length > 0 && (
              <ScatterPlot xLabel={t('dashboard.widgets.ws07.axisX')} yLabel={t('dashboard.widgets.ws07.axisY')}
                reference={d.baseline_hours === null ? null : Number(d.baseline_hours)} referenceLabel={t('dashboard.widgets.ws07.baselineLine')}
                points={plotted.map((m) => ({
                  id: m.worker_id, label: m.worker_name ?? '—', x: m.wo_completed, y: Number(m.avg_hours), color: KPI_STATUS[m.status].color,
                  lines: [
                    t('dashboard.widgets.ws07.tipAvg', { value: m.avg_hours, n: m.valid_samples }),
                    kpiStatusLabel(m.status),
                  ],
                }))}
                onSelect={(p) => ctx.openDetail(p.label, { worker_id: p.id }, mechanicWoColumns)} />
            )}
            <Legend items={(['MEETS', 'ABOVE', 'INSUFFICIENT_SAMPLE'] as const).map((s) => ({ key: s, label: kpiStatusLabel(s), color: KPI_STATUS[s].color }))} />
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
              {d.mechanics.slice(0, 8).map((m) => (
                <Pill key={m.worker_id} tone={KPI_STATUS[m.status].tone === 'neutral' ? 'neutral' : KPI_STATUS[m.status].tone}>
                  {m.worker_name ?? '—'} · {m.avg_hours === null ? '—' : t('dashboard.units.hours', { value: m.avg_hours })} · {kpiStatusLabel(m.status)}
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

export const OPS_WIDGETS: Record<string, WidgetDefinition> = {
  'FN-07': FN07,
  'FN-08': FN08,
  'WS-07': WS07,
  'WS-08': WS08,
};
