import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import type { SubscriptionItem } from '../../../types';

interface UsageLimit {
  resource_type: string;
  max_count: number | null;
  current_count: number;
}

export function AccountSubscriptionPage() {
  const [subscription, setSubscription] = useState<SubscriptionItem | null | undefined>(undefined);
  const [activeModules, setActiveModules] = useState<string[] | null>(null);
  const [usage, setUsage] = useState<UsageLimit[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([
      apiClient.get('/app/account/subscription'),
      apiClient.get('/app/account/active-modules'),
      apiClient.get('/app/account/usage-limits'),
    ])
      .then(([subRes, modRes, usageRes]) => {
        setSubscription(subRes.data.data);
        setActiveModules(modRes.data.data);
        setUsage(usageRes.data.data);
      })
      .catch((err) => setError(extractApiError(err).message));
  }, []);

  if (error) return <ErrorState message={error} />;
  if (subscription === undefined || !activeModules || !usage) return <LoadingState />;

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>Subscription</h1>

      {!subscription && (
        <div className="card" style={{ color: '#9ca3af' }}>
          No active subscription found for this organization.
        </div>
      )}

      {subscription && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginBottom: 24 }}>
            <div className="card">
              <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 6 }}>Status</div>
              <StatusBadge status={subscription.status} />
            </div>
            <SummaryCard label="Start Date" value={subscription.start_date} />
            <SummaryCard label="End Date" value={subscription.end_date} />
            <SummaryCard label="Next Billing Date" value={subscription.next_billing_date} />
          </div>

          {subscription.status === 'SUSPENDED' && (
            <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 14, borderRadius: 8, marginBottom: 20, fontSize: 14 }}>
              Your subscription is suspended. Operational features are restricted until an outstanding payment is verified. Please submit
              or check your payment status under Payments.
            </div>
          )}
          {['GRACE_PERIOD', 'PAST_DUE'].includes(subscription.status) && (
            <div style={{ background: '#fffbeb', color: '#a16207', padding: 14, borderRadius: 8, marginBottom: 20, fontSize: 14 }}>
              Your account has an outstanding balance. Please settle it before {subscription.grace_period_end ?? 'the grace period ends'}{' '}
              to avoid suspension.
            </div>
          )}
        </>
      )}

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>Active Modules</h2>
      <div className="card" style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 24 }}>
        {activeModules.map((m) => (
          <span key={m} style={{ background: '#eff6ff', color: '#1d4ed8', padding: '4px 10px', borderRadius: 6, fontSize: 12 }}>
            {m}
          </span>
        ))}
        {activeModules.length === 0 && <span style={{ color: '#9ca3af' }}>No modules entitled.</span>}
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>Usage &amp; Limits</h2>
      <div className="card">
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px 8px' }}>Resource</th>
              <th style={{ padding: '6px 8px' }}>Current</th>
              <th style={{ padding: '6px 8px' }}>Limit</th>
            </tr>
          </thead>
          <tbody>
            {usage.map((u) => (
              <tr key={u.resource_type} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '6px 8px', textTransform: 'capitalize' }}>{u.resource_type}</td>
                <td style={{ padding: '6px 8px' }}>{u.current_count}</td>
                <td style={{ padding: '6px 8px' }}>{u.max_count ?? 'Unlimited'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="card">
      <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 4 }}>{label}</div>
      <div style={{ fontSize: 16, fontWeight: 600 }}>{value}</div>
    </div>
  );
}
