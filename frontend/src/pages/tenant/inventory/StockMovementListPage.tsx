import { useState } from 'react';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { Pagination } from '../../../components/Pagination';
import { useApiList } from '../../../hooks/useApiList';
import type { StockMovementItem } from '../../../types';

const MOVEMENT_TYPES = [
  '', 'OPENING', 'RECEIPT', 'RESERVATION', 'RELEASE_RESERVATION', 'ISSUE', 'RETURN',
  'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_MINUS', 'STOCK_OPNAME', 'SCRAP',
];

export function StockMovementListPage() {
  const [movementType, setMovementType] = useState('');
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<StockMovementItem>('/app/stock-movements', { movement_type: movementType || undefined, page }, 0);

  const columns: Column<StockMovementItem>[] = [
    { key: 'occurred_at', header: 'Date', render: (m) => new Date(m.occurred_at).toLocaleString() },
    { key: 'warehouse', header: 'Warehouse', render: (m) => m.warehouse?.name ?? m.warehouse_id },
    { key: 'product', header: 'Product', render: (m) => m.product?.name ?? m.product_id },
    { key: 'type', header: 'Type', render: (m) => <StatusBadge status={m.movement_type} /> },
    { key: 'quantity', header: 'Quantity', render: (m) => m.quantity },
    { key: 'unit_cost', header: 'Unit Cost', render: (m) => m.unit_cost ?? '—' },
    { key: 'reason', header: 'Reason', render: (m) => m.reason ?? '—' },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Stock Movement Ledger</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {MOVEMENT_TYPES.map((t) => (
          <button key={t} onClick={() => { setMovementType(t); setPage(1); }} className={movementType === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {t || 'All'}
          </button>
        ))}
      </div>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No movements found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
    </div>
  );
}
