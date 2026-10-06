import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { PaymentItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { t as tt } from '../../../i18n/i18n';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDate } from '../../../utils/date';

const TABS = ['', 'SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'REJECTED', 'REVERSED'];

export function PaymentListPage() {
  const [tab, setTab] = useState('');
  const { data, loading, error } = useApiList<PaymentItem>('/platform/payments', { status: tab || undefined });

  const columns: Column<PaymentItem>[] = [
    { key: 'tenant', header: tt('common.fields.tenant'), render: (p) => p.tenant?.name ?? '—' },
    { key: 'invoice', header: tt('common.fields.invoiceNumber'), render: (p) => (p.invoice ? <Link to={`/platform/invoices/${p.invoice_id}`}>{p.invoice.invoice_number}</Link> : '—') },
    { key: 'payment_date', header: tt('common.fields.paymentDate'), render: (p) => formatDate(p.payment_date) },
    { key: 'amount', header: tt('common.fields.amount'), render: (p) => formatMoney(p.amount) },
    { key: 'method', header: tt('common.fields.method'), render: (p) => p.payment_method },
    { key: 'status', header: tt('common.fields.status'), render: (p) => <StatusBadge status={p.status} /> },
    { key: 'actions', header: '', render: (p) => <Link to={`/platform/payments/${p.id}`}>{tt('common.actions.view')}</Link> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.payments.titles.payments')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t ? statusLabel(t) : tt('common.actions.all')}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.payments.empty.noPaymentsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
