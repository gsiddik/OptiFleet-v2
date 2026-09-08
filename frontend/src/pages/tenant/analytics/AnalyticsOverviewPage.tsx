import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { KpiCard, type KpiResult } from '../../../components/analytics/KpiCard';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { useAuth } from '../../../auth/AuthContext';

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
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view analytics.</div>;
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Analytics Overview</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginTop: 0, marginBottom: 16 }}>
        Trailing 30 days across the fleet. Drill into each section from the Analytics menu for detail, filters, and export.
      </p>

      {response && <FreshnessBanner freshness={response.freshness} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && response && response.kpis.length === 0 && (
        <EmptyState label="No analytics data yet — the daily ETL has not produced a snapshot for this tenant." />
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
