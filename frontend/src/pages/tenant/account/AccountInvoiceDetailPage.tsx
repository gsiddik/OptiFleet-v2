import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { InvoiceItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';

export function AccountInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [invoice, setInvoice] = useState<InvoiceItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    apiClient
      .get(`/app/account/invoices/${id}`)
      .then((res) => setInvoice(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, [id]);

  useBreadcrumbLabel(invoice?.id, invoice?.invoice_number);

  async function downloadPdf() {
    setDownloading(true);
    try {
      const res = await apiClient.get(`/app/account/invoices/${id}/pdf`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${invoice?.invoice_number ?? 'invoice'}.pdf`;
      a.click();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setDownloading(false);
    }
  }

  if (error && !invoice) return <ErrorState message={error} />;
  if (!invoice) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/account/invoices" label="← Back to Invoices" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{invoice.invoice_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={invoice.status} />
          {hasPermission('account.invoice.download') && (
            <button className="btn-secondary" disabled={downloading} onClick={downloadPdf}>
              {downloading ? 'Loading…' : 'Download PDF'}
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
              <th style={{ padding: '6px 8px' }}>Amount</th>
            </tr>
          </thead>
          <tbody>
            {(invoice.items ?? []).map((it) => (
              <tr key={it.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '6px 8px' }}>{it.description}</td>
                <td style={{ padding: '6px 8px' }}>{formatQty(it.quantity)}</td>
                <td style={{ padding: '6px 8px' }}>{formatMoney(it.unit_price)}</td>
                <td style={{ padding: '6px 8px', fontWeight: 600 }}>{formatMoney(it.amount)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ textAlign: 'right', marginTop: 10, fontSize: 13, color: '#374151' }}>
          <strong>
            Total: {invoice.currency} {formatMoney(invoice.total)}
          </strong>
        </div>
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
