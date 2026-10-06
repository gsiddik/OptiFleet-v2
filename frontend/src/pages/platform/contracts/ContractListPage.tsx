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

const TABS: { label: string; status: string }[] = [
  { label: 'All', status: '' },
  { label: 'Draft', status: 'DRAFT' },
  { label: 'Pending Approval', status: 'PENDING_APPROVAL' },
  { label: 'Active', status: 'ACTIVE' },
  { label: 'Expiring', status: 'EXPIRING' },
  { label: 'Expired', status: 'EXPIRED' },
  { label: 'Rejected', status: 'REJECTED' },
  { label: 'Terminated', status: 'TERMINATED' },
];

export function ContractListPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ContractItem>('/platform/contracts', { status: tab || undefined }, reloadKey);

  const columns: Column<ContractItem>[] = [
    { key: 'contract_number', header: 'Contract #', render: (c) => <Link to={`/platform/contracts/${c.id}`}>{c.contract_number}</Link> },
    { key: 'tenant', header: 'Tenant', render: (c) => c.tenant?.name ?? '—' },
    { key: 'billing_cycle', header: 'Cycle', render: (c) => c.billing_cycle },
    { key: 'total', header: 'Total', render: (c) => `${c.currency} ${formatMoney(c.total)}` },
    { key: 'start_date', header: 'Start', render: (c) => c.start_date },
    { key: 'end_date', header: 'End', render: (c) => c.end_date },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Contracts</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button
            key={t.label}
            onClick={() => setTab(t.status)}
            className={tab === t.status ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '6px 12px', fontSize: 13 }}
          >
            {t.label}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('contract.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Contract
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No contracts found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <ContractForm open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}
