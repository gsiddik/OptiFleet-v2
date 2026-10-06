import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { InvoiceItem } from '../../../types';
import { formatQty } from '../../../utils/quantity';
import { formatMoney } from '../../../utils/money';

export function InvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [invoice, setInvoice] = useState<InvoiceItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showVoid, setShowVoid] = useState(false);
  const [downloading, setDownloading] = useState(false);

  function load() {
    apiClient
      .get(`/platform/invoices/${id}`)
      .then((res) => setInvoice(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(invoice?.id, invoice?.invoice_number);

  async function downloadPdf() {
    setDownloading(true);
    try {
      const res = await apiClient.get(`/platform/invoices/${id}/pdf`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setDownloading(false);
    }
  }

  if (error && !invoice) return <ErrorState message={error} />;
  if (!invoice) return <LoadingState />;

  const canVoid = invoice.status !== 'VOID' && invoice.status !== 'PAID' && hasPermission('invoice.void');

  return (
    <div>
      <BackButton fallbackTo="/platform/invoices" label="← Back to Invoices" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {invoice.invoice_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({invoice.tenant?.name})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={invoice.status} />
          <button className="btn-secondary" disabled={downloading} onClick={downloadPdf}>
            {downloading ? 'Loading…' : 'Download PDF'}
          </button>
          {canVoid && (
            <button className="btn-secondary" style={{ color: '#b91c1c' }} onClick={() => setShowVoid(true)}>
              Void
            </button>
          )}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
        <SummaryCard label="Invoice Date" value={invoice.invoice_date} />
        <SummaryCard label="Due Date" value={invoice.due_date} />
        <SummaryCard label="Total" value={`${invoice.currency} ${formatMoney(invoice.total)}`} />
        <SummaryCard label="Outstanding" value={`${invoice.currency} ${formatMoney(invoice.outstanding_amount)}`} />
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Line Items</h3>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px 8px' }}>Description</th>
              <th style={{ padding: '6px 8px' }}>Qty</th>
              <th style={{ padding: '6px 8px' }}>Unit Price</th>
              <th style={{ padding: '6px 8px' }}>Discount</th>
              <th style={{ padding: '6px 8px' }}>Tax</th>
              <th style={{ padding: '6px 8px' }}>Amount</th>
            </tr>
          </thead>
          <tbody>
            {(invoice.items ?? []).map((it) => (
              <tr key={it.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '6px 8px' }}>{it.description}</td>
                <td style={{ padding: '6px 8px' }}>{formatQty(it.quantity)}</td>
                <td style={{ padding: '6px 8px' }}>{formatMoney(it.unit_price)}</td>
                <td style={{ padding: '6px 8px' }}>{formatMoney(it.discount)}</td>
                <td style={{ padding: '6px 8px' }}>{formatMoney(it.tax)}</td>
                <td style={{ padding: '6px 8px', fontWeight: 600 }}>{formatMoney(it.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ textAlign: 'right', marginTop: 10, fontSize: 13, color: '#374151' }}>
          Subtotal: {formatMoney(invoice.subtotal)} &nbsp; Discount: {formatMoney(invoice.discount)} &nbsp; Tax:{' '}
          {formatMoney(invoice.tax)} &nbsp; Paid: {formatMoney(invoice.paid_amount)} &nbsp;{' '}
          <strong>
            Total: {invoice.currency} {formatMoney(invoice.total)}
          </strong>
        </div>
      </div>

      {showVoid && (
        <VoidModal
          onClose={() => setShowVoid(false)}
          onDone={() => {
            setShowVoid(false);
            load();
          }}
          invoiceId={invoice.id}
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

function VoidModal({ invoiceId, onClose, onDone }: { invoiceId: string; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/platform/invoices/${invoiceId}/void`, { reason });
      onDone();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Void Invoice" onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label="Reason">
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Voiding…' : 'Void Invoice'}
        </button>
      </div>
    </Modal>
  );
}
