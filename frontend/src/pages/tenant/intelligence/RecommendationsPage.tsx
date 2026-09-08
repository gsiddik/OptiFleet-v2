import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useAuth } from '../../../auth/AuthContext';

interface Recommendation {
  id: string;
  entity_type: string;
  entity_id: string;
  recommendation_type: string;
  priority: string;
  description: string;
  status: string;
  suggested_due_at: string | null;
}

// Phase 7 Section 38, 44: human review of prescriptive recommendations.
// Every action requires its own permission — buttons are hidden, and the
// server independently re-checks, when the caller lacks it (Section 48
// entitlement enforcement is never frontend-only).
export function RecommendationsPage() {
  const { hasPermission } = useAuth();
  const [recommendations, setRecommendations] = useState<Recommendation[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  function load() {
    apiClient
      .get('/app/intelligence/recommendations')
      .then((res) => setRecommendations(res.data.data.recommendations))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(() => {
    if (hasPermission('intelligence.recommendation.view')) load();
  }, [hasPermission]);

  if (!hasPermission('intelligence.recommendation.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view recommendations.</div>;
  }

  async function act(id: string, action: 'review' | 'accept' | 'reject' | 'convert') {
    setBusyId(id);
    try {
      await apiClient.post(`/app/intelligence/recommendations/${id}/${action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Intelligence Recommendations</h1>
      <p style={{ color: '#6b7280', fontSize: 13, marginTop: 0, marginBottom: 16 }}>
        Suggestions only — accepting converts to a Maintenance Request through the normal workflow; nothing here is
        approved or executed automatically.
      </p>
      {error && <ErrorState message={error} />}
      {!recommendations && !error && <LoadingState />}
      {recommendations && recommendations.length === 0 && <EmptyState label="No recommendations." />}
      {recommendations && recommendations.length > 0 && (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
              <th style={{ padding: 8 }}>Entity</th>
              <th style={{ padding: 8 }}>Type</th>
              <th style={{ padding: 8 }}>Priority</th>
              <th style={{ padding: 8 }}>Description</th>
              <th style={{ padding: 8 }}>Status</th>
              <th style={{ padding: 8 }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {recommendations.map((r) => (
              <tr key={r.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: 8 }}>{r.entity_type}:{r.entity_id}</td>
                <td style={{ padding: 8 }}>{r.recommendation_type}</td>
                <td style={{ padding: 8 }}>{r.priority}</td>
                <td style={{ padding: 8 }}>{r.description}</td>
                <td style={{ padding: 8 }}>{r.status}</td>
                <td style={{ padding: 8, display: 'flex', gap: 4 }}>
                  {r.status === 'NEW' && hasPermission('intelligence.recommendation.review') && (
                    <button disabled={busyId === r.id} onClick={() => act(r.id, 'review')}>Review</button>
                  )}
                  {['NEW', 'REVIEWED'].includes(r.status) && hasPermission('intelligence.recommendation.accept') && (
                    <button disabled={busyId === r.id} onClick={() => act(r.id, 'accept')}>Accept</button>
                  )}
                  {['NEW', 'REVIEWED'].includes(r.status) && hasPermission('intelligence.recommendation.reject') && (
                    <button disabled={busyId === r.id} onClick={() => act(r.id, 'reject')}>Reject</button>
                  )}
                  {r.status === 'ACCEPTED' && hasPermission('intelligence.recommendation.convert') && (
                    <button disabled={busyId === r.id} onClick={() => act(r.id, 'convert')}>Convert to MR</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
