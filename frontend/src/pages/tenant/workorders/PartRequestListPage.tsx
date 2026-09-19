import { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { PartRequestItem } from '../../../types';

const STATUSES = ['', 'REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED'];

/**
 * Cross-Work-Order approval queue for Phase 5's Request Parts — the
 * per-Work-Order tab (WorkOrderDetailPage's Part Requests tab) is where a
 * mechanic files a request, this page is where a warehouse/supervisor
 * role reviews the queue without hunting through individual Work Orders.
 */
export function PartRequestListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('REQUESTED');
  const [reloadKey, setReloadKey] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const { data, loading, error } = useApiList<PartRequestItem>('/app/part-requests', { status: status || undefined }, reloadKey);

  async function approve(id: string) {
    setBusyId(id);
    try {
      await apiClient.post(`/app/part-requests/${id}/approve`, {});
      setReloadKey((k) => k + 1);
    } finally {
      setBusyId(null);
    }
  }

  async function reject(id: string) {
    const reason = window.prompt('Reason for rejecting this part request:');
    if (!reason) return;
    setBusyId(id);
    try {
      await apiClient.post(`/app/part-requests/${id}/reject`, { reason });
      setReloadKey((k) => k + 1);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<PartRequestItem>[] = [
    {
      key: 'work_order',
      header: 'Work Order',
      render: (r) => (
        <Link to={`/app/work-orders/${r.work_order_id}`}>{r.work_order?.wo_number ?? r.work_order_id}</Link>
      ),
    },
    { key: 'vehicle', header: 'Vehicle', render: (r) => r.work_order?.vehicle?.registration_number ?? '—' },
    {
      key: 'items',
      header: 'Lines',
      render: (r) => (r.items ?? []).map((i) => `${i.description} (${i.quantity_requested})`).join(', ') || '—',
    },
    { key: 'notes', header: 'Notes', render: (r) => r.notes ?? '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions',
      header: '',
      render: (r) =>
        r.status === 'REQUESTED' && (hasPermission('part_request.approve') || hasPermission('part_request.reject')) ? (
          <div style={{ display: 'flex', gap: 6 }}>
            {hasPermission('part_request.approve') && (
              <button className="btn-secondary" disabled={busyId === r.id} onClick={() => approve(r.id)}>
                Approve
              </button>
            )}
            {hasPermission('part_request.reject') && (
              <button className="btn-secondary" disabled={busyId === r.id} onClick={() => reject(r.id)}>
                Reject
              </button>
            )}
          </div>
        ) : null,
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Part Requests</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No part requests found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
