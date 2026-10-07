import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { t } from '../../../i18n/i18n';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDate, formatDateTime } from '../../../utils/date';
import { useDetails } from './api';
import { compactMoney, count, days, money } from './format';
import { headerText } from './labels';
import { INK } from './palette';

// ------------------------------------------------------------------ states

export function Skeleton({ height = 120 }: { height?: number }) {
  return (
    <div aria-hidden="true" style={{ display: 'grid', gap: 8 }}>
      <div className="dash-skeleton" style={{ height: 28, width: '40%' }} />
      <div className="dash-skeleton" style={{ height }} />
    </div>
  );
}

export function StateBox({ tone = 'neutral', children }: { tone?: 'neutral' | 'error'; children: ReactNode }) {
  return (
    <div className={`dash-state${tone === 'error' ? ' dash-state-error' : ''}`} role={tone === 'error' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

// ------------------------------------------------------------------ figures

export function Kpi({ value, label, tone }: { value: ReactNode; label?: ReactNode; tone?: 'critical' | 'serious' | 'warning' | 'good' }) {
  const color = tone === 'critical' ? '#b91c1c' : tone === 'serious' ? '#9a3412' : tone === 'warning' ? '#92400e' : undefined;
  return (
    <div>
      <div className="dash-kpi" style={color ? { color } : undefined}>
        {value}
      </div>
      {label && <div className="dash-kpi-sub">{label}</div>}
    </div>
  );
}

export function Pill({ tone, children }: { tone: 'critical' | 'serious' | 'warning' | 'good' | 'neutral'; children: ReactNode }) {
  return <span className={`dash-pill dash-pill-${tone}`}>{children}</span>;
}

// ------------------------------------------------------------------ segmented (part-to-whole) bar

export interface Segment {
  key: string;
  label: string;
  value: number;
  color: string;
}

/**
 * One horizontal 100% bar for a current distribution (≤ 6–7 classes), with a legend that carries the
 * exact counts — identity never relies on color alone. Segments and legend items drill down.
 */
export function SegmentBar({ segments, onSelect, ariaLabel }: { segments: Segment[]; onSelect?: (key: string) => void; ariaLabel: string }) {
  const [hover, setHover] = useState<string | null>(null);
  const total = segments.reduce((s, x) => s + x.value, 0);
  const visible = segments.filter((s) => s.value > 0);
  const hovered = visible.find((s) => s.key === hover);
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      <div style={{ position: 'relative' }}>
        <div className="dash-segbar" role="img" aria-label={ariaLabel}>
          {total === 0 && <div style={{ flex: 1, background: '#f3f4f6' }} />}
          {visible.map((s) => (
            <button
              key={s.key}
              type="button"
              aria-label={`${s.label}: ${count(s.value)}`}
              style={{ flex: s.value, background: s.color, opacity: hover && hover !== s.key ? 0.55 : 1 }}
              onMouseEnter={() => setHover(s.key)}
              onMouseLeave={() => setHover(null)}
              onFocus={() => setHover(s.key)}
              onBlur={() => setHover(null)}
              onClick={() => onSelect?.(s.key)}
              tabIndex={onSelect ? 0 : -1}
            />
          ))}
        </div>
        {hovered && (
          <div className="dash-chart-tooltip" style={{ position: 'absolute', top: 20, left: 0, zIndex: 5 }}>
            <div className="dash-chart-tooltip-row">
              <span>
                <span className="dash-swatch" style={{ background: hovered.color, marginRight: 6 }} />
                {hovered.label}
              </span>
              <span>{count(hovered.value)}</span>
            </div>
            <div className="dash-chart-tooltip-row" style={{ color: INK.muted }}>
              <span>{t('dashboard.chart.shareOfTotal')}</span>
              <span>{total ? Math.round((hovered.value / total) * 100) : 0}%</span>
            </div>
          </div>
        )}
      </div>
      <Legend items={segments.map((s) => ({ key: s.key, label: s.label, color: s.color, value: count(s.value) }))} onSelect={onSelect} />
    </div>
  );
}

export function Legend({ items, onSelect }: { items: { key: string; label: string; color: string; value?: string }[]; onSelect?: (key: string) => void }) {
  return (
    <ul className="dash-legend">
      {items.map((item) => {
        const content = (
          <>
            <span className="dash-swatch" style={{ background: item.color }} aria-hidden="true" />
            <span>{item.label}</span>
            {item.value !== undefined && <strong>{item.value}</strong>}
          </>
        );
        return <li key={item.key}>{onSelect ? <button type="button" onClick={() => onSelect(item.key)}>{content}</button> : content}</li>;
      })}
    </ul>
  );
}

// ------------------------------------------------------------------ tables

export type CellKind = 'text' | 'num' | 'money' | 'date' | 'datetime' | 'status' | 'days' | 'bool';

export interface Column<Row> {
  key: string;
  label: string;
  kind?: CellKind;
  value?: (row: Row) => unknown;
  link?: (row: Row) => string | null;
}

export function renderCell(kind: CellKind | undefined, value: unknown, currency?: string): ReactNode {
  if (value === null || value === undefined || value === '') return '—';
  switch (kind) {
    case 'num':
      return count(value as number);
    case 'money':
      return money(value as string, currency);
    case 'date':
      return formatDate(String(value));
    case 'datetime':
      return formatDateTime(String(value));
    case 'status':
      return statusLabel(String(value));
    case 'days':
      return days(value as number);
    case 'bool':
      return value ? t('common.fields.yes') : t('common.fields.no');
    default:
      return String(value);
  }
}

export function DataTable<Row extends Record<string, unknown>>({ columns, rows, currency, caption }: { columns: Column<Row>[]; rows: Row[]; currency?: string; caption?: string }) {
  if (rows.length === 0) return <StateBox>{t('dashboard.states.noRows')}</StateBox>;
  return (
    <div className="dash-table-wrap">
      <table className="dash-table">
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key} scope="col" className={c.kind === 'num' || c.kind === 'money' || c.kind === 'days' ? 'num' : undefined}>
                {headerText(c.label)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={(row.id as string) ?? i}>
              {columns.map((c) => {
                const raw = c.value ? c.value(row) : row[c.key];
                const content = renderCell(c.kind, raw, currency);
                const href = c.link?.(row);
                return (
                  <td key={c.key} className={c.kind === 'num' || c.kind === 'money' || c.kind === 'days' ? 'num' : undefined}>
                    {href ? <Link className="entity-link" to={href}>{content}</Link> : content}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ------------------------------------------------------------------ Recharts wrappers

export interface BarSeries {
  key: string;
  label: string;
  color: string;
}

function ChartTooltip({ active, payload, label, series, unit, currency, labelFormatter, footer }: {
  active?: boolean;
  payload?: { dataKey?: string | number; value?: number }[];
  label?: string;
  series: BarSeries[];
  unit: 'count' | 'money';
  currency?: string;
  labelFormatter?: (label: string) => string;
  footer?: (label: string) => ReactNode;
}) {
  if (!active || !payload?.length || label === undefined) return null;
  const fmt = (v: number) => (unit === 'money' ? money(v, currency) : count(v));
  const total = payload.reduce((s, p) => s + (Number(p.value) || 0), 0);
  return (
    <div className="dash-chart-tooltip">
      <div style={{ fontWeight: 600, marginBottom: 4 }}>{labelFormatter ? labelFormatter(label) : label}</div>
      {series.map((s) => {
        const p = payload.find((x) => x.dataKey === s.key);
        if (!p) return null;
        return (
          <div className="dash-chart-tooltip-row" key={s.key}>
            <span>
              <span className="dash-swatch" style={{ background: s.color, marginRight: 6 }} />
              {s.label}
            </span>
            <span>{fmt(Number(p.value) || 0)}</span>
          </div>
        );
      })}
      {series.length > 1 && (
        <div className="dash-chart-tooltip-row" style={{ borderTop: '1px solid #e5e7eb', marginTop: 4, paddingTop: 4 }}>
          <span>{t('dashboard.chart.total')}</span>
          <span>{fmt(total)}</span>
        </div>
      )}
      {footer?.(label)}
    </div>
  );
}

/**
 * Vertical columns (stacked when several series) on one value axis — months or ordered buckets on X.
 * Clicking a column calls onSelect with its category.
 */
export function ColumnChart({ data, categoryKey, series, unit, currency, height = 240, labelFormatter, onSelect, highlight, colorFor, footer }: {
  data: Record<string, unknown>[];
  categoryKey: string;
  series: BarSeries[];
  unit: 'count' | 'money';
  currency?: string;
  height?: number;
  labelFormatter?: (label: string) => string;
  onSelect?: (category: string) => void;
  /** Category drawn with a lighter fill (e.g. the running month). */
  highlight?: string;
  /** Per-category color for single-series ordinal charts. */
  colorFor?: (category: string) => string;
  footer?: (label: string) => ReactNode;
}) {
  const tick = { fontSize: 11, fill: INK.muted };
  return (
    <div style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }} barCategoryGap="22%">
          <CartesianGrid vertical={false} stroke={INK.grid} />
          <XAxis dataKey={categoryKey} tick={tick} tickLine={false} axisLine={{ stroke: INK.axis }} tickFormatter={labelFormatter} interval="preserveStartEnd" minTickGap={4} />
          <YAxis tick={tick} tickLine={false} axisLine={false} width={unit === 'money' ? 56 : 36} allowDecimals={unit === 'money'}
            tickFormatter={(v: number) => (unit === 'money' ? compactMoney(v) : count(v))} />
          <Tooltip cursor={{ fill: 'rgba(17,24,39,0.04)' }}
            content={(props) => <ChartTooltip {...(props as object)} series={series} unit={unit} currency={currency} labelFormatter={labelFormatter} footer={footer} />} />
          {series.map((s, i) => (
            <Bar key={s.key} dataKey={s.key} stackId="a" fill={s.color} stroke="#fff" strokeWidth={series.length > 1 ? 1 : 0}
              radius={i === series.length - 1 ? [4, 4, 0, 0] : [0, 0, 0, 0]} maxBarSize={44}
              cursor={onSelect ? 'pointer' : undefined} onClick={(entry: { payload?: Record<string, unknown> }) => onSelect?.(String(entry.payload?.[categoryKey]))}>
              {data.map((row) => {
                const cat = String(row[categoryKey]);
                const fill = colorFor ? colorFor(cat) : s.color;
                return <Cell key={cat} fill={fill} fillOpacity={highlight === cat ? 0.55 : 1} />;
              })}
            </Bar>
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

/** Horizontal bars (stacked when several series) for rankings and per-branch/workshop comparisons. */
export function HBarChart({ data, categoryKey, series, unit, currency, onSelect, labelWidth = 120 }: {
  data: Record<string, unknown>[];
  categoryKey: string;
  series: BarSeries[];
  unit: 'count' | 'money';
  currency?: string;
  onSelect?: (row: Record<string, unknown>) => void;
  labelWidth?: number;
}) {
  const tick = { fontSize: 11, fill: INK.secondary };
  const height = Math.max(120, data.length * 34 + 40);
  return (
    <div style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} layout="vertical" margin={{ top: 4, right: 16, bottom: 0, left: 0 }} barCategoryGap="28%">
          <CartesianGrid horizontal={false} stroke={INK.grid} />
          <XAxis type="number" tick={{ fontSize: 11, fill: INK.muted }} tickLine={false} axisLine={false} allowDecimals={unit === 'money'}
            tickFormatter={(v: number) => (unit === 'money' ? compactMoney(v) : count(v))} />
          <YAxis type="category" dataKey={categoryKey} tick={tick} tickLine={false} axisLine={{ stroke: INK.axis }} width={labelWidth}
            tickFormatter={(v: string) => (v.length > 18 ? `${v.slice(0, 17)}…` : v)} />
          <Tooltip cursor={{ fill: 'rgba(17,24,39,0.04)' }}
            content={(props) => <ChartTooltip {...(props as object)} series={series} unit={unit} currency={currency} />} />
          {series.map((s, i) => (
            <Bar key={s.key} dataKey={s.key} stackId="a" fill={s.color} stroke="#fff" strokeWidth={series.length > 1 ? 1 : 0}
              radius={i === series.length - 1 ? [0, 4, 4, 0] : [0, 0, 0, 0]} maxBarSize={22}
              cursor={onSelect ? 'pointer' : undefined} onClick={(entry: { payload?: Record<string, unknown> }) => entry.payload && onSelect?.(entry.payload)} />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

// ------------------------------------------------------------------ drill-down

export interface DetailRequest {
  widgetId: string;
  title: string;
  params: Record<string, unknown>;
  columns: Column<Record<string, unknown>>[];
}

export function DetailModal({ request, currency, onClose }: { request: DetailRequest | null; currency?: string; onClose: () => void }) {
  return (
    <Modal open={request !== null} title={request?.title ?? ''} onClose={onClose} width={880}>
      {request && <DetailBody request={request} currency={currency} />}
    </Modal>
  );
}

function DetailBody({ request, currency }: { request: DetailRequest; currency?: string }) {
  const { state, result, error, page, setPage, reload } = useDetails<Record<string, unknown>>(request.widgetId, request.params);
  if (state === 'loading' && !result) return <Skeleton height={160} />;
  if (state === 'error' || state === 'forbidden') {
    return (
      <StateBox tone="error">
        {error}{' '}
        {state === 'error' && <button type="button" className="dash-link-btn" onClick={() => reload()}>{t('dashboard.actions.retry')}</button>}
      </StateBox>
    );
  }
  if (!result) return null;
  return (
    <div style={{ display: 'grid', gap: 12 }}>
      <DataTable columns={request.columns} rows={result.data} currency={currency ?? result.currency} caption={request.title} />
      {result.meta && result.meta.last_page > 1 && (
        <Pagination meta={{ current_page: page, last_page: result.meta.last_page, total: result.meta.total, per_page: result.meta.per_page }} onPageChange={setPage} />
      )}
    </div>
  );
}
