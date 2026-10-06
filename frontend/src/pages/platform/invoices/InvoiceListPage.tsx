import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { InvoiceItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { t as tt } from '../../../i18n/i18n';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDate } from '../../../utils/date';

const TABS = ['', 'DRAFT', 'ISSUED', 'OUTSTANDING', 'PARTIALLY_PAID', 'PAID', 'OVERDUE', 'VOID'];

export function InvoiceListPage() {
  const [tab, setTab] = useState('');
  const { data, loading, error } = useApiList<InvoiceItem>('/platform/invoices', { status: tab || undefined });

  const columns: Column<InvoiceItem>[] = [
    { key: 'invoice_number', header: tt('common.fields.invoiceNumber'), render: (i) => <Link to={`/platform/invoices/${i.id}`}>{i.invoice_number}</Link> },
    { key: 'tenant', header: tt('common.fields.tenant'), render: (i) => i.tenant?.name ?? '—' },
    { key: 'invoice_date', header: tt('common.fields.invoiceDate'), render: (i) => formatDate(i.invoice_date) },
    { key: 'due_date', header: tt('common.fields.dueDate'), render: (i) => formatDate(i.due_date) },
    { key: 'total', header: tt('common.fields.total'), render: (i) => `${i.currency} ${formatMoney(i.total)}` },
    { key: 'outstanding', header: tt('platform.invoices.fields.outstanding'), render: (i) => formatMoney(i.outstanding_amount) },
    { key: 'status', header: tt('common.fields.status'), render: (i) => <StatusBadge status={i.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.invoices.titles.invoices')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t ? statusLabel(t) : tt('common.actions.all')}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.invoices.empty.noInvoicesFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
