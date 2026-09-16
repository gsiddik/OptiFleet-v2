import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { StatusBadge } from '../../../../components/StatusBadge';
import { Table, type Column } from '../../../../components/Table';
import { Pagination } from '../../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../../components/States';
import { useApiList } from '../../../../hooks/useApiList';
import { useAuth } from '../../../../auth/AuthContext';
import type { ContractItem } from '../../../../types';
import { ContractForm } from '../../contracts/ContractForm';

/**
 * Section 6.1: contracts belonging only to the tenant currently open in
 * Tenant Detail. Filtering happens server-side via the existing
 * GET /platform/contracts?tenant_id=... query param (ContractController
 * already scopes it) — this tab never fetches all contracts and filters
 * them in the browser, and tenant_id here is a fixed prop, not something
 * derived from anything the user could edit.
 */
export function TenantContractTab({ tenantId, tenantName, tenantCode }: { tenantId: string; tenantName: string; tenantCode: string }) {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, meta, loading, error } = useApiList<ContractItem>(
    '/platform/contracts',
    { tenant_id: tenantId, page, per_page: 10 },
    reloadKey,
  );

  const columns: Column<ContractItem>[] = [
    { key: 'contract_number', header: 'Contract #', render: (c) => c.contract_number },
    { key: 'billing_cycle', header: 'Cycle', render: (c) => c.billing_cycle },
    { key: 'total', header: 'Total', render: (c) => `${c.currency} ${Number(c.total).toLocaleString()}` },
    { key: 'start_date', header: 'Start', render: (c) => c.start_date },
    { key: 'end_date', header: 'End', render: (c) => c.end_date },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
    {
      key: 'actions',
      header: '',
      render: (c) => (
        <button className="btn-link" onClick={() => navigate(`/platform/contracts/${c.id}?fromTenant=${tenantId}`)}>
          View
        </button>
      ),
    },
  ];

  return (
    <div>
      {hasPermission('contract.create') && (
        <div style={{ marginBottom: 12, textAlign: 'right' }}>
          <button className="btn-primary" onClick={() => setShowCreate(true)}>
            + Add New Contract
          </button>
        </div>
      )}

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No contracts for this tenant yet." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <ContractForm
        open={showCreate}
        onClose={() => setShowCreate(false)}
        onCreated={() => setReloadKey((k) => k + 1)}
        lockedTenant={{ id: tenantId, name: tenantName, code: tenantCode }}
      />
    </div>
  );
}
