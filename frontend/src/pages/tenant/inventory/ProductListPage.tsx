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
import type { ProductCategoryItem, ProductItem, UomItem } from '../../../types';

const PRODUCT_TYPES = ['SPARE_PART', 'TOOL', 'TIRE', 'CONSUMABLE', 'EQUIPMENT', 'OTHER'];

export function ProductListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [productType, setProductType] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ProductItem>('/app/products', { search: search || undefined, product_type: productType || undefined }, reloadKey);

  const columns: Column<ProductItem>[] = [
    { key: 'code', header: 'Code', render: (p) => <Link to={`/app/products/${p.id}`}>{p.code}</Link> },
    { key: 'name', header: 'Name', render: (p) => p.name },
    { key: 'sku', header: 'SKU', render: (p) => p.sku },
    { key: 'product_type', header: 'Type', render: (p) => p.product_type },
    { key: 'category', header: 'Category', render: (p) => p.category?.name ?? '—' },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Products</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {['', ...PRODUCT_TYPES].map((t) => (
          <button key={t} onClick={() => setProductType(t)} className={productType === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {t || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('product.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Product
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No products found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateProductModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateProductModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [categories, setCategories] = useState<ProductCategoryItem[]>([]);
  const [uoms, setUoms] = useState<UomItem[]>([]);
  const [code, setCode] = useState('');
  const [sku, setSku] = useState('');
  const [name, setName] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [uomId, setUomId] = useState('');
  const [productType, setProductType] = useState('SPARE_PART');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/product-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
    apiClient.get('/app/uoms', { params: { per_page: 100 } }).then((res) => setUoms(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/products', {
        code, sku, name, product_category_id: categoryId, uom_id: uomId, product_type: productType,
      });
      setCode('');
      setSku('');
      setName('');
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
    <Modal open={open} title="New Product" onClose={onClose}>
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="SKU" errors={errors.sku}>
        <input value={sku} onChange={(e) => setSku(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Category" errors={errors.product_category_id}>
        <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="UOM" errors={errors.uom_id}>
        <select value={uomId} onChange={(e) => setUomId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {uoms.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Product Type" errors={errors.product_type}>
        <select value={productType} onChange={(e) => setProductType(e.target.value)} style={inputStyle}>
          {PRODUCT_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !code || !sku || !name || !categoryId || !uomId} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
