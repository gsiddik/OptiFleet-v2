import { useState } from 'react';
import { Table, type Column } from './Table';
import { Toolbar } from './Toolbar';
import { Pagination } from './Pagination';
import { EmptyState, ErrorState, LoadingState } from './States';
import { useApiList } from '../hooks/useApiList';
import { inputStyle } from './FormField';
import type { AuditLogEntry } from '../types';

export function AuditLogTable({ endpoint, showTenantColumn = false }: { endpoint: string; showTenantColumn?: boolean }) {
  const [resourceType, setResourceType] = useState('');
  const [action, setAction] = useState('');
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<AuditLogEntry>(endpoint, { resource_type: resourceType, action, page });

  const columns: Column<AuditLogEntry>[] = [
    { key: 'created_at', header: 'When', render: (l) => new Date(l.created_at).toLocaleString() },
    { key: 'actor_name', header: 'Actor', render: (l) => l.actor_name ?? 'System' },
    ...(showTenantColumn ? [{ key: 'tenant_id', header: 'Tenant', render: (l: AuditLogEntry) => l.tenant_id ?? '—' } as Column<AuditLogEntry>] : []),
    { key: 'resource_type', header: 'Resource', render: (l) => l.resource_type },
    { key: 'action', header: 'Action', render: (l) => l.action },
    {
      key: 'changes',
      header: 'Changes',
      render: (l) => (
        <details>
          <summary style={{ cursor: 'pointer', fontSize: 12, color: '#1d4ed8' }}>view</summary>
          <pre style={{ fontSize: 11, maxWidth: 320, whiteSpace: 'pre-wrap' }}>
            {JSON.stringify({ old: l.old_values, new: l.new_values }, null, 2)}
          </pre>
        </details>
      ),
    },
  ];

  return (
    <div>
      <Toolbar>
        <input
          placeholder="Resource type (e.g. Branch)"
          value={resourceType}
          onChange={(e) => {
            setResourceType(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 180 }}
        />
        <input
          placeholder="Action (e.g. created)"
          value={action}
          onChange={(e) => {
            setAction(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 160 }}
        />
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No audit records found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
    </div>
  );
}
