import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { PaymentItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { t } from '../../../i18n/i18n';
import { formatDate } from '../../../utils/date';

export function PaymentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [payment, setPayment] = useState<PaymentItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [decision, setDecision] = useState<'verify' | 'reject' | null>(null);

  function load() {
    apiClient
      .get(`/platform/payments/${id}`)
      .then((res) => setPayment(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function viewProof(proofId: string, filename: string) {
    try {
      const res = await apiClient.get(`/platform/payments/${id}/proofs/${proofId}`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener';
      a.download = filename;
      a.click();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  if (error && !payment) return <ErrorState message={error} />;
  if (!payment) return <LoadingState />;

  const canDecide = ['SUBMITTED', 'UNDER_REVIEW'].includes(payment.status) && (hasPermission('payment.verify') || hasPermission('payment.reject'));

  return (
    <div>
      <BackButton fallbackTo="/platform/payments" label={t('platform.payments.actions.backToPayments')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {t('platform.payments.titles.payment')} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({payment.tenant?.name})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={payment.status} />
          {canDecide && hasPermission('payment.verify') && (
            <button className="btn-primary" onClick={() => setDecision('verify')}>
              {t('platform.payments.actions.verify')}
            </button>
          )}
          {canDecide && hasPermission('payment.reject') && (
            <button className="btn-secondary" style={{ color: '#b91c1c' }} onClick={() => setDecision('reject')}>
              {t('common.actions.reject')}
            </button>
          )}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
        <SummaryCard label={t('platform.payments.sections.invoice')} value={payment.invoice ? payment.invoice.invoice_number : '—'} />
        <SummaryCard label={t('platform.payments.sections.paymentDate')} value={formatDate(payment.payment_date)} />
        <SummaryCard label={t('platform.payments.sections.amount')} value={formatMoney(payment.amount)} />
        <SummaryCard label={t('platform.payments.sections.method')} value={payment.payment_method} />
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('common.sections.details')}</h3>
        <div style={{ fontSize: 13, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, color: '#374151' }}>
          <div>{t('common.fields.bank')}: {payment.bank_name ?? '—'}</div>
          <div>{t('platform.payments.fields.accountName')}: {payment.account_name ?? '—'}</div>
          <div>{t('platform.payments.fields.transactionReference')}: {payment.transaction_reference ?? '—'}</div>
          <div>{t('platform.payments.fields.note')}: {payment.note ?? '—'}</div>
          {payment.verification_note && <div>{t('platform.payments.fields.verificationNoteVerificationNote', { verification_note: payment.verification_note })}</div>}
        </div>
        {payment.invoice && (
          <div style={{ marginTop: 10 }}>
            <Link to={`/platform/invoices/${payment.invoice_id}`}>{t('platform.payments.actions.viewInvoice')}</Link>
          </div>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('platform.payments.sections.proofOfPayment')}</h3>
        {(payment.proofs ?? []).length === 0 && <p style={{ fontSize: 13, color: '#9ca3af' }}>{t('platform.payments.empty.noProofFilesUploaded')}</p>}
        {(payment.proofs ?? []).map((p) => (
          <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: 13, borderBottom: '1px solid #f3f4f6' }}>
            <span>
              {p.original_filename} <span style={{ color: '#9ca3af' }}>({(p.size / 1024).toFixed(0)} KB)</span>
            </span>
            <button className="btn-link" onClick={() => viewProof(p.id, p.original_filename)}>
              {t('common.actions.download')}
            </button>
          </div>
        ))}
      </div>

      {decision && (
        <DecisionModal
          kind={decision}
          paymentId={payment.id}
          onClose={() => setDecision(null)}
          onDone={() => {
            setDecision(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="card" style={{ padding: 14 }}>
      <div style={{ fontSize: 12, color: '#9ca3af', marginBottom: 4 }}>{label}</div>
      <div style={{ fontSize: 16, fontWeight: 600 }}>{value}</div>
    </div>
  );
}

function DecisionModal({
  kind,
  paymentId,
  onClose,
  onDone,
}: {
  kind: 'verify' | 'reject';
  paymentId: string;
  onClose: () => void;
  onDone: () => void;
}) {
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/platform/payments/${paymentId}/${kind}`, { note });
      onDone();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={kind === 'verify' ? t('platform.payments.modals.verifyPayment') : t('platform.payments.modals.rejectPayment')} onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label={kind === 'verify' ? t('inventory.placeholders.noteOptional') : t('common.fields.reason')} required={kind === 'reject'}>
        <textarea value={note} onChange={(e) => setNote(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || (kind === 'reject' && !note)} onClick={submit}>
          {submitting ? t('common.actions.submitting') : kind === 'verify' ? t('platform.payments.actions.verify') : t('common.actions.reject')}
        </button>
      </div>
    </Modal>
  );
}
