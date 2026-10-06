import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { KpiCard, type KpiResult } from '../../../components/analytics/KpiCard';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { useAuth } from '../../../auth/AuthContext';
import { t } from '../../../i18n/i18n';

interface OverviewResponse {
  kpis: KpiResult[];
  freshness: Freshness;
  period: { from: string; to: string };
}

// Phase 6 Section 41/44: the Analytics landing page — one headline KPI
// per major domain, plus freshness, over the trailing 30 days.
export function AnalyticsOverviewPage() {
  const { hasPermission } = useAuth();
  const [response, setResponse] = useState<OverviewResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!hasPermission('analytics.overview.view')) return;
    apiClient
      .get('/app/analytics/overview')
      .then((res) => setResponse(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, [hasPermission]);

  if (!hasPermission('analytics.overview.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>{t('analytics.help.youDoNotPermissionViewAnalytics')}</div>;
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{t('analytics.titles.analyticsOverview')}</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginTop: 0, marginBottom: 16 }}>
        {t('analytics.help.trailing30DaysAcrossFleetDrill')}
      </p>

      {response && <FreshnessBanner freshness={response.freshness} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && response && response.kpis.length === 0 && (
        <EmptyState label={t('analytics.empty.noAnalyticsDataYetDailyEtl')} />
      )}
      {!error && !loading && response && response.kpis.length > 0 && (
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          {response.kpis.map((kpi) => (
            <KpiCard key={kpi.code} kpi={kpi} />
          ))}
        </div>
      )}
    </div>
  );
}
