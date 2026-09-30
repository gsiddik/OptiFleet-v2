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
  const [action, setAction] = useState<{ kind: 'activate' | 'adjust'; sub: SubscriptionItem } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data, loading, error: listError } = useApiList<SubscriptionItem>('/platform/subscriptions', { status: tab || undefined }, reloadKey);

  // Generating a billing also generates and issues its invoice, so the backend requires all three.
  const canGenerateBilling = hasPermission('billing.generate') && hasPermission('invoice.generate') && hasPermission('invoice.issue');

  async function generateBilling(sub: SubscriptionItem) {
    setError(null);
    setNotice(null);
    try {
      await apiClient.post(`/platform/subscriptions/${sub.id}/generate-billing`);
      setNotice(`Billing and invoice generated for ${sub.tenant?.name ?? 'the subscription'}.`);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

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
          {s.status === 'PENDING' && hasPermission('subscription.activate') && (
            <button className="btn-link" onClick={() => setAction({ kind: 'activate', sub: s })}>
              Activate
            </button>
          )}
          {['ACTIVE', 'PAST_DUE', 'GRACE_PERIOD'].includes(s.status) && canGenerateBilling && (
            <button className="btn-link" onClick={() => generateBilling(s)}>
              Generate Billing
            </button>
          )}
          {!['CANCELLED', 'EXPIRED'].includes(s.status) && hasPermission('billing.adjust') && (
            <button className="btn-link" onClick={() => setAction({ kind: 'adjust', sub: s })}>
              Adjustment Invoice
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
      {notice && <div style={{ color: '#047857', fontSize: 13, marginBottom: 10 }}>{notice}</div>}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && data.length === 0 && <EmptyState label="No subscriptions found." />}
      {!listError && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {action && (
        <SubscriptionActionModal
          kind={action.kind}
          subscription={action.sub}
          onClose={() => setAction(null)}
          onDone={(message) => {
            setAction(null);
            setNotice(message);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
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

/** Manual activation of a PENDING subscription, or a manual adjustment (extra charge) invoice. */
function SubscriptionActionModal({
  kind,
  subscription,
  onClose,
  onDone,
}: {
  kind: 'activate' | 'adjust';
  subscription: SubscriptionItem;
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const [reason, setReason] = useState('');
  const [amount, setAmount] = useState('');
  const [description, setDescription] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  async function submit() {
    setSubmitting(true);
    setError(null);
    setErrors({});
    try {
      if (kind === 'activate') {
        await apiClient.post(`/platform/subscriptions/${subscription.id}/activate`, { reason });
        onDone('Subscription activated.');
      } else {
        const res = await apiClient.post(`/platform/subscriptions/${subscription.id}/adjustment-invoices`, { amount, description });
        onDone(`Adjustment invoice ${res.data.data.invoice_number} issued.`);
      }
    } catch (err) {
      const e = extractApiError(err);
      setError(e.message);
      setErrors(e.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  const tenant = subscription.tenant?.name ?? '';
  return (
    <Modal open title={kind === 'activate' ? `Activate Subscription — ${tenant}` : `Adjustment Invoice — ${tenant}`} onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      {kind === 'activate' ? (
        <>
          <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
            Activates the subscription and its module entitlements now, without waiting for the first payment.
          </p>
          <FormField label="Reason" required errors={errors.reason}>
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
          </FormField>
        </>
      ) : (
        <>
          <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
            Issues an additional invoice for this subscription. Issued invoices cannot be edited — void and re-issue to correct.
          </p>
          <FormField label="Amount" required errors={errors.amount}>
            <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="e.g. 150000.00" style={inputStyle} />
          </FormField>
          <FormField label="Description" required errors={errors.description}>
            <input value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          </FormField>
        </>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button
          className="btn-primary"
          disabled={submitting || (kind === 'activate' ? !reason.trim() : !amount || !description.trim())}
          onClick={submit}
        >
          {submitting ? 'Saving…' : kind === 'activate' ? 'Activate' : 'Issue Invoice'}
        </button>
      </div>
    </Modal>
  );
}
