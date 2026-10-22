import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import type { SubscriptionItem } from '../../../types';
import { t } from '../../../i18n/i18n';
import { formatDate } from '../../../utils/date';

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
    let mounted = true;
    let pending = false;
    async function refresh() {
      if (pending) return;
      pending = true;
      try {
        const [subRes, modRes, usageRes] = await Promise.all([
          apiClient.get('/app/account/subscription'),
          apiClient.get('/app/account/active-modules'),
          apiClient.get('/app/account/usage-limits'),
        ]);
        if (mounted) {
          setSubscription(subRes.data.data);
          setActiveModules(modRes.data.data);
          setUsage(usageRes.data.data);
          setError(null);
        }
      } catch (err) {
        if (mounted) setError(extractApiError(err).message);
      } finally {
        pending = false;
      }
    }
    void refresh();
    const timer = window.setInterval(() => { void refresh(); }, 30000);
    return () => { mounted = false; window.clearInterval(timer); };
  }, []);

  if (error) return <ErrorState message={error} />;
  if (subscription === undefined || !activeModules || !usage) return <LoadingState />;

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>{t('account.titles.subscription')}</h1>

      {!subscription && (
        <div className="card" style={{ color: '#9ca3af' }}>
          {t('account.empty.noActiveSubscriptionFoundOrganization')}
        </div>
      )}

      {subscription && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginBottom: 24 }}>
            <div className="card">
              <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 6 }}>{t('common.fields.status')}</div>
              <StatusBadge status={subscription.status} />
            </div>
            <SummaryCard label={t('platform.contracts.fields.startDate')} value={formatDate(subscription.start_date)} />
            <SummaryCard label={t('platform.contracts.fields.endDate')} value={formatDate(subscription.end_date)} />
            <SummaryCard label={t('account.sections.nextBillingDate')} value={formatDate(subscription.next_billing_date)} />
          </div>

          {subscription.source === 'OPTINEXUS' && subscription.details && (
            <div className="card" style={{ marginBottom: 24 }}>
              <h2>{t('common.sections.details')}</h2>
              <dl>
                <dt>{t('account.fields.subscriptionNumber')}</dt><dd>{subscription.details.subscription_number}</dd>
                <dt>{t('common.fields.product')}</dt><dd>{subscription.details.product_name ?? '—'}</dd>
                <dt>{t('account.fields.plan')}</dt><dd>{subscription.details.plan_name ?? '—'}</dd>
                <dt>{t('account.fields.billingInterval')}</dt><dd>{subscription.details.billing_interval}</dd>
                <dt>{t('account.fields.currentPeriod')}</dt><dd>{formatDate(subscription.details.current_period_start)} — {formatDate(subscription.details.current_period_end)}</dd>
                <dt>{t('platform.contracts.fields.gracePeriodDays')}</dt><dd>{subscription.details.grace_period_days}</dd>
                <dt>{t('platform.contracts.fields.gracePeriodEnd')}</dt><dd>{formatDate(subscription.grace_period_end)}</dd>
              </dl>
              <h3>{t('common.sections.items')}</h3>
              <table style={{ width: '100%' }}>
                <thead><tr><th>{t('common.fields.type')}</th><th>{t('account.fields.planAddon')}</th><th>{t('common.fields.quantity')}</th><th>{t('account.fields.unitPrice')}</th><th>{t('account.fields.totalPrice')}</th></tr></thead>
                <tbody>{subscription.details.items.map((item) => <tr key={item.id}>
                  <td>{item.price_type ?? item.item_type}</td><td>{item.plan_name ?? item.addon_name ?? '—'}</td>
                  <td>{item.quantity}</td><td>{subscription.details!.currency} {item.unit_price ?? '—'}</td><td>{subscription.details!.currency} {item.total_price ?? '—'}</td>
                </tr>)}</tbody>
              </table>
            </div>
          )}

          {subscription.status === 'SUSPENDED' && (
            <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 14, borderRadius: 8, marginBottom: 20, fontSize: 14 }}>
              {t('account.help.subscriptionSuspendedOperationalFeaturesRestrictedUntil')}
            </div>
          )}
          {['GRACE_PERIOD', 'PAST_DUE'].includes(subscription.status) && (
            <div style={{ background: '#fffbeb', color: '#a16207', padding: 14, borderRadius: 8, marginBottom: 20, fontSize: 14 }}>
              {t('account.help.accountOutstandingBalancePleaseSettleBefore')} {subscription.grace_period_end ?? t('account.help.theGracePeriodEnds')}{' '}
              {t('account.help.toAvoidSuspension')}
            </div>
          )}
        </>
      )}

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('dashboard.sections.activeModules')}</h2>
      <div className="card" style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 24 }}>
        {activeModules.map((m) => (
          <span key={m} style={{ background: '#eff6ff', color: '#1d4ed8', padding: '4px 10px', borderRadius: 6, fontSize: 12 }}>
            {m}
          </span>
        ))}
        {activeModules.length === 0 && <span style={{ color: '#9ca3af' }}>{t('dashboard.empty.noModulesEntitled')}</span>}
      </div>

      <h2 style={{ fontSize: 16, marginBottom: 12 }}>{t('account.sections.usageAndLimits')}</h2>
      <div className="card">
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px 8px' }}>{t('common.fields.resource')}</th>
              <th style={{ padding: '6px 8px' }}>{t('account.fields.current')}</th>
              <th style={{ padding: '6px 8px' }}>{t('account.fields.limit')}</th>
            </tr>
          </thead>
          <tbody>
            {usage.map((u) => (
              <tr key={u.resource_type} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '6px 8px', textTransform: 'capitalize' }}>{u.resource_type}</td>
                <td style={{ padding: '6px 8px' }}>{u.current_count}</td>
                <td style={{ padding: '6px 8px' }}>{u.max_count ?? t('account.help.unlimited')}</td>
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
