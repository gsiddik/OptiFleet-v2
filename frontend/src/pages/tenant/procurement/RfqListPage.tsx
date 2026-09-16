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
import type { ProductItem, RfqItem, Warehouse } from '../../../types';

const STATUSES = ['', 'DRAFT', 'ISSUED', 'CLOSED', 'CANCELLED'];

export function RfqListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<RfqItem>('/app/rfqs', { status: status || undefined }, reloadKey);

  const columns: Column<RfqItem>[] = [
    { key: 'number', header: 'RFQ #', render: (r) => <Link to={`/app/rfqs/${r.id}`}>{r.rfq_number}</Link> },
    { key: 'warehouse', header: 'Warehouse', render: (r) => r.warehouse?.name ?? r.warehouse_id },
    { key: 'vendors', header: 'Vendors Invited', render: (r) => r.vendors?.length ?? 0 },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>RFQs</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('rfq.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New RFQ
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No RFQs found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateRfqModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateRfqModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
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
      await apiClient.post('/app/rfqs', { warehouse_id: warehouseId, items: [{ product_id: productId, quantity }] });
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
    <Modal open={open} title="New RFQ" onClose={onClose}>
      <FormField label="Warehouse" errors={errors.warehouse_id} required>
        <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {warehouses.map((w) => (
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
        <input type="number" step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !warehouseId || !productId || !quantity} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
