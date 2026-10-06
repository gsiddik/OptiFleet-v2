import { useState } from 'react';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { BillingItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { t as tt } from '../../../i18n/i18n';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDate } from '../../../utils/date';

const TABS = ['', 'DRAFT', 'GENERATED', 'INVOICED', 'PAID', 'PARTIALLY_PAID', 'PAST_DUE', 'CANCELLED'];

export function BillingListPage() {
  const [tab, setTab] = useState('');
  const { data, loading, error } = useApiList<BillingItem>('/platform/billings', { status: tab || undefined });

  const columns: Column<BillingItem>[] = [
    { key: 'tenant', header: tt('common.fields.tenant'), render: (b) => b.tenant?.name ?? '—' },
    { key: 'contract', header: tt('common.fields.contractNumber'), render: (b) => b.contract?.contract_number ?? '—' },
    { key: 'period', header: tt('platform.billing.fields.period'), render: (b) => `${formatDate(b.billing_period_start)} → ${formatDate(b.billing_period_end)}` },
    { key: 'due_date', header: tt('common.fields.dueDate'), render: (b) => formatDate(b.due_date) },
    { key: 'total', header: tt('common.fields.total'), render: (b) => formatMoney(b.total) },
    { key: 'status', header: tt('common.fields.status'), render: (b) => <StatusBadge status={b.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.billing.titles.billing')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button key={t} onClick={() => setTab(t)} className={tab === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t ? statusLabel(t) : tt('common.actions.all')}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.billing.empty.noBillingRecordsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
