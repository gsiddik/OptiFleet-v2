import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { WorkshopInvoiceItem } from '../../../types';

/**
 * R1: lists Workshop Invoices OptiFleet has RECORDED — every row is a
 * document the Workshop Partner issued externally, not something created
 * here (see WorkshopInvoiceDetailPage's "Record Workshop Invoice" wording).
 */
export function WorkshopInvoiceListPage() {
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const { data, meta, loading, error } = useApiList<WorkshopInvoiceItem>('/app/workshop-invoices', { status: status || undefined, page, per_page: 20 });

  const filtered = search
    ? data.filter((i) => i.external_invoice_number.toLowerCase().includes(search.toLowerCase()) || (i.partner?.name ?? '').toLowerCase().includes(search.toLowerCase()))
    : data;

  const columns: Column<WorkshopInvoiceItem>[] = [
    { key: 'external_invoice_number', header: 'External Invoice #', render: (i) => <Link to={`/app/workshop-invoices/${i.id}`}>{i.external_invoice_number}</Link> },
    { key: 'partner', header: 'Workshop Partner', render: (i) => i.partner?.name ?? i.partner_id },
    { key: 'work_order', header: 'Work Order', render: (i) => i.work_order?.wo_number ?? i.work_order_id },
    { key: 'invoice_date', header: 'Invoice Date', render: (i) => i.invoice_date },
    { key: 'total_amount', header: 'Total', render: (i) => `${i.total_amount} ${i.currency}` },
    { key: 'memo_status', header: 'Memo Status', render: (i) => <StatusBadge status={i.memo?.status ?? '—'} /> },
    { key: 'status', header: 'Invoice Status', render: (i) => <StatusBadge status={i.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workshop Invoices</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: -8, marginBottom: 16 }}>
        Externally-issued invoices from Workshop Partners, recorded here for tracking, reconciliation, and settlement. Record a new one
        from a Work Order's Maintenance Memo once it is Completed.
      </p>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            style={{ padding: '8px 10px', borderRadius: 6, border: '1px solid #d1d5db' }}
          >
            <option value="">All statuses</option>
            <option value="RECORDED">Recorded</option>
            <option value="CORRECTION_REQUESTED">Correction Requested</option>
            <option value="CANCELLATION_REQUESTED">Cancellation Requested</option>
            <option value="CANCELLED">Cancelled</option>
          </select>
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && filtered.length === 0 && <EmptyState label="No Workshop Invoices recorded yet." />}
      {!error && !loading && filtered.length > 0 && (
        <>
          <Table columns={columns} rows={filtered} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
    </div>
  );
}
