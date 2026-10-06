import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ProductItem, StockTransferItem, Warehouse } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { statusLabel } from '../../../i18n/statusRegistry';

const STATUSES = ['', 'DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED', 'DISPATCHED', 'IN_TRANSIT', 'RECEIVED', 'COMPLETED', 'REJECTED', 'CANCELLED'];

export function StockTransferListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<StockTransferItem>('/app/stock-transfers', { status: status || undefined }, reloadKey);

  const columns: Column<StockTransferItem>[] = [
    { key: 'number', header: 'Transfer #', render: (t) => <Link to={`/app/stock-transfers/${t.id}`}>{t.transfer_number}</Link> },
    { key: 'from', header: 'From', render: (t) => t.from_warehouse?.name ?? t.from_warehouse_id },
    { key: 'to', header: 'To', render: (t) => t.to_warehouse?.name ?? t.to_warehouse_id },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Stock Transfers</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s ? statusLabel(s) : 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('stock_transfer.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Transfer
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No transfers found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateTransferModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateTransferModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [fromWarehouseId, setFromWarehouseId] = useState('');
  const [toWarehouseId, setToWarehouseId] = useState('');
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    apiClient.get('/app/products', { params: { per_page: 100 } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/stock-transfers', {
        from_warehouse_id: fromWarehouseId, to_warehouse_id: toWarehouseId,
        items: [{ product_id: productId, quantity }],
      });
      setQuantity('');
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="New Stock Transfer" onClose={onClose}>
      <FormField label="From Warehouse" errors={errors.from_warehouse_id} required>
        <select
          value={fromWarehouseId}
          onChange={(e) => {
            setFromWarehouseId(e.target.value);
            if (e.target.value === toWarehouseId) setToWarehouseId('');
          }}
          style={inputStyle}
        >
          <option value="">Select…</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="To Warehouse" errors={errors.to_warehouse_id} required>
        <select value={toWarehouseId} onChange={(e) => setToWarehouseId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {/* Backend requires a different destination: never offer the source warehouse. */}
          {warehouses.filter((w) => w.id !== fromWarehouseId).map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Product" errors={errors['items.0.product_id']} required>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Quantity" errors={errors['items.0.quantity']} required>
        <NumericInput step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !fromWarehouseId || !toWarehouseId || !productId || !quantity} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
