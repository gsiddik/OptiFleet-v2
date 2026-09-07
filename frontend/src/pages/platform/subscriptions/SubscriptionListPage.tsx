import { useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { SubscriptionItem } from '../../../types';

const TABS = ['', 'PENDING', 'ACTIVE', 'EXPIRING', 'PAST_DUE', 'GRACE_PERIOD', 'SUSPENDED', 'EXPIRED', 'CANCELLED'];

export function SubscriptionListPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [suspending, setSuspending] = useState<SubscriptionItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data, loading, error: listError } = useApiList<SubscriptionItem>('/platform/subscriptions', { status: tab || undefined }, reloadKey);

  async function reactivate(sub: SubscriptionItem) {
    setError(null);
    try {
      await apiClient.post(`/platform/subscriptions/${sub.id}/reactivate`);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  const columns: Column<SubscriptionItem>[] = [
    { key: 'tenant', header: 'Tenant', render: (s) => s.tenant?.name ?? '—' },
    { key: 'contract', header: 'Contract #', render: (s) => s.contract?.contract_number ?? '—' },
    { key: 'start_date', header: 'Start', render: (s) => s.start_date },
    { key: 'end_date', header: 'End', render: (s) => s.end_date },
    { key: 'next_billing_date', header: 'Next Billing', render: (s) => s.next_billing_date },
    { key: 'status', header: 'Status', render: (s) => <StatusBadge status={s.status} /> },
    {
      key: 'actions',
      header: '',
      render: (s) => (
        <div style={{ display: 'flex', gap: 6 }}>
          {['ACTIVE', 'PAST_DUE', 'GRACE_PERIOD'].includes(s.status) && hasPermission('subscription.suspend') && (
            <button className="btn-link" onClick={() => setSuspending(s)}>
              Suspend
            </button>
          )}
          {s.status === 'SUSPENDED' && hasPermission('subscription.reactivate') && (
            <button className="btn-link" onClick={() => reactivate(s)}>
              Reactivate
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Subscriptions</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && data.length === 0 && <EmptyState label="No subscriptions found." />}
      {!listError && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {suspending && (
        <SuspendModal
          subscription={suspending}
          onClose={() => setSuspending(null)}
          onDone={() => {
            setSuspending(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function SuspendModal({ subscription, onClose, onDone }: { subscription: SubscriptionItem; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/platform/subscriptions/${subscription.id}/suspend`, { reason });
      onDone();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Suspend Subscription" onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label="Reason">
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Suspending…' : 'Suspend'}
        </button>
      </div>
    </Modal>
  );
}
