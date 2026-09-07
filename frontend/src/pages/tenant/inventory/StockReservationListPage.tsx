import { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { StockReservationItem } from '../../../types';

const STATUSES = ['', 'DRAFT', 'RESERVED', 'PARTIALLY_RESERVED', 'RELEASED', 'CONSUMED', 'CANCELLED'];

export function StockReservationListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const { data, loading, error } = useApiList<StockReservationItem>('/app/stock-reservations', { status: status || undefined }, reloadKey);

  async function cancel(id: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/stock-reservations/${id}/cancel`);
      setReloadKey((k) => k + 1);
    } finally {
      setBusy(false);
    }
  }

  const columns: Column<StockReservationItem>[] = [
    { key: 'work_order', header: 'Work Order', render: (r) => <Link to={`/app/work-orders/${r.work_order_id}`}>{r.work_order?.wo_number ?? r.work_order_id}</Link> },
    { key: 'warehouse', header: 'Warehouse', render: (r) => r.warehouse?.name ?? r.warehouse_id },
    { key: 'items', header: 'Lines', render: (r) => r.items?.length ?? 0 },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions', header: '', render: (r) => hasPermission('inventory.reserve') && !['RELEASED', 'CONSUMED', 'CANCELLED'].includes(r.status) && (
        <button className="btn-link" disabled={busy} onClick={() => cancel(r.id)}>
          Cancel
        </button>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Stock Reservations</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No reservations found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: 12 }}>
        Reservations are created from a Work Order's Planned Parts tab. Issue and Return actions also live there.
      </p>
    </div>
  );
}
