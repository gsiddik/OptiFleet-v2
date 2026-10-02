import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import { CreateProductModal } from '../inventory/CreateProductModal';
import type { TireProductListItem } from '../../../types';

/**
 * Tire List: one row per Tire Product (Product of Item Type TIRE) with the counts of its physical
 * tires — New Stock, Used Stock and Installed — aggregated server-side. "New Tire" creates the
 * Product itself (the same New Product form, opened in the Tire context); physical tires are then
 * registered from the product's Tire Detail → Inventory → New Stock.
 */
export function TireListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, meta, loading, error } = useApiList<TireProductListItem>('/app/tire-products', { search: search || undefined, page, per_page: 20 }, reloadKey);

  const qty = (n: number) => <span style={{ fontVariantNumeric: 'tabular-nums' }}>{n}</span>;
  const columns: Column<TireProductListItem>[] = [
    { key: 'brand', header: 'Brand', render: (p) => p.brand ?? '—' },
    {
      key: 'name',
      header: 'Product Name',
      render: (p) => (
        <div>
          <div>{p.name}</div>
          {p.tire_size_computed && <div style={{ fontSize: 11, color: '#6b7280' }}>{p.tire_size_computed}</div>}
        </div>
      ),
    },
    { key: 'rim', header: 'Rim Diameter', render: (p) => (p.rim_diameter_inch != null ? `${p.rim_diameter_inch}"` : '—') },
    { key: 'new', header: 'New Stock Qty', render: (p) => qty(p.new_qty) },
    { key: 'used', header: 'Used Stock Qty', render: (p) => qty(p.used_qty) },
    { key: 'installed', header: 'Installed Stock Qty', render: (p) => qty(p.installed_qty) },
    { key: 'action', header: 'Action', render: (p) => <Link to={`/app/tires/products/${p.id}`}>View Detail</Link> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Tires</h1>
      <Toolbar
        search={search}
        onSearchChange={(value) => {
          setSearch(value);
          setPage(1);
        }}
        actions={
          hasPermission('product.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              New Tire
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No tire products found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
      {showCreate && <CreateProductModal context="TIRE" open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />}
    </div>
  );
}
