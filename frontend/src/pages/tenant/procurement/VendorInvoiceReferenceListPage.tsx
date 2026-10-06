import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { inputStyle } from '../../../components/FormField';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VendorInvoiceReceiptRow, VendorInvoiceSummary } from '../../../types';
import { formatDate } from '../../../utils/date';
import { formatMoney } from '../../../utils/money';
import { openProtectedFile } from '../../../utils/protectedFile';
import { PaymentProofModal, VendorInvoicePaymentModal } from './VendorInvoicePaymentModal';
import { t as tt, withLabels } from '../../../i18n/i18n';

const FILTERS = withLabels([
  { value: '', label: 'All', labelKey: 'procurement.filters.all' },
  { value: 'NEW', label: 'New', labelKey: 'procurement.filters.new' },
  { value: 'DUE_SOON', label: 'Due Soon', labelKey: 'procurement.filters.dueSoon' },
  { value: 'LATE', label: 'Late', labelKey: 'procurement.filters.late' },
  { value: 'PAID', label: 'Paid', labelKey: 'procurement.filters.paid' },
]);

/**
 * Vendor Invoice References — tracking of the invoices recorded at Goods Receipt (no standalone
 * creation). One row per Goods Receipt; an invoice shared by several receipts shows on each of
 * their rows with the same data and status. Status comes from the backend.
 */
export function VendorInvoiceReferenceListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [paying, setPaying] = useState<VendorInvoiceSummary | null>(null);
  const [proofOf, setProofOf] = useState<VendorInvoiceSummary | null>(null);
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
  }, reloadKey);

  const columns: Column<VendorInvoiceReceiptRow>[] = [
    {
      key: 'gr',
      header: tt('procurement.fields.grNumber2'),
      render: (r) => (
        <div>
          <div>{r.gr_number}</div>
          {r.invoice.has_document && (
            <button type="button" className="btn-link" style={{ fontSize: 12, padding: 0 }} onClick={() => openProtectedFile(`/app/vendor-invoice-references/${r.invoice.id}/download`)}>
              {tt('procurement.actions.viewInvoice')}
            </button>
          )}
        </div>
      ),
    },
    { key: 'po', header: tt('procurement.fields.purchaseOrderNumber'), render: (r) => (r.purchase_order ? <Link to={`/app/purchase-orders/${r.purchase_order.id}`}>{r.purchase_order.po_number}</Link> : '—') },
    { key: 'invoice', header: tt('procurement.fields.invoiceNumber'), render: (r) => r.invoice.vendor_invoice_number },
    { key: 'vendor', header: tt('common.fields.vendor'), render: (r) => r.invoice.partner?.name ?? '—' },
    { key: 'amount', header: tt('common.fields.amount'), render: (r) => <div style={{ textAlign: 'right' }}>{formatMoney(r.invoice.amount)}</div> },
    { key: 'top', header: tt('procurement.fields.termsOfPaymentDays'), render: (r) => <div style={{ textAlign: 'right' }}>{r.invoice.terms_of_payment_days ?? '—'}</div> },
    { key: 'date', header: tt('common.fields.invoiceDate'), render: (r) => formatDate(r.invoice.vendor_invoice_date) },
    {
      key: 'due',
      header: tt('procurement.fields.dueDatePaymentDate'),
      render: (r) =>
        r.invoice.payment ? (
          <span>
            {formatDate(r.invoice.due_date)} /{' '}
            <button type="button" className="btn-link" style={{ padding: 0 }} title={tt('procurement.tooltips.viewPaymentProof')} onClick={() => setProofOf(r.invoice)}>
              {formatDate(r.invoice.payment.payment_date)}
            </button>
          </span>
        ) : (
          formatDate(r.invoice.due_date)
        ),
    },
    { key: 'status', header: tt('common.fields.status'), render: (r) => <StatusBadge status={r.invoice.status} /> },
    {
      key: 'action',
      header: tt('common.fields.action'),
      render: (r) =>
        r.invoice.status !== 'PAID' && hasPermission('vendor_invoice.pay') ? (
          <button className="btn-secondary" style={{ padding: '3px 10px', fontSize: 12 }} onClick={() => setPaying(r.invoice)}>
            {tt('procurement.actions.payment')}
          </button>
        ) : null,
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('procurement.titles.vendorInvoiceReferences')}</h1>
      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: -10 }}>
        {tt('procurement.help.recordedWhenGoodsReceivedPurchaseOrder')}
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
          aria-label={tt('procurement.fields.searchVendorInvoices')}
          placeholder={tt('procurement.search.searchGrNumberPoNumberInvoice')}
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
      {!error && !loading && data.length === 0 && <EmptyState label={tt('procurement.empty.noVendorInvoicesRecordedGoodsReceipt')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}

      {paying && (
        <VendorInvoicePaymentModal
          invoice={paying}
          onClose={() => setPaying(null)}
          onPaid={() => {
            setPaying(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {proofOf && <PaymentProofModal invoice={proofOf} onClose={() => setProofOf(null)} />}
    </div>
  );
}
