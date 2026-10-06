import { useEffect, useState } from 'react';
import { apiClient } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { Pagination } from '../../../components/Pagination';
import { useApiList } from '../../../hooks/useApiList';
import type { StockMovementItem, ProductItem, Warehouse } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { formatDateTime } from '../../../utils/date';
import { t as tt } from '../../../i18n/i18n';

const MOVEMENT_TYPES = [
  '', 'OPENING', 'RECEIPT', 'RESERVATION', 'RELEASE_RESERVATION', 'ISSUE', 'RETURN',
  'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_MINUS', 'STOCK_OPNAME', 'SCRAP', 'CONSUME',
];

// G-21: signed weight of each movement type against on-hand balance, mirroring
// InventoryService's own writeMovement semantics — used only to render a running
// balance column client-side; it never mutates or recomputes server-held stock.
const BALANCE_WEIGHT: Record<string, 1 | -1 | 0> = {
  OPENING: 1, RECEIPT: 1, RETURN: 1, TRANSFER_IN: 1, ADJUSTMENT_PLUS: 1,
  ISSUE: -1, TRANSFER_OUT: -1, ADJUSTMENT_MINUS: -1, SCRAP: -1,
  RESERVATION: 0, RELEASE_RESERVATION: 0, STOCK_OPNAME: 0, CONSUME: 0,
};

export function StockMovementListPage() {
  const [movementType, setMovementType] = useState('');
  const [productId, setProductId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [page, setPage] = useState(1);
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const isDrilldown = Boolean(productId || warehouseId);

  const { data, meta, loading, error } = useApiList<StockMovementItem>(
    '/app/stock-movements',
    {
      movement_type: movementType || undefined,
      product_id: productId || undefined,
      warehouse_id: warehouseId || undefined,
      page,
      // Drilldown mode widens the page so the running balance below is accurate for
      // realistic history lengths without needing a server-computed opening balance.
      per_page: isDrilldown ? 200 : undefined,
    },
    0,
  );

  useEffect(() => {
    apiClient.get('/app/products', { params: { per_page: 100 } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, []);

  // Rows arrive newest-first; render the card oldest-first with a running balance,
  // per the per-item/per-warehouse "Inventory Card" drilldown this Phase adds (G-21).
  const chronological = isDrilldown ? [...data].reverse() : data;
  let runningBalance = 0;
  const withBalance = chronological.map((m) => {
    runningBalance += (BALANCE_WEIGHT[m.movement_type] ?? 0) * Number(m.quantity);
    return { ...m, __balance: runningBalance };
  });
  const rows = isDrilldown ? [...withBalance].reverse() : withBalance;

  const columns: Column<StockMovementItem & { __balance: number }>[] = [
    { key: 'occurred_at', header: tt('common.fields.date'), render: (m) => formatDateTime(m.occurred_at) },
    { key: 'warehouse', header: tt('common.fields.warehouse'), render: (m) => m.warehouse?.name ?? m.warehouse_id },
    { key: 'product', header: tt('common.fields.product'), render: (m) => m.product?.name ?? m.product_id },
    { key: 'type', header: tt('common.fields.type'), render: (m) => <StatusBadge status={m.movement_type} /> },
    { key: 'quantity', header: tt('common.fields.quantity'), render: (m) => (BALANCE_WEIGHT[m.movement_type] === -1 ? '-' : BALANCE_WEIGHT[m.movement_type] === 1 ? '+' : '') + formatQty(m.quantity) },
    ...(isDrilldown ? [{ key: 'balance', header: tt('inventory.fields.runningBalance'), render: (m: StockMovementItem & { __balance: number }) => m.__balance.toFixed(4) } as Column<StockMovementItem & { __balance: number }>] : []),
    { key: 'unit_cost', header: tt('inventory.fields.unitCost'), render: (m) => formatMoney(m.unit_cost) },
    { key: 'reason', header: tt('common.fields.reason'), render: (m) => m.reason ?? '—' },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{isDrilldown ? tt('inventory.titles.inventoryCard') : tt('inventory.titles.stockMovementLedger')}</h1>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0, marginBottom: 16 }}>
        {isDrilldown
          ? tt('inventory.help.perItemPerWarehouseMovementHistory')
          : tt('inventory.help.filterProductWarehouseBelowPerItem')}
      </p>
      <div style={{ display: 'flex', gap: 8, marginBottom: 10, flexWrap: 'wrap' }}>
        <select value={productId} onChange={(e) => { setProductId(e.target.value); setPage(1); }} style={{ padding: '6px 8px', fontSize: 12, minWidth: 200 }}>
          <option value="">{tt('inventory.filters.allProducts')}</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
        <select value={warehouseId} onChange={(e) => { setWarehouseId(e.target.value); setPage(1); }} style={{ padding: '6px 8px', fontSize: 12, minWidth: 200 }}>
          <option value="">{tt('inventory.filters.allWarehouses')}</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
        {isDrilldown && (
          <button className="btn-secondary" onClick={() => { setProductId(''); setWarehouseId(''); setPage(1); }} style={{ fontSize: 12 }}>
            {tt('inventory.actions.clearDrilldown')}
          </button>
        )}
      </div>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {MOVEMENT_TYPES.map((t) => (
          <button key={t} onClick={() => { setMovementType(t); setPage(1); }} className={movementType === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {t || tt('common.actions.all')}
          </button>
        ))}
      </div>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('inventory.empty.noMovementsFound')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={rows} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
    </div>
  );
}
