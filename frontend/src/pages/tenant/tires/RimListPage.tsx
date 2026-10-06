import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import { CreateProductModal } from '../inventory/CreateProductModal';
import { ProductImportModal } from '../inventory/ProductImportModal';
import type { RimProductListItem } from '../../../types';
import { t } from '../../../i18n/i18n';

/**
 * Tire Management → Rim: one row per Rim Product (Product of Item Type RIM) with its rim specification
 * (only fields the Rim specification actually has) and the counts of its serial-numbered rims. "New
 * Rim" opens the New Product form in the Rim context (Item Type RIM, Wheel & Tyre System, serial
 * tracking — all locked); physical rims are registered from the product's Rim Detail.
 */
export function RimListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const { data, meta, loading, error } = useApiList<RimProductListItem>('/app/rim-products', { search: search || undefined, page, per_page: 20 }, reloadKey);
  // Rims recorded in the earlier standalone Rim catalog stay reachable (read / edit) — never migrated silently.
  const legacy = useApiList<{ id: string }>('/app/rims', { per_page: 1 }, 0);

  const qty = (n: number) => <span style={{ fontVariantNumeric: 'tabular-nums' }}>{n}</span>;
  const inch = (v: string | null) => (v != null ? `${Number(v)}"` : '—');
  const mm = (v: string | null) => (v != null ? t('rim.fields.valueMm', { value: Number(v) }) : '—');
  const columns: Column<RimProductListItem>[] = [
    { key: 'sku', header: t('inventory.fields.sku'), render: (p) => <span style={{ fontFamily: 'monospace', fontSize: 12 }}>{p.sku ?? '—'}</span> },
    {
      key: 'name',
      header: t('procurement.fields.productName'),
      render: (p) => (
        <Link to={`/app/rims/products/${p.id}`} className="entity-link" data-product-link={p.id}>
          {p.name}
        </Link>
      ),
    },
    { key: 'brand', header: t('common.fields.brand'), render: (p) => p.brand ?? '—' },
    { key: 'model', header: t('common.fields.model'), render: (p) => p.model ?? '—' },
    { key: 'type', header: t('rim.fields.rimType'), render: (p) => (p.rim_type ? t(`rim.rimTypes.${p.rim_type.toLowerCase()}`) : '—') },
    { key: 'size', header: t('rim.fields.diameterXWidth'), render: (p) => `${inch(p.diameter_inch)} × ${inch(p.width_inch)}` },
    { key: 'pcd', header: t('rim.fields.boltPatternPcd'), render: (p) => (p.bolt_holes ? `${p.bolt_holes} × ${p.pcd_mm != null ? Number(p.pcd_mm) : '—'}` : '—') },
    { key: 'offset', header: t('rim.fields.offset'), render: (p) => mm(p.offset_mm) },
    { key: 'bore', header: t('rim.fields.centerBore'), render: (p) => mm(p.center_bore_mm) },
    { key: 'material', header: t('inventory.fields.material'), render: (p) => p.material ?? '—' },
    { key: 'status', header: t('common.fields.status'), render: (p) => <StatusBadge status={p.status} /> },
    { key: 'installed', header: t('tire.sections.installed'), render: (p) => qty(p.installed_qty) },
    { key: 'new', header: t('tire.sections.newStock'), render: (p) => qty(p.new_qty) },
    { key: 'used', header: t('rim.sections.usedStock'), render: (p) => qty(p.used_qty) },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('tire.titles.rim')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(value) => {
          setSearch(value);
          setPage(1);
        }}
        actions={
          hasPermission('product.create') ? (
            <span style={{ display: 'flex', gap: 8 }}>
              <button className="btn-secondary" onClick={() => setShowImport(true)} data-rim-product-import>
                {t('productImport.actions.importProducts')}
              </button>
              <button className="btn-primary" onClick={() => setShowCreate(true)} data-new-rim>
                {t('rim.actions.newRim')}
              </button>
            </span>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('rim.empty.noRimProducts')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}
      {(legacy.meta?.total ?? 0) > 0 && (
        <p style={{ fontSize: 12, color: '#6b7280', marginTop: 12 }} data-legacy-rim-catalog>
          {t('rim.help.legacyCatalog', { count: legacy.meta?.total ?? 0 })} <Link to="/app/rims/catalog">{t('rim.actions.openLegacyCatalog')}</Link>
        </p>
      )}
      {showImport && <ProductImportModal initialItemType="RIM" locked onClose={() => setShowImport(false)} onImported={() => setReloadKey((k) => k + 1)} />}
      {showCreate && <CreateProductModal context="RIM" open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />}
    </div>
  );
}
