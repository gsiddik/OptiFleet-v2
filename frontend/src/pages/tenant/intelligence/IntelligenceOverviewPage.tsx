import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { useAuth } from '../../../auth/AuthContext';

interface OverviewResponse {
  vehicles_healthy: number;
  vehicles_at_risk: number;
  vehicles_critical: number;
  high_risk_components: number;
  predicted_failures_7d: number;
  predicted_failures_30d: number;
  low_rul_count: number;
  repeat_failures: number;
  open_recommendations: number;
  accepted_recommendations: number;
  freshness: Freshness;
}

const CARD = { border: '1px solid #e5e7eb', borderRadius: 8, padding: 16, minWidth: 160, flex: '1 1 160px' };

// Phase 7 Section 44/52-53: Maintenance Intelligence landing page.
export function IntelligenceOverviewPage() {
  const { hasPermission } = useAuth();
  const [response, setResponse] = useState<OverviewResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!hasPermission('intelligence.overview.view')) return;
    apiClient
      .get('/app/intelligence/overview')
      .then((res) => setResponse(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, [hasPermission]);

  if (!hasPermission('intelligence.overview.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view maintenance intelligence.</div>;
  }

  const cards = response
    ? [
        { label: 'Vehicles Healthy', value: response.vehicles_healthy, color: '#16a34a' },
        { label: 'Vehicles At Risk', value: response.vehicles_at_risk, color: '#b45309' },
        { label: 'Critical Vehicles', value: response.vehicles_critical, color: '#b91c1c' },
        { label: 'High-Risk Components', value: response.high_risk_components, color: '#b45309' },
        { label: 'Predicted Failures (7d)', value: response.predicted_failures_7d, color: '#b91c1c' },
        { label: 'Predicted Failures (30d)', value: response.predicted_failures_30d, color: '#b45309' },
        { label: 'Low RUL Items', value: response.low_rul_count, color: '#b45309' },
        { label: 'Repeat Failures', value: response.repeat_failures, color: '#b45309' },
        { label: 'Open Recommendations', value: response.open_recommendations, color: '#2563eb' },
        { label: 'Accepted Recommendations', value: response.accepted_recommendations, color: '#16a34a' },
      ]
    : [];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Maintenance Intelligence</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginTop: 0, marginBottom: 16 }}>
        Descriptive, diagnostic, predictive and prescriptive insight — deterministic and explainable, ML only where an
        active model has passed its own acceptance gate.
      </p>

      {response && <FreshnessBanner freshness={response.freshness} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && !response && <EmptyState label="No intelligence data yet." />}
      {!error && !loading && response && (
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          {cards.map((c) => (
            <div key={c.label} style={CARD}>
              <div style={{ fontSize: 12, color: '#6b7280' }}>{c.label}</div>
              <div style={{ fontSize: 28, fontWeight: 700, color: c.color }}>{c.value}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
