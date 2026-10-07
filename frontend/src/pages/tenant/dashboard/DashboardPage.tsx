import { useEffect, useMemo, useState } from 'react';
import { apiClient } from '../../../api/client';
import { ErrorState } from '../../../components/States';
import { useTabParam } from '../../../hooks/useTabParam';
import { t } from '../../../i18n/i18n';
import { errorText } from './api';
import { DetailModal, Skeleton, StateBox, type DetailRequest } from './components';
import './dashboard.css';
import type { DashboardCatalog, DashboardFilters } from './types';
import { WidgetCard } from './WidgetCard';
import { WIDGET_DEFINITIONS } from './widgets';

/**
 * Tenant dashboard. The server decides which widgets and tabs exist for this user (permissions,
 * entitled modules, data scope); this page only lays them out. Every widget loads, fails and
 * retries on its own; current-state widgets ignore the period filter and say so.
 */
export function TenantDashboardPage() {
  const [catalog, setCatalog] = useState<DashboardCatalog | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/dashboard/catalog')
      .then((res) => setCatalog(res.data.data as DashboardCatalog))
      .catch((e) => setError(errorText(e).message));
  }, []);

  if (error) {
    return (
      <div>
        <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('dashboard.titles.dashboard')}</h1>
        <ErrorState message={error} />
      </div>
    );
  }
  if (!catalog) {
    return (
      <div>
        <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('dashboard.titles.dashboard')}</h1>
        <div className="dash-grid">
          {[0, 1, 2].map((i) => <div key={i} className="dash-card dash-s"><Skeleton height={80} /></div>)}
        </div>
      </div>
    );
  }
  return <Dashboard catalog={catalog} />;
}

function Dashboard({ catalog }: { catalog: DashboardCatalog }) {
  const tabs = useMemo(
    () => catalog.presets
      .map((p) => ({ id: p.id, label: p.id, labelKey: `dashboard.presets.${p.id}`, widgets: p.widgets.filter((id) => WIDGET_DEFINITIONS[id]) }))
      .filter((p) => p.widgets.length > 0),
    [catalog],
  );
  const [active, setActive] = useTabParam(tabs, (tabs[0]?.id ?? 'summary') as string);
  const [filters, setFilters] = useState<DashboardFilters>({ branch_id: '', workshop_id: '', warehouse_id: '', months: catalog.filters.default_months });
  const [detail, setDetail] = useState<DetailRequest[]>([]);

  if (tabs.length === 0) {
    return (
      <div>
        <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('dashboard.titles.dashboard')}</h1>
        <StateBox>{t('dashboard.states.noWidgets')}</StateBox>
      </div>
    );
  }

  const preset = tabs.find((p) => p.id === active) ?? tabs[0];
  const widgets = preset.widgets.map((id) => catalog.widgets.find((w) => w.id === id)).filter((w) => w !== undefined);
  const uses = (key: 'branch' | 'workshop' | 'warehouse' | 'period') => widgets.some((w) => w.filters.includes(key));
  const options = catalog.filters.options;
  const set = (patch: Partial<DashboardFilters>) => setFilters((f) => ({ ...f, ...patch }));
  const hasPeriodAndCurrent = uses('period') && widgets.some((w) => w.kind === 'current');

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('dashboard.titles.dashboard')}</h1>

      <div className="dash-tabs" role="tablist" aria-label={t('dashboard.titles.presets')}>
        {tabs.map((p) => (
          <button key={p.id} type="button" role="tab" className="dash-tab" aria-selected={p.id === preset.id} onClick={() => setActive(p.id)}>
            {t(`dashboard.presets.${p.id}`)}
          </button>
        ))}
      </div>

      <div className="dash-toolbar" role="group" aria-label={t('dashboard.filters.label')}>
        {uses('branch') && options.branches.length > 1 && (
          <label>
            {t('dashboard.filters.branch')}
            <select value={filters.branch_id} onChange={(e) => set({ branch_id: e.target.value })}>
              <option value="">{t('dashboard.filters.allInScope')}</option>
              {options.branches.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
          </label>
        )}
        {uses('workshop') && options.workshops.length > 1 && (
          <label>
            {t('dashboard.filters.workshop')}
            <select value={filters.workshop_id} onChange={(e) => set({ workshop_id: e.target.value })}>
              <option value="">{t('dashboard.filters.allInScope')}</option>
              {options.workshops.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
          </label>
        )}
        {uses('warehouse') && options.warehouses.length > 1 && (
          <label>
            {t('dashboard.filters.warehouse')}
            <select value={filters.warehouse_id} onChange={(e) => set({ warehouse_id: e.target.value })}>
              <option value="">{t('dashboard.filters.allInScope')}</option>
              {options.warehouses.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select>
          </label>
        )}
        {uses('period') && (
          <label>
            {t('dashboard.filters.period')}
            <select value={filters.months} onChange={(e) => set({ months: Number(e.target.value) })}>
              {catalog.filters.month_options.map((m) => <option key={m} value={m}>{t('dashboard.filters.monthsOption', { months: m })}</option>)}
            </select>
          </label>
        )}
        <div className="dash-toolbar-note">
          {hasPeriodAndCurrent ? t('dashboard.filters.periodNote') : null}{' '}
          {t('dashboard.filters.timezoneNote', { timezone: catalog.timezone })}
        </div>
      </div>

      <div className="dash-grid">
        {widgets.map((w) => (
          <WidgetCard key={`${preset.id}-${w.id}`} widget={w} definition={WIDGET_DEFINITIONS[w.id]} filters={filters} currency={catalog.currency} onDetail={(request) => setDetail([request])} />
        ))}
      </div>

      <DetailModal stack={detail} currency={catalog.currency} onPush={(request) => setDetail((s) => [...s, request])}
        onBack={() => setDetail((s) => s.slice(0, -1))} onClose={() => setDetail([])} />
    </div>
  );
}
