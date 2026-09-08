import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { RiskBadge } from '../../../components/intelligence/RiskBadge';
import { useAuth } from '../../../auth/AuthContext';

interface Prediction {
  score: number | null;
  probability: number | null;
  risk_level: string | null;
  confidence: string | null;
  explanation: string[];
  source: string;
  model_id: string | null;
  model_version: number | null;
  business_date: string;
  rul?: { remaining_km?: { point: number; low: number; high: number }; remaining_days?: { point: number; low: number; high: number }; basis: string };
}
interface HistoryPoint {
  business_date: string;
  score: number | null;
  risk_level: string | null;
}
interface DetailResponse {
  vehicle_id: string;
  health: Prediction | null;
  health_history: HistoryPoint[];
  failure_risk: Prediction | null;
  failure_risk_history: HistoryPoint[];
  rul: Prediction | null;
  repeat_failures: Prediction[];
  anomalies: Prediction[];
  component_health: Prediction[];
  recommendations: { id: string; recommendation_type: string; priority: string; status: string; description: string }[];
  freshness: Freshness;
}

// Phase 7 Section 54/56: one vehicle's full intelligence picture — health,
// risk, RUL, component health, key factors, recommendations, history.
// Section 56: never show a bare score without risk + confidence + factors.
export function VehicleIntelligenceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [response, setResponse] = useState<DetailResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!hasPermission('intelligence.vehicle.view')) return;
    apiClient
      .get(`/app/intelligence/vehicles/${id}`)
      .then((res) => setResponse(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, [id, hasPermission]);

  if (!hasPermission('intelligence.vehicle.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view vehicle intelligence.</div>;
  }
  if (error) return <ErrorState message={error} />;
  if (loading) return <LoadingState />;
  if (!response) return <EmptyState label="No intelligence data for this vehicle." />;

  const section: React.CSSProperties = { border: '1px solid #e5e7eb', borderRadius: 8, padding: 16, marginBottom: 16 };

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Vehicle {response.vehicle_id}</h1>
      <FreshnessBanner freshness={response.freshness} />

      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
        <div style={{ ...section, flex: '1 1 300px' }}>
          <h3 style={{ marginTop: 0 }}>Health Score</h3>
          {response.health ? (
            <>
              <div style={{ fontSize: 28, fontWeight: 700 }}>
                {response.health.score} <RiskBadge level={response.health.risk_level} />
              </div>
              <div style={{ fontSize: 12, color: '#6b7280' }}>Confidence: {response.health.confidence}</div>
              <ul style={{ fontSize: 13, paddingLeft: 18 }}>
                {response.health.explanation.map((line, i) => <li key={i}>{line}</li>)}
              </ul>
            </>
          ) : <EmptyState label="No health score yet." />}
        </div>

        <div style={{ ...section, flex: '1 1 300px' }}>
          <h3 style={{ marginTop: 0 }}>Failure Risk (30d)</h3>
          {response.failure_risk ? (
            <>
              <div style={{ fontSize: 28, fontWeight: 700 }}>
                {response.failure_risk.probability !== null
                  ? `${(response.failure_risk.probability * 100).toFixed(0)}%`
                  : `${((response.failure_risk.score ?? 0) * 100).toFixed(0)}%`}{' '}
                <RiskBadge level={response.failure_risk.risk_level} />
              </div>
              <div style={{ fontSize: 12, color: '#6b7280' }}>
                Confidence: {response.failure_risk.confidence} · Source: {response.failure_risk.source}
                {response.failure_risk.model_id && ` · Model v${response.failure_risk.model_version}`}
              </div>
              <div style={{ fontSize: 13, fontWeight: 600, marginTop: 8 }}>Main factors:</div>
              <ul style={{ fontSize: 13, paddingLeft: 18 }}>
                {response.failure_risk.explanation.map((line, i) => <li key={i}>{line}</li>)}
              </ul>
            </>
          ) : <EmptyState label="No failure risk prediction yet." />}
        </div>

        {response.rul && (
          <div style={{ ...section, flex: '1 1 300px' }}>
            <h3 style={{ marginTop: 0 }}>Remaining Useful Life</h3>
            {response.rul.rul?.remaining_km && (
              <div>Estimated {response.rul.rul.remaining_km.low}–{response.rul.rul.remaining_km.high} km remaining</div>
            )}
            {response.rul.rul?.remaining_days && (
              <div>Estimated {response.rul.rul.remaining_days.low}–{response.rul.rul.remaining_days.high} days remaining</div>
            )}
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>Basis: {response.rul.rul?.basis} (not a machine-learned estimate)</div>
          </div>
        )}
      </div>

      <div style={section}>
        <h3 style={{ marginTop: 0 }}>Component Health</h3>
        {response.component_health.length === 0 ? <EmptyState label="No component health data." /> : (
          <table style={{ width: '100%', fontSize: 13 }}>
            <tbody>
              {response.component_health.map((c, i) => (
                <tr key={i}>
                  <td style={{ padding: 4 }}>{c.score}</td>
                  <td style={{ padding: 4 }}><RiskBadge level={c.risk_level} /></td>
                  <td style={{ padding: 4, color: '#6b7280' }}>{c.explanation.join('; ')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={section}>
        <h3 style={{ marginTop: 0 }}>Recommendations</h3>
        {response.recommendations.length === 0 ? <EmptyState label="No open recommendations." /> : (
          <table style={{ width: '100%', fontSize: 13 }}>
            <thead><tr><th style={{ textAlign: 'left', padding: 4 }}>Type</th><th style={{ textAlign: 'left', padding: 4 }}>Priority</th><th style={{ textAlign: 'left', padding: 4 }}>Status</th></tr></thead>
            <tbody>
              {response.recommendations.map((r) => (
                <tr key={r.id}><td style={{ padding: 4 }}>{r.recommendation_type}</td><td style={{ padding: 4 }}>{r.priority}</td><td style={{ padding: 4 }}>{r.status}</td></tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div style={section}>
        <h3 style={{ marginTop: 0 }}>Risk / Health History</h3>
        <table style={{ width: '100%', fontSize: 12 }}>
          <thead><tr><th style={{ textAlign: 'left', padding: 4 }}>Date</th><th style={{ textAlign: 'left', padding: 4 }}>Health Score</th><th style={{ textAlign: 'left', padding: 4 }}>Failure Risk</th></tr></thead>
          <tbody>
            {response.health_history.map((h, i) => (
              <tr key={i}>
                <td style={{ padding: 4 }}>{h.business_date}</td>
                <td style={{ padding: 4 }}>{h.score} <RiskBadge level={h.risk_level} /></td>
                <td style={{ padding: 4 }}>{response.failure_risk_history[i]?.risk_level && <RiskBadge level={response.failure_risk_history[i].risk_level} />}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
