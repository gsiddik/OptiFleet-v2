import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { inputStyle } from '../../../components/FormField';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { VendorInvoiceReceiptRow } from '../../../types';
import { formatDate } from '../../../utils/date';
import { formatMoney } from '../../../utils/money';
import { openProtectedFile } from '../../../utils/protectedFile';

const FILTERS = [
  { value: '', label: 'All' },
  { value: 'NEW', label: 'New' },
  { value: 'DUE_SOON', label: 'Due Soon' },
  { value: 'LATE', label: 'Late' },
  { value: 'PAID', label: 'Paid' },
];

/**
 * Vendor Invoice References — tracking of the invoices recorded at Goods Receipt (no standalone
 * creation). One row per Goods Receipt; an invoice shared by several receipts shows on each of
 * their rows with the same data and status. Status comes from the backend.
 */
export function VendorInvoiceReferenceListPage() {
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [page, setPage] = useState(1);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const { data, meta, loading, error } = useApiList<VendorInvoiceReceiptRow>('/app/vendor-invoice-references', {
    status: status || undefined,
    search: debounced || undefined,
    page,
  });

  const columns: Column<VendorInvoiceReceiptRow>[] = [
    {
      key: 'gr',
      header: 'GR#',
      render: (r) => (
        <div>
          <div>{r.gr_number}</div>
          {r.invoice.has_document && (
            <button type="button" className="btn-link" style={{ fontSize: 12, padding: 0 }} onClick={() => openProtectedFile(`/app/vendor-invoice-references/${r.invoice.id}/download`)}>
              View Invoice
            </button>
          )}
        </div>
      ),
    },
    { key: 'po', header: 'Purchase Order #', render: (r) => (r.purchase_order ? <Link to={`/app/purchase-orders/${r.purchase_order.id}`}>{r.purchase_order.po_number}</Link> : '—') },
    { key: 'invoice', header: 'Invoice Number', render: (r) => r.invoice.vendor_invoice_number },
    { key: 'vendor', header: 'Vendor', render: (r) => r.invoice.partner?.name ?? '—' },
    { key: 'amount', header: 'Amount', render: (r) => <div style={{ textAlign: 'right' }}>{formatMoney(r.invoice.amount)}</div> },
    { key: 'top', header: 'Terms of Payment (Days)', render: (r) => <div style={{ textAlign: 'right' }}>{r.invoice.terms_of_payment_days ?? '—'}</div> },
    { key: 'date', header: 'Invoice Date', render: (r) => formatDate(r.invoice.vendor_invoice_date) },
    { key: 'due', header: 'Due Date', render: (r) => formatDate(r.invoice.due_date) },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.invoice.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vendor Invoice References</h1>
      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: -10 }}>
        Recorded when goods are received (Purchase Order → Post Goods Receipt). Due dates count working days (Mon–Fri). Procurement traceability only — separate from tenant SaaS
        billing invoices under Account &gt; Invoices.
      </p>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginBottom: 12 }}>
        {FILTERS.map((f) => (
          <button
            key={f.value}
            className={status === f.value ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '4px 10px', fontSize: 12 }}
            onClick={() => {
              setStatus(f.value);
              setPage(1);
            }}
          >
            {f.label}
          </button>
        ))}
        <input
          aria-label="Search vendor invoices"
          placeholder="Search GR#, PO#, invoice or vendor…"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 280 }}
        />
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vendor invoices recorded at Goods Receipt yet." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
