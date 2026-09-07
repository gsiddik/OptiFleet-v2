import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../api/client';
import { ErrorState, LoadingState } from '../../components/States';

interface DashboardData {
  branches_total: number;
  workshops_total: number;
  warehouses_total: number;
  users_total: number;
  active_modules: string[];
}

export function TenantDashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/dashboard')
      .then((res) => setData(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, []);

  if (error) return <ErrorState message={error} />;
  if (!data) return <LoadingState />;

  const stats = [
    { label: 'Branches', value: data.branches_total },
    { label: 'Workshops', value: data.workshops_total },
    { label: 'Warehouses', value: data.warehouses_total },
    { label: 'Active Users', value: data.users_total },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>Dashboard</h1>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
        {stats.map((s) => (
          <div key={s.label} className="card">
            <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
            <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value}</div>
          </div>
        ))}
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>Active Modules</h2>
      <div className="card" style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        {data.active_modules.map((m) => (
          <span key={m} style={{ background: '#eff6ff', color: '#1d4ed8', padding: '4px 10px', borderRadius: 6, fontSize: 12 }}>
            {m}
          </span>
        ))}
        {data.active_modules.length === 0 && <span style={{ color: '#9ca3af' }}>No modules entitled.</span>}
      </div>
    </div>
  );
}
