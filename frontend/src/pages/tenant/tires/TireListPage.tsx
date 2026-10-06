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
import { t } from '../../../i18n/i18n';

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
    { key: 'brand', header: t('common.fields.brand'), render: (p) => p.brand ?? '—' },
    {
      key: 'name',
      header: t('procurement.fields.productName'),
      render: (p) => (
        <div>
          {/* The product name opens its Tire Detail (no separate View Detail action). */}
          <Link to={`/app/tires/products/${p.id}`} className="entity-link" data-product-link={p.id}>
            {p.name}
          </Link>
          {p.tire_size_computed && <div style={{ fontSize: 11, color: '#6b7280' }}>{p.tire_size_computed}</div>}
        </div>
      ),
    },
    { key: 'rim', header: t('inventory.fields.rimDiameter'), render: (p) => (p.rim_diameter_inch != null ? `${p.rim_diameter_inch}"` : '—') },
    { key: 'new', header: t('tire.fields.newStockQty'), render: (p) => qty(p.new_qty) },
    { key: 'used', header: t('tire.fields.usedStockQty'), render: (p) => qty(p.used_qty) },
    { key: 'reusable', header: t('tire.fields.reusableReuse'), render: (p) => qty(p.reusable_qty) },
    { key: 'installed', header: t('tire.fields.installedStockQty'), render: (p) => qty(p.installed_qty) },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('tire.titles.tires')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(value) => {
          setSearch(value);
          setPage(1);
        }}
        actions={
          hasPermission('product.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('tire.actions.newTire')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('tire.empty.noTireProductsFound')} />}
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
