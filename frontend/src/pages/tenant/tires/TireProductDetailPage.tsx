import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { Pagination, type PaginationMeta } from '../../../components/Pagination';
import { ScrollTable, type ScrollColumn } from '../../../components/ScrollTable';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useApiList } from '../../../hooks/useApiList';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { ProductDetailsSection } from '../inventory/ProductDetailsSection';
import { formatDate } from '../../../utils/date';
import { ImportTiresModal } from './ImportTiresModal';
import { formatHours } from './operations/tireOperationFormat';
import { RegisterTireModal } from './RegisterTireModal';
import type { ProductItem, TireInventoryCategory, TireInventoryRow, TireInventorySummary } from '../../../types';
import { t } from '../../../i18n/i18n';

type TireProductDetail = ProductItem & { inventory: TireInventorySummary; deleted_at?: string | null };

/**
 * Tire Detail: one Tire Product (Product of Item Type TIRE) — its product Details (the same section
 * as Product Detail) and the Inventory of its physical tires: New Stock, Installed and Used Stocks.
 * Counts and tables come from one server-side classification, so they always agree with the Tire
 * List. Installed / Used serials open the physical tire page; New Stock can be registered one by one
 * or imported from the Excel template.
 */
export function TireProductDetailPage() {
  const { productId = '' } = useParams<{ productId: string }>();
  const { hasPermission } = useAuth();
  const [product, setProduct] = useState<TireProductDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [registering, setRegistering] = useState(false);
  const [importing, setImporting] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const load = useCallback(() => {
    apiClient
      .get(`/app/tire-products/${productId}`)
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
  const canRegister = hasPermission('tire.manage') && !product.deleted_at;

  return (
    <div>
      <BackButton fallbackTo="/app/tires" label={t('tire.actions.backToTires')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {product.brand ? `${product.brand} — ` : ''}
          {product.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({product.code})</span>
        </h1>
        <StatusBadge status={product.deleted_at ? 'DELETED' : product.status} />
      </div>
      {product.deleted_at && <p style={{ fontSize: 13, color: '#92400e' }}>{t('tire.help.productDeletedTiresTheirHistoryStay')}</p>}

      <ProductDetailsSection product={product} onChanged={load} />

      <h2 style={{ fontSize: 17, margin: '20px 0 10px' }}>{t('tire.sections.inventory')}</h2>
      <div className="tire-inventory-top" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 380px), 1fr))', gap: 16, marginBottom: 16 }}>
        <InventoryCard
          title={t('tire.sections.newStock')}
          count={product.inventory.new_qty}
          action={
            canRegister ? (
              <span style={{ display: 'flex', gap: 6 }}>
                <button className="btn-secondary" style={{ padding: '4px 10px', fontSize: 12 }} onClick={() => setImporting(true)} data-import-open>
                  {t('tire.actions.import')}
                </button>
                <button className="btn-primary" style={{ padding: '4px 10px', fontSize: 12 }} onClick={() => setRegistering(true)}>
                  {t('tire.actions.registerTire')}
                </button>
              </span>
            ) : null
          }
        >
          <ScrollingInventory
            productId={productId}
            category="NEW"
            reloadKey={reloadKey}
            columns={[
              // New stock has no operation history yet: the serial is plain text (Installed / Used link to the tire).
              { header: t('tire.fields.serial'), cell: (r) => <span style={{ fontFamily: 'monospace' }}>{r.serial_number}</span> },
              { header: t('tire.fields.manufactureDateCode'), cell: (r) => r.manufacture_date_code ?? '—' },
              { header: t('tire.fields.purchaseDate'), cell: (r) => (r.purchase_date ? formatDate(r.purchase_date) : '—') },
              { header: t('common.fields.status'), cell: (r) => <StatusBadge status={r.current_status} /> },
            ]}
          />
        </InventoryCard>
        <InventoryCard title={t('tire.sections.installed')} count={product.inventory.installed_qty}>
          <ScrollingInventory
            productId={productId}
            category="INSTALLED"
            reloadKey={reloadKey}
            columns={[
              { header: t('tire.fields.serial'), cell: (r) => <SerialLink row={r} /> },
              { header: t('tire.fields.registration'), cell: (r) => (r.vehicle_id ? <Link to={`/app/vehicles/${r.vehicle_id}`}>{r.registration_number ?? '—'}</Link> : '—') },
              { header: t('tire.fields.currentKm'), cell: (r) => <Num value={r.current_odometer} /> },
            ]}
          />
        </InventoryCard>
      </div>
      <InventoryCard
        title={t('tire.sections.usedStocks')}
        count={product.inventory.used_qty}
        action={
          <span data-reusable-count style={{ fontSize: 12, color: '#166534' }} title={t('tire.tooltips.onlyReuseTiresAvailableInstallationRemoved')}>
            {product.inventory.reusable_qty} reusable (REUSE)
          </span>
        }
      >
        <PagedInventory
          productId={productId}
          reloadKey={reloadKey}
          columns={[
            { header: t('tire.fields.serial'), cell: (r) => <SerialLink row={r} /> },
            { header: t('common.fields.status'), cell: (r) => <StatusBadge status={r.current_status} /> },
            { header: t('tire.fields.usageKm'), cell: (r) => <Num value={r.usage_km} /> },
            { header: t('tire.fields.usageTimeHoursMeter'), cell: (r) => formatHours(r.usage_hours) },
            { header: t('tire.fields.currentTreadDepth'), cell: (r) => (r.current_tread_depth_mm != null ? t('tire.help.dPullMmMm', { d_pull_mm: r.current_tread_depth_mm }) : '—') },
          ]}
        />
      </InventoryCard>

      {registering && <RegisterTireModal product={product} onClose={() => setRegistering(false)} onRegistered={refresh} />}
      {importing && <ImportTiresModal product={product} onClose={() => setImporting(false)} onImported={refresh} />}
    </div>
  );
}

function InventoryCard({ title, count, action, children }: { title: string; count: number; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="card" style={{ minWidth: 0 }} data-inventory-section={title}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, marginBottom: 8 }}>
        <h3 style={{ margin: 0, fontSize: 15 }}>
          {title} <span style={{ color: '#6b7280', fontWeight: 400 }} data-inventory-count>({count})</span>
        </h3>
        {action}
      </div>
      {children}
    </section>
  );
}

type InventoryColumn = ScrollColumn<TireInventoryRow>;

const PAGE_SIZE = 25;
const VISIBLE_ROWS = 5;

/** New Stock / Installed: at most 5 rows visible, internal scroll, next page fetched on scroll. */
function ScrollingInventory({ productId, category, reloadKey, columns }: { productId: string; category: TireInventoryCategory; reloadKey: number; columns: InventoryColumn[] }) {
  const [rows, setRows] = useState<TireInventoryRow[] | null>(null);
  const [meta, setMeta] = useState<PaginationMeta | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const loadingRef = useRef(false);

  const request = useCallback(
    (page: number) => apiClient.get(`/app/tire-products/${productId}/inventory`, { params: { category, page, per_page: PAGE_SIZE } }),
    [productId, category],
  );

  useEffect(() => {
    let cancelled = false;
    request(1)
      .then((res) => {
        if (cancelled) return;
        setRows(res.data.data);
        setMeta(res.data.meta);
        setError(null);
      })
      .catch((e) => !cancelled && setError(extractApiError(e).message));
    return () => {
      cancelled = true;
    };
  }, [request, reloadKey]);

  const hasMore = !!meta && meta.current_page < meta.last_page;
  function loadMore() {
    if (!hasMore || !meta || loadingRef.current) return;
    loadingRef.current = true;
    setLoadingMore(true);
    request(meta.current_page + 1)
      .then((res) => {
        setRows((prev) => [...(prev ?? []), ...res.data.data]);
        setMeta(res.data.meta);
      })
      .catch((e) => setError(extractApiError(e).message))
      .finally(() => {
        loadingRef.current = false;
        setLoadingMore(false);
      });
  }

  return (
    <div>
      <ScrollTable dataAttr={category} columns={columns} rows={rows ?? []} rowKey={(r) => r.serial_number} maxRows={VISIBLE_ROWS} onReachEnd={loadMore} emptyLabel={rows === null ? t('tire.empty.loading') : t('tire.empty.noTires')} />
      <InventoryFooter shown={rows?.length ?? 0} meta={meta} loading={loadingMore} error={error} onMore={hasMore ? loadMore : undefined} />
    </div>
  );
}

/** Used Stocks: server-side pages. */
function PagedInventory({ productId, reloadKey, columns }: { productId: string; reloadKey: number; columns: InventoryColumn[] }) {
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<TireInventoryRow>(`/app/tire-products/${productId}/inventory`, { category: 'USED', page, per_page: PAGE_SIZE }, reloadKey);

  return (
    <div data-inventory-paged="USED">
      <ScrollTable dataAttr="USED" columns={columns} rows={data} rowKey={(r) => r.serial_number} maxRows={PAGE_SIZE} emptyLabel={loading ? t('tire.empty.loading') : t('tire.empty.noTires')} />
      {error && <ErrorState message={error} />}
      {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}

function InventoryFooter({ shown, meta, loading, error, onMore }: { shown: number; meta: PaginationMeta | null; loading: boolean; error: string | null; onMore?: () => void }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 12, color: '#6b7280', marginTop: 6, minHeight: 24 }}>
      <span>{meta ? t('tire.help.showingShownOfTotal', { shown: shown, total: meta.total }) : ''}</span>
      {error && <span style={{ color: '#b91c1c' }}>{error}</span>}
      {onMore && (
        <button className="btn-link" disabled={loading} onClick={onMore}>
          {loading ? t('tire.empty.loading') : t('tire.actions.loadMore')}
        </button>
      )}
    </div>
  );
}

function SerialLink({ row }: { row: TireInventoryRow }) {
  return (
    <Link to={`/app/tires/${row.id}`} style={{ fontFamily: 'monospace' }}>
      {row.serial_number}
    </Link>
  );
}

/** "12500.75" → "12,500.75"; null → "—". Display only — the value is never computed on the client. */
function Num({ value }: { value: string | null | undefined }) {
  if (value == null) return <>—</>;
  const [whole, fraction] = value.split('.');
  return <span style={{ fontVariantNumeric: 'tabular-nums' }}>{whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction && Number(fraction) !== 0 ? `.${fraction}` : '')}</span>;
}
