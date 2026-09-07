import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { PurchaseOrderItem } from '../../../types';

const STATUSES = ['', 'DRAFT', 'SUBMITTED', 'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED'];

export function PurchaseOrderListPage() {
  const [status, setStatus] = useState('');
  const { data, loading, error } = useApiList<PurchaseOrderItem>('/app/purchase-orders', { status: status || undefined }, 0);

  const columns: Column<PurchaseOrderItem>[] = [
    { key: 'number', header: 'PO #', render: (p) => <Link to={`/app/purchase-orders/${p.id}`}>{p.po_number}</Link> },
    { key: 'partner', header: 'Vendor', render: (p) => p.partner?.name ?? p.partner_id },
    { key: 'warehouse', header: 'Delivery Warehouse', render: (p) => p.delivery_warehouse?.name ?? p.delivery_warehouse_id },
    { key: 'total', header: 'Total', render: (p) => p.total },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Purchase Orders</h1>
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
      {!error && !loading && data.length === 0 && <EmptyState label="No purchase orders found. Create one from an RFQ's selected quotation." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
