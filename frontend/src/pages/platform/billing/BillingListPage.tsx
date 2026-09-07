import { useState } from 'react';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { BillingItem } from '../../../types';

const TABS = ['', 'DRAFT', 'GENERATED', 'INVOICED', 'PAID', 'PARTIALLY_PAID', 'PAST_DUE', 'CANCELLED'];

export function BillingListPage() {
  const [tab, setTab] = useState('');
  const { data, loading, error } = useApiList<BillingItem>('/platform/billings', { status: tab || undefined });

  const columns: Column<BillingItem>[] = [
    { key: 'tenant', header: 'Tenant', render: (b) => b.tenant?.name ?? '—' },
    { key: 'contract', header: 'Contract #', render: (b) => b.contract?.contract_number ?? '—' },
    { key: 'period', header: 'Period', render: (b) => `${b.billing_period_start} → ${b.billing_period_end}` },
    { key: 'due_date', header: 'Due Date', render: (b) => b.due_date },
    { key: 'total', header: 'Total', render: (b) => Number(b.total).toLocaleString() },
    { key: 'status', header: 'Status', render: (b) => <StatusBadge status={b.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Billing</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No billing records found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
