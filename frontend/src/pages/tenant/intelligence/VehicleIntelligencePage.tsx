import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { RiskBadge } from '../../../components/intelligence/RiskBadge';
import { useAuth } from '../../../auth/AuthContext';

interface VehicleRow {
  vehicle_id: string;
  health_score: number | null;
  health_status: string | null;
  failure_risk_level: string | null;
  failure_risk_score: number | null;
  confidence: string | null;
}

interface VehiclesResponse {
  vehicles: VehicleRow[];
  business_date: string | null;
  freshness: Freshness;
}

// Phase 7 Section 44/52: fleet-wide health + risk table (Section 45:
// always provide table access, not just charts).
export function VehicleIntelligencePage() {
  const { hasPermission } = useAuth();
  const [response, setResponse] = useState<VehiclesResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!hasPermission('intelligence.vehicle.view')) return;
    apiClient
      .get('/app/intelligence/vehicles')
      .then((res) => setResponse(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, [hasPermission]);

  if (!hasPermission('intelligence.vehicle.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view vehicle intelligence.</div>;
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Vehicle Health &amp; Risk</h1>
      {response && <FreshnessBanner freshness={response.freshness} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && response && response.vehicles.length === 0 && (
        <EmptyState label="No vehicle health/risk data yet — the intelligence pipeline has not run for this tenant." />
      )}
      {!error && !loading && response && response.vehicles.length > 0 && (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
              <th style={{ padding: 8 }}>Vehicle</th>
              <th style={{ padding: 8 }}>Health Score</th>
              <th style={{ padding: 8 }}>Health Status</th>
              <th style={{ padding: 8 }}>Failure Risk</th>
              <th style={{ padding: 8 }}>Risk Score</th>
              <th style={{ padding: 8 }}>Confidence</th>
            </tr>
          </thead>
          <tbody>
            {response.vehicles.map((v) => (
              <tr key={v.vehicle_id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: 8 }}>
                  <Link to={`/app/intelligence/vehicles/${v.vehicle_id}`}>{v.vehicle_id}</Link>
                </td>
                <td style={{ padding: 8 }}>{v.health_score ?? '—'}</td>
                <td style={{ padding: 8 }}><RiskBadge level={v.health_status} /></td>
                <td style={{ padding: 8 }}><RiskBadge level={v.failure_risk_level} /></td>
                <td style={{ padding: 8 }}>{v.failure_risk_score !== null ? v.failure_risk_score.toFixed(2) : '—'}</td>
                <td style={{ padding: 8 }}>{v.confidence ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
