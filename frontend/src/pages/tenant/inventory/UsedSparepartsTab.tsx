import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { Pagination, type PaginationMeta } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { formatQty } from '../../../utils/quantity';

interface UsedSparepartRow {
  id: string;
  category: 'REUSABLE' | 'QUARANTINE' | 'REPAIR_PENDING';
  quantity: string;
  condition: string;
  disposition: string;
  disposition_status: string;
  repaired: boolean;
  product: { id: string; name: string; sku: string | null } | null;
  storage_bin: { id: string; code: string; name: string } | null;
  warehouse: { id: string; code: string; name: string } | null;
  work_order: { id: string; wo_number: string } | null;
  vehicle: { id: string; registration_number: string } | null;
}

const CATEGORIES = [
  { value: '', label: 'All' },
  { value: 'REUSABLE', label: 'Reusable' },
  { value: 'QUARANTINE', label: 'Quarantine' },
  { value: 'REPAIR_PENDING', label: 'Repair pending' },
];

const AVAILABILITY: Record<UsedSparepartRow['category'], { label: string; color: string; note: string }> = {
  REUSABLE: { label: 'Reusable', color: '#047857', note: 'Returned to stock — counted in the product On Hand' },
  QUARANTINE: { label: 'Quarantine', color: '#b91c1c', note: 'Physically held — not available' },
  REPAIR_PENDING: { label: 'Repair pending', color: '#b45309', note: 'Under repair — not available' },
};

/**
 * Warehouse Stock → Used Spareparts: traceability of used parts from Removed Components / Used
 * Sparepart Processing. Reusable quantity is already part of the product's normal stock (one
 * posting); quarantine and repair-pending quantities are shown separately and are never available.
 */
export function UsedSparepartsTab() {
  const [category, setCategory] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [page, setPage] = useState(1);
  const [warehouses, setWarehouses] = useState<{ id: string; name: string }[]>([]);

  useEffect(() => {
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, []);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const { data, meta, loading, error } = useApiList<UsedSparepartRow>('/app/inventory/used-spareparts', {
    category: category || undefined,
    warehouse_id: warehouseId || undefined,
    search: debounced || undefined,
    page,
  });
  const summary = (meta as (PaginationMeta & { summary?: { reusable_qty: number; quarantine_qty: number; repair_pending_qty: number } }) | null)?.summary;

  const columns: Column<UsedSparepartRow>[] = [
    { key: 'product', header: 'Product', render: (r) => r.product?.name ?? '—' },
    { key: 'sku', header: 'Part Code / SKU', render: (r) => r.product?.sku ?? '—' },
    { key: 'quantity', header: 'Quantity', render: (r) => formatQty(r.quantity) },
    { key: 'condition', header: 'Condition', render: (r) => (r.condition === 'USED_FAULTY' ? 'Used — Faulty' : 'Used — Good') },
    { key: 'disposition', header: 'Disposition', render: (r) => (r.repaired ? `REPAIR → ${r.disposition}` : r.disposition) },
    {
      key: 'availability',
      header: 'Availability',
      render: (r) => (
        <span title={AVAILABILITY[r.category].note} style={{ color: AVAILABILITY[r.category].color, fontWeight: 600 }}>
          {AVAILABILITY[r.category].label}
          <div style={{ fontSize: 11, fontWeight: 400, color: '#6b7280' }}>{AVAILABILITY[r.category].note}</div>
        </span>
      ),
    },
    { key: 'status', header: 'Processing Status', render: (r) => <StatusBadge status={r.disposition_status} /> },
    { key: 'warehouse', header: 'Warehouse', render: (r) => r.warehouse?.name ?? '—' },
    { key: 'bin', header: 'Bin', render: (r) => r.storage_bin?.code ?? '—' },
    { key: 'wo', header: 'Origin Work Order', render: (r) => (r.work_order ? <Link to={`/app/work-orders/${r.work_order.id}`}>{r.work_order.wo_number}</Link> : '—') },
    { key: 'vehicle', header: 'Origin Vehicle', render: (r) => r.vehicle?.registration_number ?? '—' },
  ];

  const tile = (label: string, value: number | undefined, color: string) => (
    <div className="card" style={{ padding: '10px 14px', minWidth: 160 }}>
      <div style={{ fontSize: 12, color: '#6b7280' }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 700, color }}>{value === undefined ? '—' : formatQty(value)}</div>
    </div>
  );

  return (
    <div>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 12 }}>
        {tile('Reusable Qty', summary?.reusable_qty, '#047857')}
        {tile('Quarantine Qty (unavailable)', summary?.quarantine_qty, '#b91c1c')}
        {tile('Repair pending Qty (unavailable)', summary?.repair_pending_qty, '#b45309')}
      </div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12, alignItems: 'center' }}>
        {CATEGORIES.map((c) => (
          <button
            key={c.value}
            onClick={() => {
              setCategory(c.value);
              setPage(1);
            }}
            className={category === c.value ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '4px 10px', fontSize: 12 }}
          >
            {c.label}
          </button>
        ))}
        <select
          aria-label="Used spareparts warehouse"
          value={warehouseId}
          onChange={(e) => {
            setWarehouseId(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 200 }}
        >
          <option value="">All warehouses</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
        <input
          aria-label="Search used spareparts"
          placeholder="Search product, WO or vehicle…"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 240 }}
        />
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No used spareparts in Reuse, Quarantine or Repair." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
