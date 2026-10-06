import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../api/client';
import { ErrorState, LoadingState } from '../../components/States';
import { formatDateTime } from '../../utils/date';
import { t } from '../../i18n/i18n';

interface DashboardData {
  tenants_total: number;
  tenants_active: number;
  platform_users_total: number;
  tenant_users_total: number;
  modules_total: number;
  recent_audit_logs: { id: string; resource_type: string; action: string; created_at: string }[];
  subscriptions_pending: number;
  subscriptions_active: number;
  subscriptions_suspended: number;
  contracts_expiring: number;
  invoices_outstanding: number;
  invoices_overdue: number;
  payments_pending_verification: number;
}

export function PlatformDashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/platform/dashboard')
      .then((res) => setData(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, []);

  if (error) return <ErrorState message={error} />;
  if (!data) return <LoadingState />;

  const stats = [
    { label: t('platform.dashboard.sections.totalTenants'), value: data.tenants_total },
    { label: t('platform.dashboard.sections.activeTenants'), value: data.tenants_active },
    { label: t('platform.dashboard.sections.platformUsers'), value: data.platform_users_total },
    { label: t('platform.dashboard.sections.tenantUsers'), value: data.tenant_users_total },
    { label: t('platform.dashboard.sections.modules'), value: data.modules_total },
  ];

  const commercialStats = [
    { label: t('platform.dashboard.sections.pendingSubscriptions'), value: data.subscriptions_pending },
    { label: t('platform.dashboard.sections.activeSubscriptions'), value: data.subscriptions_active },
    { label: t('platform.dashboard.sections.suspendedSubscriptions'), value: data.subscriptions_suspended, alert: data.subscriptions_suspended > 0 },
    { label: t('platform.dashboard.sections.contractsExpiring'), value: data.contracts_expiring, alert: data.contracts_expiring > 0 },
    { label: t('platform.dashboard.sections.invoicesOutstanding'), value: data.invoices_outstanding },
    { label: t('platform.dashboard.sections.invoicesOverdue'), value: data.invoices_overdue, alert: data.invoices_overdue > 0 },
    { label: t('platform.dashboard.sections.paymentsPendingVerification'), value: data.payments_pending_verification, alert: data.payments_pending_verification > 0 },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('platform.dashboard.titles.platformDashboard')}</h1>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 16, marginBottom: 28 }}>
        {stats.map((s) => (
          <div key={s.label} className="card">
            <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
            <div style={{ fontSize: 28, fontWeight: 700 }}>{s.value}</div>
          </div>
        ))}
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('platform.dashboard.sections.commercialOverview')}</h2>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16, marginBottom: 28 }}>
        {commercialStats.map((s) => (
          <div key={s.label} className="card">
            <div style={{ fontSize: 13, color: '#6b7280' }}>{s.label}</div>
            <div style={{ fontSize: 28, fontWeight: 700, color: s.alert ? '#b91c1c' : undefined }}>{s.value}</div>
          </div>
        ))}
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('platform.dashboard.sections.recentActivity')}</h2>
      <div className="card">
        {data.recent_audit_logs.length === 0 && <div style={{ color: '#9ca3af' }}>{t('platform.dashboard.empty.noRecentActivity')}</div>}
        {data.recent_audit_logs.map((log) => (
          <div key={log.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            <strong>{log.resource_type}</strong> {log.action} — {formatDateTime(log.created_at)}
          </div>
        ))}
      </div>
    </div>
  );
}
