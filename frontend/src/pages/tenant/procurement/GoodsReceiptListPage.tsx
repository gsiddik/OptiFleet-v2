import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { GoodsReceiptItem } from '../../../types';

export function GoodsReceiptListPage() {
  const { data, loading, error } = useApiList<GoodsReceiptItem>('/app/goods-receipts', {}, 0);

  const columns: Column<GoodsReceiptItem>[] = [
    { key: 'number', header: 'GR #', render: (g) => g.gr_number },
    { key: 'po', header: 'Purchase Order', render: (g) => <Link to={`/app/purchase-orders/${g.purchase_order_id}`}>{g.purchase_order?.po_number ?? g.purchase_order_id}</Link> },
    { key: 'warehouse', header: 'Warehouse', render: (g) => g.warehouse?.name ?? g.warehouse_id },
    { key: 'partner', header: 'Vendor', render: (g) => g.partner?.name ?? g.partner_id },
    { key: 'received_at', header: 'Received At', render: (g) => (g.received_at ? new Date(g.received_at).toLocaleString() : '—') },
    { key: 'status', header: 'Status', render: (g) => <StatusBadge status={g.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Goods Receipt</h1>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No goods receipts found. Post one from a Purchase Order." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
