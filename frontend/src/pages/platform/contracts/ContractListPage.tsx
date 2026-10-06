import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ContractItem } from '../../../types';
import { ContractForm } from './ContractForm';
import { formatMoney } from '../../../utils/money';
import { labelText, t as tt } from '../../../i18n/i18n';
import { billingCycleLabel } from './ContractForm';
import { formatDate } from '../../../utils/date';

const TABS: { label: string; labelKey?: string; status: string }[] = [
  { label: 'All', labelKey: 'platform.contracts.sections.all', status: '' },
  { label: 'Draft', labelKey: 'platform.contracts.sections.draft', status: 'DRAFT' },
  { label: 'Pending Approval', labelKey: 'platform.contracts.sections.pendingApproval', status: 'PENDING_APPROVAL' },
  { label: 'Active', labelKey: 'platform.contracts.sections.active', status: 'ACTIVE' },
  { label: 'Expiring', labelKey: 'platform.contracts.sections.expiring', status: 'EXPIRING' },
  { label: 'Expired', labelKey: 'platform.contracts.sections.expired', status: 'EXPIRED' },
  { label: 'Rejected', labelKey: 'platform.contracts.sections.rejected', status: 'REJECTED' },
  { label: 'Terminated', labelKey: 'platform.contracts.sections.terminated', status: 'TERMINATED' },
];

export function ContractListPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ContractItem>('/platform/contracts', { status: tab || undefined }, reloadKey);

  const columns: Column<ContractItem>[] = [
    { key: 'contract_number', header: tt('common.fields.contractNumber'), render: (c) => <Link to={`/platform/contracts/${c.id}`}>{c.contract_number}</Link> },
    { key: 'tenant', header: tt('common.fields.tenant'), render: (c) => c.tenant?.name ?? '—' },
    { key: 'billing_cycle', header: tt('common.fields.cycle'), render: (c) => billingCycleLabel(c.billing_cycle) },
    { key: 'total', header: tt('common.fields.total'), render: (c) => `${c.currency} ${formatMoney(c.total)}` },
    { key: 'start_date', header: tt('common.fields.start'), render: (c) => formatDate(c.start_date) },
    { key: 'end_date', header: tt('common.fields.end'), render: (c) => formatDate(c.end_date) },
    { key: 'status', header: tt('common.fields.status'), render: (c) => <StatusBadge status={c.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.contracts.titles.contracts')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button
            key={labelText(t)}
            onClick={() => setTab(t.status)}
            className={tab === t.status ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '6px 12px', fontSize: 13 }}
          >
            {labelText(t)}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('contract.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('platform.contracts.actions.newContract')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.contracts.empty.noContractsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <ContractForm open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}
