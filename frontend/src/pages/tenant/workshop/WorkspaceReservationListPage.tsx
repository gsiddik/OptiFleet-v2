import { useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { TransferWorkspaceModal } from '../workorders/workspace/ScheduleWorkspaceModal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WorkspaceReservationItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';

// RESERVED = requested; ACTIVE is legacy (counts as approved). Completion comes only from the Work Order.
const STATUSES = ['', 'RESERVED', 'APPROVED', 'ACTIVE', 'TRANSFERRED', 'COMPLETED', 'CANCELLED'];

export function WorkspaceReservationListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [transferring, setTransferring] = useState<WorkspaceReservationItem | null>(null);
  const { data, loading, error: listError } = useApiList<WorkspaceReservationItem>('/app/workspace-reservations', { status: status || undefined }, reloadKey);

  async function act(id: string, action: 'approve' | 'cancel') {
    setBusyId(id);
    setError(null);
    try {
      await apiClient.post(`/app/workspace-reservations/${id}/${action}`);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setError(extractApiError(err).message);
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
    { key: 'approved', header: 'Approved', render: (r) => (r.approved_at ? `${new Date(r.approved_at).toLocaleString()}${r.approver ? ` · ${r.approver.name}` : ''}` : '—') },
    {
      key: 'actions',
      header: '',
      render: (r) => (
        <div style={{ display: 'flex', gap: 6 }}>
          {r.status === 'RESERVED' && hasPermission('workspace.approve') && (
            <button className="btn-primary" disabled={busyId === r.id} onClick={() => act(r.id, 'approve')}>
              Approve
            </button>
          )}
          {(r.status === 'APPROVED' || r.status === 'ACTIVE') && r.work_order_id && hasPermission('workspace.approve') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setTransferring(r)}>
              Transfer to Another Workspace
            </button>
          )}
          {['RESERVED', 'APPROVED', 'ACTIVE'].includes(r.status) && hasPermission('workspace.reserve') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'cancel')}>
              Cancel
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workspace Assignments</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && data.length === 0 && <EmptyState label="No reservations found." />}
      {!listError && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {transferring?.work_order_id && (
        <TransferWorkspaceModal
          open
          workOrderId={transferring.work_order_id}
          current={transferring}
          onClose={() => setTransferring(null)}
          onDone={() => setReloadKey((k) => k + 1)}
        />
      )}
    </div>
  );
}
