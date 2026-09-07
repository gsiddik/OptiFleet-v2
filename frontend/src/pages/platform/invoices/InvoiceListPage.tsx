import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { InvoiceItem } from '../../../types';

const TABS = ['', 'DRAFT', 'ISSUED', 'OUTSTANDING', 'PARTIALLY_PAID', 'PAID', 'OVERDUE', 'VOID'];

export function InvoiceListPage() {
  const [tab, setTab] = useState('');
  const { data, loading, error } = useApiList<InvoiceItem>('/platform/invoices', { status: tab || undefined });

  const columns: Column<InvoiceItem>[] = [
    { key: 'invoice_number', header: 'Invoice #', render: (i) => <Link to={`/platform/invoices/${i.id}`}>{i.invoice_number}</Link> },
    { key: 'tenant', header: 'Tenant', render: (i) => i.tenant?.name ?? '—' },
    { key: 'invoice_date', header: 'Invoice Date', render: (i) => i.invoice_date },
    { key: 'due_date', header: 'Due Date', render: (i) => i.due_date },
    { key: 'total', header: 'Total', render: (i) => `${i.currency} ${Number(i.total).toLocaleString()}` },
    { key: 'outstanding', header: 'Outstanding', render: (i) => Number(i.outstanding_amount).toLocaleString() },
    { key: 'status', header: 'Status', render: (i) => <StatusBadge status={i.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Invoices</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No invoices found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
