import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { InvoiceItem, PaymentItem } from '../../../types';

const TABS = ['', 'SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'REJECTED', 'REVERSED'];
const PAYMENT_METHODS = ['BANK_TRANSFER', 'VIRTUAL_ACCOUNT', 'CREDIT_CARD', 'E_WALLET', 'CASH', 'OTHER'];

export function AccountPaymentListPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showSubmit, setShowSubmit] = useState(false);
  const { data, loading, error } = useApiList<PaymentItem>('/app/account/payments', { status: tab || undefined }, reloadKey);

  const columns: Column<PaymentItem>[] = [
    { key: 'invoice', header: 'Invoice #', render: (p) => p.invoice?.invoice_number ?? '—' },
    { key: 'payment_date', header: 'Payment Date', render: (p) => p.payment_date },
    { key: 'amount', header: 'Amount', render: (p) => Number(p.amount).toLocaleString() },
    { key: 'method', header: 'Method', render: (p) => p.payment_method },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
    { key: 'actions', header: '', render: (p) => <Link to={`/app/account/payments/${p.id}`}>View</Link> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Payments</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('account.payment.submit') ? (
            <button className="btn-primary" onClick={() => setShowSubmit(true)}>
              + Submit Payment
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No payments found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {showSubmit && (
        <SubmitPaymentModal
          onClose={() => setShowSubmit(false)}
          onSubmitted={() => {
            setShowSubmit(false);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function SubmitPaymentModal({ onClose, onSubmitted }: { onClose: () => void; onSubmitted: () => void }) {
  const [invoices, setInvoices] = useState<InvoiceItem[]>([]);
  const [invoiceId, setInvoiceId] = useState('');
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().slice(0, 10));
  const [amount, setAmount] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('BANK_TRANSFER');
  const [bankName, setBankName] = useState('');
  const [accountName, setAccountName] = useState('');
  const [transactionReference, setTransactionReference] = useState('');
  const [note, setNote] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    Promise.all([
      apiClient.get('/app/account/invoices', { params: { status: 'OUTSTANDING' } }),
      apiClient.get('/app/account/invoices', { params: { status: 'PARTIALLY_PAID' } }),
      apiClient.get('/app/account/invoices', { params: { status: 'OVERDUE' } }),
    ]).then(([a, b, c]) => setInvoices([...a.data.data, ...b.data.data, ...c.data.data]));
  }, []);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const res = await apiClient.post('/app/account/payments', {
        invoice_id: invoiceId,
        payment_date: paymentDate,
        amount,
        payment_method: paymentMethod,
        bank_name: bankName || null,
        account_name: accountName || null,
        transaction_reference: transactionReference || null,
        note: note || null,
      });
      const paymentId = res.data.data.id;
      if (file) {
        const form = new FormData();
        form.append('file', file);
        await apiClient.post(`/app/account/payments/${paymentId}/proof`, form);
      }
      onSubmitted();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Submit Payment" onClose={onClose} width={560}>
      <FormField label="Invoice" errors={errors.invoice_id}>
        <select value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} style={inputStyle}>
          <option value="">Select an outstanding invoice…</option>
          {invoices.map((inv) => (
            <option key={inv.id} value={inv.id}>
              {inv.invoice_number} — {inv.currency} {Number(inv.outstanding_amount).toLocaleString()} outstanding
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Payment Date" errors={errors.payment_date}>
          <input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Amount" errors={errors.amount}>
          <input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Payment Method" errors={errors.payment_method}>
          <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} style={inputStyle}>
            {PAYMENT_METHODS.map((m) => (
              <option key={m} value={m}>
                {m}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Bank Name" errors={errors.bank_name}>
          <input value={bankName} onChange={(e) => setBankName(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Account Name" errors={errors.account_name}>
          <input value={accountName} onChange={(e) => setAccountName(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Transaction Reference" errors={errors.transaction_reference}>
          <input value={transactionReference} onChange={(e) => setTransactionReference(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label="Note" errors={errors.note}>
        <textarea value={note} onChange={(e) => setNote(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <FormField label="Proof of Payment (optional, JPG/PNG/WEBP/PDF, max 5MB)" errors={errors.file}>
        <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !invoiceId} onClick={submit}>
          {submitting ? 'Submitting…' : 'Submit Payment'}
        </button>
      </div>
    </Modal>
  );
}
