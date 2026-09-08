import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { RiskBadge } from '../../../components/intelligence/RiskBadge';
import { useAuth } from '../../../auth/AuthContext';

interface ComponentRow {
  vehicle_id: string;
  component_group_id: string;
  score: number;
  risk_level: string;
  explanation: string[];
}

// Phase 7 Section 44/55: component health/failure list.
export function ComponentIntelligencePage() {
  const { hasPermission } = useAuth();
  const [components, setComponents] = useState<ComponentRow[] | null>(null);
  const [freshness, setFreshness] = useState<Freshness | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!hasPermission('intelligence.component.view')) return;
    apiClient
      .get('/app/intelligence/components')
      .then((res) => {
        setComponents(res.data.data.components);
        setFreshness(res.data.data.freshness);
      })
      .catch((err) => setError(extractApiError(err).message));
  }, [hasPermission]);

  if (!hasPermission('intelligence.component.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view component intelligence.</div>;
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Component Reliability</h1>
      {freshness && <FreshnessBanner freshness={freshness} />}
      {error && <ErrorState message={error} />}
      {!components && !error && <LoadingState />}
      {components && components.length === 0 && <EmptyState label="No component health data yet." />}
      {components && components.length > 0 && (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
              <th style={{ padding: 8 }}>Vehicle</th>
              <th style={{ padding: 8 }}>Component Group</th>
              <th style={{ padding: 8 }}>Score</th>
              <th style={{ padding: 8 }}>Status</th>
              <th style={{ padding: 8 }}>Factors</th>
            </tr>
          </thead>
          <tbody>
            {components.map((c, i) => (
              <tr key={i} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: 8 }}>{c.vehicle_id}</td>
                <td style={{ padding: 8 }}>{c.component_group_id}</td>
                <td style={{ padding: 8 }}>{c.score}</td>
                <td style={{ padding: 8 }}><RiskBadge level={c.risk_level} /></td>
                <td style={{ padding: 8, color: '#6b7280' }}>{c.explanation.join('; ')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
