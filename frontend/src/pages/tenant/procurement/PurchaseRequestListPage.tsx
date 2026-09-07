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
import type { ProductItem, PurchaseRequestItem, Warehouse } from '../../../types';

const STATUSES = ['', 'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'PROCUREMENT', 'REJECTED', 'CANCELLED'];

export function PurchaseRequestListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<PurchaseRequestItem>('/app/purchase-requests', { status: status || undefined }, reloadKey);

  const columns: Column<PurchaseRequestItem>[] = [
    { key: 'number', header: 'PR #', render: (p) => <Link to={`/app/purchase-requests/${p.id}`}>{p.pr_number}</Link> },
    { key: 'warehouse', header: 'Warehouse', render: (p) => p.warehouse?.name ?? p.warehouse_id },
    { key: 'source', header: 'Source', render: (p) => p.source_type },
    { key: 'priority', header: 'Priority', render: (p) => p.priority },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Purchase Requests</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('purchase_request.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Request
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No purchase requests found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreatePrModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreatePrModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [priority, setPriority] = useState('MEDIUM');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/organization/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    apiClient.get('/app/products', { params: { per_page: 100 } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/purchase-requests', {
        warehouse_id: warehouseId, priority, source_type: 'MANUAL',
        items: [{ product_id: productId, requested_quantity: quantity }],
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
    <Modal open={open} title="New Purchase Request" onClose={onClose}>
      <FormField label="Warehouse" errors={errors.warehouse_id}>
        <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Product" errors={errors['items.0.product_id']}>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Requested Quantity" errors={errors['items.0.requested_quantity']}>
        <input type="number" step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Priority" errors={errors.priority}>
        <select value={priority} onChange={(e) => setPriority(e.target.value)} style={inputStyle}>
          {['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
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
