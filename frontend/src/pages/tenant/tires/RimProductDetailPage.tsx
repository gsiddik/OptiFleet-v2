import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ExcelImportModal } from '../../../components/ExcelImportModal';
import { Pagination } from '../../../components/Pagination';
import { ScrollTable, type ScrollColumn } from '../../../components/ScrollTable';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useApiList } from '../../../hooks/useApiList';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { ProductDetailsSection } from '../inventory/ProductDetailsSection';
import { formatDate } from '../../../utils/date';
import { RegisterRimModal, type RimRegistrationMode } from './RegisterRimModal';
import type { ProductItem, RimInventoryCategory, RimInventoryRow, RimInventorySummary } from '../../../types';
import { t } from '../../../i18n/i18n';

type RimProductDetail = ProductItem & { inventory: RimInventorySummary; deleted_at?: string | null };

/**
 * Rim Detail: one Rim Product — its product Details (the same section as Product Detail) and its
 * serial-numbered rims. Rims are mostly already on vehicles, so Installed comes first and is the
 * primary place to Register / Import rims (Serial Number + Vehicle + Position); New Stock and Used Stock
 * are usually small. Counts and tables come from one server-side classification.
 */
export function RimProductDetailPage() {
  const { productId = '' } = useParams<{ productId: string }>();
  const { hasPermission } = useAuth();
  const [product, setProduct] = useState<RimProductDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [registering, setRegistering] = useState<RimRegistrationMode | null>(null);
  const [importing, setImporting] = useState<RimRegistrationMode | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  const load = useCallback(() => {
    apiClient
      .get(`/app/rim-products/${productId}`)
      .then((res) => setProduct(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [productId]);
  useEffect(load, [load]);
  useBreadcrumbLabel(product?.id, product?.name);

  if (error && !product) return <ErrorState message={error} />;
  if (!product) return <LoadingState />;

  const refresh = () => {
    load();
    setReloadKey((k) => k + 1);
  };
  const canManage = hasPermission('rim.manage') && !product.deleted_at;
  const actions = (mode: RimRegistrationMode) =>
    canManage ? (
      <span style={{ display: 'flex', gap: 6 }}>
        <button className="btn-secondary" style={{ padding: '4px 10px', fontSize: 12 }} onClick={() => setImporting(mode)} data-import-open={mode}>
          {t('rim.actions.importRim')}
        </button>
        <button className="btn-primary" style={{ padding: '4px 10px', fontSize: 12 }} onClick={() => setRegistering(mode)} data-register-open={mode}>
          {t('rim.actions.registerRim')}
        </button>
      </span>
    ) : null;
  const serial = (r: RimInventoryRow) => <span style={{ fontFamily: 'monospace' }}>{r.serial_number ?? r.asset_number ?? '—'}</span>;

  return (
    <div>
      <BackButton fallbackTo="/app/rims" label={t('rim.actions.backToRims')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {product.brand ? `${product.brand} — ` : ''}
          {product.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({product.code})</span>
        </h1>
        <StatusBadge status={product.deleted_at ? 'DELETED' : product.status} />
      </div>
      {product.deleted_at && <p style={{ fontSize: 13, color: '#92400e' }}>{t('rim.help.productDeleted')}</p>}

      <ProductDetailsSection product={product} onChanged={load} />

      <h2 style={{ fontSize: 17, margin: '20px 0 10px' }}>{t('tire.sections.inventory')}</h2>
      <InventoryCard title={t('tire.sections.installed')} count={product.inventory.installed_qty} action={actions('INSTALLED')}>
        <CategoryTable
          productId={productId}
          category="INSTALLED"
          reloadKey={reloadKey}
          columns={[
            { header: t('common.fields.serialNumber'), cell: serial },
            { header: t('tire.fields.registration'), cell: (r) => (r.vehicle_id ? <Link to={`/app/vehicles/${r.vehicle_id}`}>{r.registration_number ?? '—'}</Link> : '—') },
            { header: t('tire.fields.position'), cell: (r) => r.position_code ?? '—' },
            { header: t('rim.fields.installedOn'), cell: (r) => (r.installed_at ? formatDate(r.installed_at) : '—') },
          ]}
        />
      </InventoryCard>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 380px), 1fr))', gap: 16, marginTop: 16 }}>
        <InventoryCard title={t('tire.sections.newStock')} count={product.inventory.new_qty} action={actions('NEW_STOCK')}>
          <CategoryTable
            productId={productId}
            category="NEW"
            reloadKey={reloadKey}
            columns={[
              { header: t('common.fields.serialNumber'), cell: serial },
              { header: t('common.fields.warehouse'), cell: (r) => r.warehouse_name ?? '—' },
              { header: t('tire.fields.purchaseDate'), cell: (r) => (r.purchase_date ? formatDate(r.purchase_date) : '—') },
            ]}
          />
        </InventoryCard>
        <InventoryCard title={t('rim.sections.usedStock')} count={product.inventory.used_qty}>
          <CategoryTable
            productId={productId}
            category="USED"
            reloadKey={reloadKey}
            columns={[
              { header: t('common.fields.serialNumber'), cell: serial },
              { header: t('common.fields.status'), cell: (r) => <StatusBadge status={r.current_status} /> },
              { header: t('common.fields.warehouse'), cell: (r) => r.warehouse_name ?? '—' },
            ]}
          />
        </InventoryCard>
      </div>

      {registering && <RegisterRimModal product={product} initialMode={registering} onClose={() => setRegistering(null)} onRegistered={refresh} />}
      {importing && (
        <ExcelImportModal
          title={importing === 'INSTALLED' ? t('rim.modals.importInstalled') : t('rim.modals.importNewStock')}
          intro={importing === 'INSTALLED' ? t('rim.help.importInstalledIntro', { productName: product.name }) : t('rim.help.importNewStockIntro', { productName: product.name })}
          templatePath={`/app/rim-products/${product.id}/import-template`}
          templateFilename={`rim-${importing === 'INSTALLED' ? 'installed' : 'new-stock'}-${product.code ?? 'template'}.xlsx`}
          previewPath={`/app/rim-products/${product.id}/import/preview`}
          importPath={`/app/rim-products/${product.id}/import`}
          query={{ mode: importing }}
          onClose={() => setImporting(null)}
          onImported={refresh}
        />
      )}
    </div>
  );
}

function InventoryCard({ title, count, action, children }: { title: string; count: number; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="card" style={{ minWidth: 0 }} data-inventory-section={title}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, marginBottom: 8, flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0, fontSize: 15 }}>
          {title} <span style={{ color: '#6b7280', fontWeight: 400 }} data-inventory-count>({count})</span>
        </h3>
        {action}
      </div>
      {children}
    </section>
  );
}

const PAGE_SIZE = 25;

function CategoryTable({ productId, category, reloadKey, columns }: { productId: string; category: RimInventoryCategory; reloadKey: number; columns: ScrollColumn<RimInventoryRow>[] }) {
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<RimInventoryRow>(`/app/rim-products/${productId}/inventory`, { category, page, per_page: PAGE_SIZE }, reloadKey);

  return (
    <div data-inventory-paged={category}>
      <ScrollTable dataAttr={category} columns={columns} rows={data} rowKey={(r) => r.id} maxRows={8} emptyLabel={loading ? t('tire.empty.loading') : t('rim.empty.noRims')} />
      {error && <ErrorState message={error} />}
      {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
