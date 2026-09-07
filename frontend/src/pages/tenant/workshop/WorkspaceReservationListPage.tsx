import { useState } from 'react';
import { apiClient } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WorkspaceReservationItem } from '../../../types';

const STATUSES = ['', 'RESERVED', 'ACTIVE', 'COMPLETED', 'CANCELLED'];

export function WorkspaceReservationListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const { data, loading, error } = useApiList<WorkspaceReservationItem>('/app/workspace-reservations', { status: status || undefined }, reloadKey);

  async function act(id: string, action: 'activate' | 'complete' | 'cancel') {
    setBusyId(id);
    try {
      await apiClient.post(`/app/workspace-reservations/${id}/${action}`);
      setReloadKey((k) => k + 1);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<WorkspaceReservationItem>[] = [
    { key: 'workspace', header: 'Workspace', render: (r) => `${r.workspace?.name ?? r.workspace_id} (${r.workspace?.workshop?.name ?? '—'})` },
    { key: 'work_order', header: 'Work Order', render: (r) => r.work_order?.wo_number ?? '—' },
    { key: 'vehicle', header: 'Vehicle', render: (r) => r.work_order?.vehicle?.registration_number ?? '—' },
    { key: 'start_at', header: 'Start', render: (r) => new Date(r.start_at).toLocaleString() },
    { key: 'end_at', header: 'End', render: (r) => new Date(r.end_at).toLocaleString() },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions',
      header: '',
      render: (r) =>
        hasPermission('workspace.reserve') ? (
          <div style={{ display: 'flex', gap: 6 }}>
            {r.status === 'RESERVED' && (
              <>
                <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'activate')}>
                  Activate
                </button>
                <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'cancel')}>
                  Cancel
                </button>
              </>
            )}
            {r.status === 'ACTIVE' && (
              <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'complete')}>
                Complete
              </button>
            )}
          </div>
        ) : null,
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workspace Assignments (Reservations)</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No reservations found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
