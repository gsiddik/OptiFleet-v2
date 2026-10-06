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
import { message } from '../../../i18n/messages';
import { t as tt } from '../../../i18n/i18n';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDate } from '../../../utils/date';

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
      setNotice(sub.tenant?.name ? message('platform.subscriptions.messages.billingGenerated', { tenantName: sub.tenant.name }) : message('platform.subscriptions.messages.billingGeneratedNoTenant'));
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
    { key: 'tenant', header: tt('common.fields.tenant'), render: (s) => s.tenant?.name ?? '—' },
    { key: 'contract', header: tt('common.fields.contractNumber'), render: (s) => s.contract?.contract_number ?? '—' },
    { key: 'start_date', header: tt('common.fields.start'), render: (s) => formatDate(s.start_date) },
    { key: 'end_date', header: tt('common.fields.end'), render: (s) => formatDate(s.end_date) },
    { key: 'next_billing_date', header: tt('platform.subscriptions.fields.nextBilling'), render: (s) => formatDate(s.next_billing_date) },
    { key: 'status', header: tt('common.fields.status'), render: (s) => <StatusBadge status={s.status} /> },
    {
      key: 'actions',
      header: '',
      render: (s) => (
        <div style={{ display: 'flex', gap: 6 }}>
          {['ACTIVE', 'PAST_DUE', 'GRACE_PERIOD'].includes(s.status) && hasPermission('subscription.suspend') && (
            <button className="btn-link" onClick={() => setSuspending(s)}>
              {tt('platform.subscriptions.actions.suspend')}
            </button>
          )}
          {s.status === 'PENDING' && hasPermission('subscription.activate') && (
            <button className="btn-link" onClick={() => setAction({ kind: 'activate', sub: s })}>
              {tt('common.actions.activate')}
            </button>
          )}
          {['ACTIVE', 'PAST_DUE', 'GRACE_PERIOD'].includes(s.status) && canGenerateBilling && (
            <button className="btn-link" onClick={() => generateBilling(s)}>
              {tt('platform.subscriptions.actions.generateBilling')}
            </button>
          )}
          {!['CANCELLED', 'EXPIRED'].includes(s.status) && hasPermission('billing.adjust') && (
            <button className="btn-link" onClick={() => setAction({ kind: 'adjust', sub: s })}>
              {tt('platform.subscriptions.actions.adjustmentInvoice')}
            </button>
          )}
          {s.status === 'SUSPENDED' && hasPermission('subscription.reactivate') && (
            <button className="btn-link" onClick={() => reactivate(s)}>
              {tt('common.actions.reactivate')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.subscriptions.titles.subscriptions')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t ? statusLabel(t) : tt('common.actions.all')}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {notice && <div style={{ color: '#047857', fontSize: 13, marginBottom: 10 }}>{notice}</div>}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && data.length === 0 && <EmptyState label={tt('platform.subscriptions.empty.noSubscriptionsFound')} />}
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
    <Modal open title={tt('platform.subscriptions.modals.suspendSubscription')} onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label={tt('common.fields.reason')}>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('platform.subscriptions.actions.suspending') : tt('platform.subscriptions.actions.suspend')}
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
        onDone(tt('platform.subscriptions.fields.subscriptionActivated'));
      } else {
        const res = await apiClient.post(`/platform/subscriptions/${subscription.id}/adjustment-invoices`, { amount, description });
        onDone(tt('platform.subscriptions.fields.adjustmentInvoiceInvoiceNumberIssued', { invoice_number: res.data.data.invoice_number }));
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
    <Modal open title={kind === 'activate' ? tt('platform.subscriptions.modals.activateSubscriptionTenant', { tenant: tenant }) : tt('platform.subscriptions.modals.adjustmentInvoiceTenant', { tenant: tenant })} onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      {kind === 'activate' ? (
        <>
          <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
            {tt('platform.subscriptions.help.activatesSubscriptionModuleEntitlementsNowWithout')}
          </p>
          <FormField label={tt('common.fields.reason')} required errors={errors.reason}>
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
          </FormField>
        </>
      ) : (
        <>
          <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>
            {tt('platform.subscriptions.help.issuesAdditionalInvoiceSubscriptionIssuedInvoices')}
          </p>
          <FormField label={tt('common.fields.amount')} required errors={errors.amount}>
            <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="e.g. 150000.00" style={inputStyle} />
          </FormField>
          <FormField label={tt('common.fields.description')} required errors={errors.description}>
            <input value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          </FormField>
        </>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button
          className="btn-primary"
          disabled={submitting || (kind === 'activate' ? !reason.trim() : !amount || !description.trim())}
          onClick={submit}
        >
          {submitting ? tt('common.actions.saving') : kind === 'activate' ? tt('common.actions.activate') : tt('platform.subscriptions.actions.issueInvoice')}
        </button>
      </div>
    </Modal>
  );
}
