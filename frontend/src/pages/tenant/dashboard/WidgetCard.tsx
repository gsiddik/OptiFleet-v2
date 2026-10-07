import { useState, type ReactNode } from 'react';
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
  openDetail: (title: string, params: Record<string, unknown>, columns: Column<Record<string, unknown>>[]) => void;
  currency: string;
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
}

/** One widget: its own request, skeleton, empty / error / no-access states, retry and drill-down. */
export function WidgetCard({ widget, definition, filters, currency, onDetail }: {
  widget: CatalogWidget;
  definition: WidgetDefinition;
  filters: DashboardFilters;
  currency: string;
  onDetail: (request: DetailRequest) => void;
}) {
  const params = widgetParams(filters, widget.filters);
  const { state, envelope, error, reload } = useWidget<any>(widget.id, params);
  const [showTable, setShowTable] = useState(false);
  const title = widgetTitle(widget.id);
  const titleId = `dash-${widget.id}-title`;

  const ctx: RenderContext = {
    currency,
    openDetail: (subtitle, extra, columns) => onDetail({
      widgetId: widget.id,
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
    <section className={`dash-card dash-${definition.size}`} aria-labelledby={titleId} data-widget={widget.id} aria-busy={state === 'loading'}>
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
