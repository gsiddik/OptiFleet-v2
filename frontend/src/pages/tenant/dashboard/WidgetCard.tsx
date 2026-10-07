import { useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { InfoTip } from '../../../components/InfoTip';
import { t } from '../../../i18n/i18n';
import { useWidget, widgetParams } from './api';
import { DataTable, Skeleton, StateBox, type Column, type DetailRequest } from './components';
import { updatedAt } from './format';
import { widgetKey, widgetTitle } from './labels';
import type { CatalogWidget, DashboardFilters, WidgetEnvelope } from './types';

export type WidgetSize = 's' | 'm' | 'l' | 'xl';

export interface RenderContext {
  /** Opens the drill-down for this widget with extra parameters (validated server-side). */
  openDetail: (title: string, params: Record<string, unknown>, columns: Column<Record<string, unknown>>[], widgetId?: string) => void;
  currency: string;
  /** Re-fetch this widget (e.g. after its configuration was changed from inside the card). */
  reload: () => void;
}

export interface WidgetDefinition<T = any> {
  size: WidgetSize;
  render: (env: WidgetEnvelope<T>, ctx: RenderContext) => ReactNode;
  /** True when the widget has nothing to show (rendered as the empty state, not as zeros). */
  isEmpty?: (data: T) => boolean;
  /** Accessible table alternative of the chart (toggle). */
  table?: (env: WidgetEnvelope<T>) => { columns: Column<Record<string, unknown>>[]; rows: Record<string, unknown>[] };
  /** Main "view all" drill-down. */
  detail?: { params?: Record<string, unknown>; columns: Column<Record<string, unknown>>[] };
  /**
   * Widget-specific filters (e.g. vehicle category, maintenance type) shown inside the card. Values
   * are sent with the widget request and its drill-downs and validated server-side.
   */
  controls?: (data: T | null, value: Record<string, string>, set: (key: string, value: string) => void) => ReactNode;
}

/** One widget: its own request, skeleton, empty / error / no-access states, retry and drill-down. */
export function WidgetCard({ widget, definition, filters, currency, onDetail, fullWidth = false }: {
  widget: CatalogWidget;
  /** Span the whole row (e.g. the only widget of a tab) instead of the definition's size. */
  fullWidth?: boolean;
  definition: WidgetDefinition;
  filters: DashboardFilters;
  currency: string;
  onDetail: (request: DetailRequest) => void;
}) {
  const [local, setLocal] = useState<Record<string, string>>({});
  const span = useMasonrySpan();
  const params = { ...widgetParams(filters, widget.filters), ...Object.fromEntries(Object.entries(local).filter(([, v]) => v !== '')) };
  const { state, envelope, error, reload } = useWidget<any>(widget.id, params);
  const [showTable, setShowTable] = useState(false);
  const title = widgetTitle(widget.id);
  const titleId = `dash-${widget.id}-title`;

  const ctx: RenderContext = {
    currency,
    reload,
    openDetail: (subtitle, extra, columns, widgetId) => onDetail({
      widgetId: widgetId ?? widget.id,
      title: subtitle ? `${title} — ${subtitle}` : title,
      params: { ...params, ...extra },
      columns,
    }),
  };

  const scopeBadge = widget.kind === 'period'
    ? t('dashboard.scope.period', { months: filters.months })
    : t('dashboard.scope.current');

  let body: ReactNode;
  if (state === 'loading' && !envelope) {
    body = <Skeleton height={definition.size === 's' ? 60 : 160} />;
  } else if (state === 'forbidden') {
    body = <StateBox>{error}</StateBox>;
  } else if (state === 'error') {
    body = (
      <StateBox tone="error">
        {error}{' '}
        <button type="button" className="dash-link-btn" onClick={reload}>{t('dashboard.actions.retry')}</button>
      </StateBox>
    );
  } else if (envelope) {
    const empty = definition.isEmpty?.(envelope.data) ?? false;
    const tableView = showTable && definition.table ? definition.table(envelope) : null;
    body = (
      <>
        {empty ? <StateBox>{t(`dashboard.widgets.${widgetKey(widget.id)}.empty`)}</StateBox>
          : tableView ? <DataTable columns={tableView.columns} rows={tableView.rows} currency={envelope.currency} caption={title} />
            : definition.render(envelope, ctx)}
        {envelope.limitations.length > 0 && (
          <ul className="dash-limitations">
            {envelope.limitations.map((l) => <li key={l.code}>{t(l.code, l.params)}</li>)}
          </ul>
        )}
      </>
    );
  }

  return (
    <section ref={span.ref} style={span.style} className={`dash-card dash-${fullWidth ? 'xl' : definition.size}`} aria-labelledby={titleId} data-widget={widget.id} aria-busy={state === 'loading'}>
      <div className="dash-card-head">
        <div style={{ minWidth: 0 }}>
          <h3 className="dash-card-title" id={titleId}>
            {title}
            <InfoTip label={title}>{t(`dashboard.widgets.${widgetKey(widget.id)}.help`)}</InfoTip>
          </h3>
          <div className="dash-card-meta">
            <span className="dash-badge">{scopeBadge}</span>
            {envelope && state !== 'error' && <span style={{ marginLeft: 8 }}>{updatedAt(envelope.generated_at)}</span>}
          </div>
        </div>
        <button type="button" className="dash-icon-btn" onClick={reload} aria-label={t('dashboard.actions.refreshWidget', { title })} title={t('dashboard.actions.refresh')}>
          ⟳
        </button>
      </div>
      {definition.controls && (
        <div className="dash-card-controls">
          {definition.controls(envelope?.data ?? null, local, (key, value) => setLocal((prev) => ({ ...prev, [key]: value })))}
        </div>
      )}
      {body}
      {envelope && state === 'ready' && (definition.detail || definition.table) && !(definition.isEmpty?.(envelope.data) ?? false) && (
        <div className="dash-card-foot">
          {definition.table ? (
            <button type="button" className="dash-link-btn" aria-pressed={showTable} onClick={() => setShowTable((v) => !v)}>
              {showTable ? t('dashboard.actions.showChart') : t('dashboard.actions.showTable')}
            </button>
          ) : <span />}
          {definition.detail && widget.drilldown && (
            <button type="button" className="dash-link-btn" onClick={() => ctx.openDetail('', definition.detail!.params ?? {}, definition.detail!.columns)}>
              {t('dashboard.actions.viewDetails')}
            </button>
          )}
        </div>
      )}
    </section>
  );
}

/** Grid row unit and gap of `.dash-grid` (dashboard.css). */
const ROW = 8;
const GAP = 16;

/**
 * Masonry span: the card occupies ceil((height + gap) / row) grid rows, re-measured whenever its
 * content (loading → data, table toggle, resize) changes height.
 */
function useMasonrySpan() {
  const ref = useRef<HTMLElement | null>(null);
  const [rows, setRows] = useState<number | null>(null);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el || typeof ResizeObserver === 'undefined') return undefined;
    const measure = () => setRows(Math.max(1, Math.ceil((el.getBoundingClientRect().height + GAP) / ROW)));
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    return () => observer.disconnect();
  }, []);
  return { ref, style: rows === null ? undefined : { gridRowEnd: `span ${rows}` } };
}
