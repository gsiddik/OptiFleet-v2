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
import type { ProductCategoryItem, ProductItem, PurchaseRequestItem, WorkOrderItem, Warehouse } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';

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
    { key: 'source', header: 'Source', render: (p) => (p.work_order ? `${p.source_type} (${p.work_order.wo_number})` : p.source_type) },
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
  const [categories, setCategories] = useState<ProductCategoryItem[]>([]);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [brandFilter, setBrandFilter] = useState('');
  const [modelFilter, setModelFilter] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [sourceType, setSourceType] = useState<'MANUAL' | 'WORK_ORDER'>('MANUAL');
  const [workOrders, setWorkOrders] = useState<WorkOrderItem[]>([]);
  const [workOrderId, setWorkOrderId] = useState('');
  const [workOrderProducts, setWorkOrderProducts] = useState<ProductItem[]>([]);
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [priority, setPriority] = useState('MEDIUM');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    apiClient.get('/app/product-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data)).catch(() => setCategories([]));
  }, [open]);

  useEffect(() => {
    if (!open) return;
    apiClient
      .get('/app/products', { params: { per_page: 100, product_category_id: categoryFilter || undefined } })
      .then((res) => setProducts(res.data.data))
      .catch(() => setProducts([]));
  }, [open, categoryFilter]);

  useEffect(() => {
    if (!open || sourceType !== 'WORK_ORDER') return;
    apiClient.get('/app/work-orders', { params: { per_page: 50 } }).then((res) => setWorkOrders(res.data.data)).catch(() => setWorkOrders([]));
  }, [open, sourceType]);

  // WO-scoped item picker: once a Work Order is selected, the product list narrows to
  // that WO's own planned parts rather than the full catalog.
  useEffect(() => {
    if (!workOrderId) {
      setWorkOrderProducts([]);
      return;
    }
    apiClient.get(`/app/work-orders/${workOrderId}`).then((res) => {
      const plannedParts = res.data.data.planned_parts ?? [];
      const productIds = new Set(plannedParts.map((p: { product_id: string | null }) => p.product_id).filter(Boolean));
      setWorkOrderProducts(products.filter((p) => productIds.has(p.id)));
    }).catch(() => setWorkOrderProducts([]));
  }, [workOrderId, products]);

  const filteredProducts = ((sourceType === 'WORK_ORDER' ? workOrderProducts : products))
    .filter((p) => (brandFilter ? (p.brand ?? '').toLowerCase().includes(brandFilter.toLowerCase()) : true))
    .filter((p) =>
      modelFilter
        ? (p.compatibilities ?? []).some((c) => (c.vehicle_model ?? '').toLowerCase().includes(modelFilter.toLowerCase()))
        : true,
    );

  useEffect(() => {
    if (productId && !filteredProducts.some((p) => p.id === productId)) setProductId('');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredProducts]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/purchase-requests', {
        warehouse_id: warehouseId,
        priority,
        source_type: sourceType,
        work_order_id: sourceType === 'WORK_ORDER' ? workOrderId : undefined,
        items: [{ product_id: productId, requested_quantity: quantity }],
      });
      setQuantity('');
      setWorkOrderId('');
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
      <FormField label="Source">
        <select
          value={sourceType}
          onChange={(e) => {
            setSourceType(e.target.value as 'MANUAL' | 'WORK_ORDER');
            setWorkOrderId('');
            setProductId('');
          }}
          style={inputStyle}
        >
          <option value="MANUAL">Manual</option>
          <option value="WORK_ORDER">Work Order</option>
        </select>
      </FormField>
      {sourceType === 'WORK_ORDER' && (
        <FormField label="Work Order" errors={errors.work_order_id}>
          <select value={workOrderId} onChange={(e) => setWorkOrderId(e.target.value)} style={inputStyle}>
            <option value="">Select…</option>
            {workOrders.map((w) => (
              <option key={w.id} value={w.id}>
                {w.wo_number}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Category (filter)">
          <select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)} style={inputStyle}>
            <option value="">All categories</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Brand (filter)">
          <input value={brandFilter} onChange={(e) => setBrandFilter(e.target.value)} placeholder="e.g. Bosch" style={inputStyle} />
        </FormField>
        <FormField label="Model Compatibility (filter)">
          <input value={modelFilter} onChange={(e) => setModelFilter(e.target.value)} placeholder="e.g. Dutro" style={inputStyle} />
        </FormField>
      </div>
      <FormField label="Product" errors={errors['items.0.product_id']} required>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {filteredProducts.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
              {p.brand ? ` (${p.brand})` : ''}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Requested Quantity" errors={errors['items.0.requested_quantity']} required>
        <NumericInput step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
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
        <button
          className="btn-primary"
          disabled={submitting || !warehouseId || !productId || !quantity || (sourceType === 'WORK_ORDER' && !workOrderId)}
          onClick={submit}
        >
          Create
        </button>
      </div>
    </Modal>
  );
}
