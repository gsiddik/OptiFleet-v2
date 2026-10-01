import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { PaymentItem } from '../../../types';
import { formatMoney } from '../../../utils/money';

export function AccountPaymentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [payment, setPayment] = useState<PaymentItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);

  function load() {
    apiClient
      .get(`/app/account/payments/${id}`)
      .then((res) => setPayment(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function viewProof(proofId: string, filename: string) {
    try {
      const res = await apiClient.get(`/app/account/payments/${id}/proofs/${proofId}`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      a.click();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function uploadProof(file: File) {
    setUploading(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('file', file);
      await apiClient.post(`/app/account/payments/${id}/proof`, form);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setUploading(false);
    }
  }

  if (error && !payment) return <ErrorState message={error} />;
  if (!payment) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/account/payments" label="← Back to Payments" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>Payment</h1>
        <StatusBadge status={payment.status} />
      </div>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
        <SummaryCard label="Invoice" value={payment.invoice ? payment.invoice.invoice_number : '—'} />
        <SummaryCard label="Payment Date" value={payment.payment_date} />
        <SummaryCard label="Amount" value={formatMoney(payment.amount)} />
        <SummaryCard label="Method" value={payment.payment_method} />
      </div>

      {payment.status === 'REJECTED' && (
        <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 14, borderRadius: 8, marginBottom: 16, fontSize: 14 }}>
          This payment was rejected{payment.verification_note ? `: ${payment.verification_note}` : '.'} Please submit a new payment for
          this invoice from the Payments list.
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
        <div style={{ fontSize: 13, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, color: '#374151' }}>
          <div>Bank: {payment.bank_name ?? '—'}</div>
          <div>Account Name: {payment.account_name ?? '—'}</div>
          <div>Transaction Reference: {payment.transaction_reference ?? '—'}</div>
          <div>Note: {payment.note ?? '—'}</div>
        </div>
        {payment.invoice && (
          <div style={{ marginTop: 10 }}>
            <Link to={`/app/account/invoices/${payment.invoice_id}`}>View Invoice →</Link>
          </div>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Proof of Payment</h3>
        {(payment.proofs ?? []).length === 0 && <p style={{ fontSize: 13, color: '#9ca3af' }}>No proof files uploaded.</p>}
        {(payment.proofs ?? []).map((p) => (
          <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: 13, borderBottom: '1px solid #f3f4f6' }}>
            <span>
              {p.original_filename} <span style={{ color: '#9ca3af' }}>({(p.size / 1024).toFixed(0)} KB)</span>
            </span>
            <button className="btn-link" onClick={() => viewProof(p.id, p.original_filename)}>
              Download
            </button>
          </div>
        ))}
        {['SUBMITTED', 'UNDER_REVIEW'].includes(payment.status) && hasPermission('account.payment.submit') && (
          <div style={{ marginTop: 12 }}>
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp,.pdf"
              disabled={uploading}
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) uploadProof(f);
              }}
            />
          </div>
        )}
      </div>
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
