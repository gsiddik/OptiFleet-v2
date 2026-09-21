import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ProductItem } from '../../../types';
import { CreateProductModal, ITEM_TYPES as PRODUCT_TYPES } from './CreateProductModal';

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
