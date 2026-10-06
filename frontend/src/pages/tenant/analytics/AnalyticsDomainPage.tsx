import { useEffect, useMemo, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { Modal } from '../../../components/Modal';
import { KpiCard } from '../../../components/analytics/KpiCard';
import { FreshnessBanner } from '../../../components/analytics/FreshnessBanner';
import { TrendChart } from '../../../components/analytics/TrendChart';
import { formatCell, readPath, type AnalyticsDomainConfig, type AnalyticsResponse } from '../../../components/analytics/analyticsTypes';
import { useAuth } from '../../../auth/AuthContext';
import { labelText, t, withLabels } from '../../../i18n/i18n';

const RANGE_PRESETS = withLabels([
  { label: '7 days', labelKey: 'analytics.fields.n7Days', days: 7 },
  { label: '30 days', labelKey: 'analytics.fields.n30Days', days: 30 },
  { label: '90 days', labelKey: 'analytics.fields.n90Days', days: 90 },
  { label: '365 days', labelKey: 'analytics.fields.n365Days', days: 365 },
]);

function isoDaysAgo(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

/**
 * Phase 6 Section 41-46: one page renders every /analytics/{domain}
 * endpoint — KPI cards, a trend chart on the first highlight field, a
 * full numeric table (Section 45: always provide table access, not just
 * charts), a date-range + dimension filter, and CSV export using the
 * exact same filters (Section 47).
 */
export function AnalyticsDomainPage({ config }: { config: AnalyticsDomainConfig }) {
  const { hasPermission } = useAuth();
  const [rangeDays, setRangeDays] = useState(30);
  const [dimensionValue, setDimensionValue] = useState('');
  const [response, setResponse] = useState<AnalyticsResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [detailRow, setDetailRow] = useState<Record<string, unknown> | null>(null);
  const [exporting, setExporting] = useState(false);

  const params = useMemo(
    () => ({
      from: isoDaysAgo(rangeDays),
      to: isoDaysAgo(0),
      ...(dimensionValue ? { dimension_value: dimensionValue } : {}),
    }),
    [rangeDays, dimensionValue],
  );

  useEffect(() => {
    if (!hasPermission(config.permission)) return;
    setLoading(true);
    setError(null);
    apiClient
      .get(config.endpoint, { params })
      .then((res) => setResponse(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, [config.endpoint, config.permission, params, hasPermission]);

  if (!hasPermission(config.permission)) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>{t('analytics.help.youDoNotPermissionPermissionView', { permission: config.permission })}</div>;
  }

  async function exportCsv() {
    setExporting(true);
    try {
      const res = await apiClient.get(`/app/analytics/export/${config.exportSlug}`, { params, responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'text/csv' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${config.exportSlug}-analytics.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setExporting(false);
    }
  }

  const trendField = config.highlightFields[0];
  const trendPoints =
    response && trendField
      ? response.metrics.map((row) => ({
          date: String(row.snapshot_date ?? ''),
          value: typeof readPath(row, trendField.key) === 'number' ? (readPath(row, trendField.key) as number) : null,
        }))
      : [];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{config.title}</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginTop: 0, marginBottom: 16 }}>{config.description}</p>

      {response && <FreshnessBanner freshness={response.freshness} />}

      <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 16, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', gap: 6 }}>
          {RANGE_PRESETS.map((preset) => (
            <button
              key={preset.days}
              className={rangeDays === preset.days ? 'btn-primary' : 'btn-secondary'}
              onClick={() => setRangeDays(preset.days)}
            >
              {labelText(preset)}
            </button>
          ))}
        </div>
        <input
          placeholder={t('analytics.placeholders.dimensionLabelIdOptional', { dimensionLabel: config.dimensionLabel })}
          value={dimensionValue}
          onChange={(e) => setDimensionValue(e.target.value)}
          style={{ padding: '6px 10px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, minWidth: 220 }}
        />
        {hasPermission('analytics.export') && (
          <button className="btn-secondary" onClick={exportCsv} disabled={exporting}>
            {exporting ? t('analytics.actions.exporting') : t('analytics.actions.exportCsv')}
          </button>
        )}
      </div>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}

      {!error && !loading && response && (
        <>
          {response.kpis.length > 0 && (
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
              {response.kpis.map((kpi) => (
                <KpiCard key={kpi.code} kpi={kpi} />
              ))}
            </div>
          )}

          {trendField && (
            <div style={{ marginBottom: 16 }}>
              <TrendChart points={trendPoints} label={labelText(trendField)} />
            </div>
          )}

          {response.metrics.length === 0 && <EmptyState label={t('analytics.empty.noAnalyticsDataPeriod')} />}

          {response.metrics.length > 0 && (
            <div style={{ overflowX: 'auto', border: '1px solid #e5e7eb', borderRadius: 8 }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                <thead>
                  <tr style={{ background: '#f9fafb', textAlign: 'left' }}>
                    <th style={{ padding: '8px 12px' }}>{t('common.fields.date')}</th>
                    <th style={{ padding: '8px 12px' }}>{config.dimensionLabel}</th>
                    {config.highlightFields.map((f) => (
                      <th key={f.key} style={{ padding: '8px 12px' }}>
                        {labelText(f)}
                      </th>
                    ))}
                    <th style={{ padding: '8px 12px' }} />
                  </tr>
                </thead>
                <tbody>
                  {response.metrics.map((row, i) => (
                    <tr key={i} style={{ borderTop: '1px solid #f3f4f6' }}>
                      <td style={{ padding: '8px 12px' }}>{String(row.snapshot_date ?? '')}</td>
                      <td style={{ padding: '8px 12px' }}>{String(row[config.dimensionField] ?? t('common.fields.all'))}</td>
                      {config.highlightFields.map((f) => (
                        <td key={f.key} style={{ padding: '8px 12px' }}>
                          {formatCell(readPath(row, f.key))}
                        </td>
                      ))}
                      <td style={{ padding: '8px 12px' }}>
                        <button className="btn-secondary" style={{ fontSize: 11, padding: '2px 8px' }} onClick={() => setDetailRow(row)}>
                          {t('analytics.actions.details')}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}

      <Modal open={detailRow !== null} title={t('analytics.modals.rawMetrics')} onClose={() => setDetailRow(null)}>
        <pre style={{ fontSize: 12, whiteSpace: 'pre-wrap', maxHeight: '60vh', overflowY: 'auto' }}>
          {JSON.stringify(detailRow, null, 2)}
        </pre>
      </Modal>
    </div>
  );
}
