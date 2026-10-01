import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { FreshnessBanner, type Freshness } from '../../../components/analytics/FreshnessBanner';
import { RiskBadge } from '../../../components/intelligence/RiskBadge';
import { useAuth } from '../../../auth/AuthContext';
import { formatMoney } from '../../../utils/money';

interface TireRow {
  tire_id: string;
  vehicle_id: string;
  risk_level: string;
  rul?: { remaining_km?: { low: number; high: number } };
}
interface ProductPerformance {
  product_id: string;
  tire_count: number;
  avg_cost_per_km: number | null;
  avg_damage_count_90d: number;
}

// Phase 7 Section 34/44: tire RUL, product performance, abnormal wear.
export function TireIntelligencePage() {
  const { hasPermission } = useAuth();
  const [tires, setTires] = useState<TireRow[] | null>(null);
  const [productPerformance, setProductPerformance] = useState<ProductPerformance[]>([]);
  const [freshness, setFreshness] = useState<Freshness | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!hasPermission('intelligence.tire.view')) return;
    apiClient
      .get('/app/intelligence/tires')
      .then((res) => {
        setTires(res.data.data.tires);
        setProductPerformance(res.data.data.product_performance ?? []);
        setFreshness(res.data.data.freshness);
      })
      .catch((err) => setError(extractApiError(err).message));
  }, [hasPermission]);

  if (!hasPermission('intelligence.tire.view')) {
    return <div style={{ padding: 32, color: '#b91c1c' }}>You do not have permission to view tire intelligence.</div>;
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Tire Intelligence</h1>
      {freshness && <FreshnessBanner freshness={freshness} />}
      {error && <ErrorState message={error} />}
      {!tires && !error && <LoadingState />}

      {productPerformance.length > 0 && (
        <div style={{ marginBottom: 20 }}>
          <h3>Product Performance</h3>
          <table style={{ width: '100%', fontSize: 13 }}>
            <thead><tr><th style={{ textAlign: 'left', padding: 4 }}>Product</th><th style={{ textAlign: 'left', padding: 4 }}>Tires</th><th style={{ textAlign: 'left', padding: 4 }}>Avg Cost/km</th><th style={{ textAlign: 'left', padding: 4 }}>Avg Damage (90d)</th></tr></thead>
            <tbody>
              {productPerformance.map((p) => (
                <tr key={p.product_id}>
                  <td style={{ padding: 4 }}>{p.product_id}</td>
                  <td style={{ padding: 4 }}>{p.tire_count}</td>
                  <td style={{ padding: 4 }}>{formatMoney(p.avg_cost_per_km)}</td>
                  <td style={{ padding: 4 }}>{p.avg_damage_count_90d}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {tires && tires.length === 0 && <EmptyState label="No tire RUL data yet." />}
      {tires && tires.length > 0 && (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '2px solid #e5e7eb' }}>
              <th style={{ padding: 8 }}>Tire</th>
              <th style={{ padding: 8 }}>Vehicle</th>
              <th style={{ padding: 8 }}>Replacement Urgency</th>
              <th style={{ padding: 8 }}>Remaining km</th>
            </tr>
          </thead>
          <tbody>
            {tires.map((t) => (
              <tr key={t.tire_id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: 8 }}>{t.tire_id}</td>
                <td style={{ padding: 8 }}>{t.vehicle_id}</td>
                <td style={{ padding: 8 }}><RiskBadge level={t.risk_level} /></td>
                <td style={{ padding: 8 }}>{t.rul?.remaining_km ? `${t.rul.remaining_km.low}–${t.rul.remaining_km.high}` : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}
