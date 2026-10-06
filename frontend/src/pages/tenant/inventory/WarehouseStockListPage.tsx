import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { UsedSparepartsTab } from './UsedSparepartsTab';
import { UsedTiresTab } from './UsedTiresTab';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WarehouseStockItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { statusLabel } from '../../../i18n/statusRegistry';
import { t, translated, withLabels } from '../../../i18n/i18n';

const REORDER_STATUSES = ['', 'HEALTHY', 'LOW_STOCK', 'REORDER_REQUIRED', 'OUT_OF_STOCK'];

/** Tabs follow the canonical Item Type (classified server-side via `item_group`). */
const ITEM_GROUPS = withLabels([
  { value: 'PARTS_SUPPLIES', label: 'Parts & Supplies', labelKey: 'inventory.fields.partsAndSupplies', hint: 'Spare Parts, Consumables, Rims, Tires', hintKey: 'inventory.help.sparePartsConsumablesRimsTires' },
  { value: 'TOOLS_EQUIPMENT', label: 'Tools & Equipment', labelKey: 'inventory.fields.toolsAndEquipment', hint: 'Tools, Equipment', hintKey: 'inventory.help.toolsEquipment' },
  // Used parts from Used Sparepart Processing — kept apart so used and new stock never mix.
  { value: 'USED_SPAREPARTS', label: 'Used Spareparts', labelKey: 'inventory.fields.usedSpareparts', hint: 'Reusable, Quarantine and Repair-pending used parts', hintKey: 'inventory.help.reusableQuarantineRepairPendingUsedParts' },
  // REUSE tires: a separate used tire quantity, issued through Part Requests (Used lines).
  { value: 'USED_TIRES', label: 'Used Tires', labelKey: 'inventory.fields.usedTires', hint: 'REUSE tires per warehouse, issued through Part Requests', hintKey: 'inventory.help.reuseTiresPerWarehouseIssuedThrough' },
] as const);

export function WarehouseStockListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reorderStatus, setReorderStatus] = useState('');
  const [itemGroup, setItemGroup] = useState<(typeof ITEM_GROUPS)[number]['value']>('PARTS_SUPPLIES');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [adjustTarget, setAdjustTarget] = useState<WarehouseStockItem | null>(null);
  const [scrapTarget, setScrapTarget] = useState<WarehouseStockItem | null>(null);
  const [thresholdsTarget, setThresholdsTarget] = useState<WarehouseStockItem | null>(null);
  const { data, meta, loading, error } = useApiList<WarehouseStockItem>(
    '/app/inventory',
    { item_group: itemGroup === 'USED_SPAREPARTS' || itemGroup === 'USED_TIRES' ? 'PARTS_SUPPLIES' : itemGroup, search: search || undefined, reorder_status: reorderStatus || undefined, page },
    reloadKey,
  );

  const columns: Column<WarehouseStockItem>[] = [
    { key: 'product', header: t('common.fields.product'), render: (s) => s.product?.name ?? s.product_id },
    { key: 'warehouse', header: t('common.fields.warehouse'), render: (s) => s.warehouse?.name ?? s.warehouse_id },
    { key: 'on_hand', header: t('analytics.fields.onHand'), render: (s) => formatQty(s.quantity_on_hand) },
    { key: 'reserved', header: t('inventory.fields.reserved'), render: (s) => formatQty(s.quantity_reserved) },
    { key: 'available', header: t('inventory.fields.available'), render: (s) => formatQty(s.quantity_available) },
    { key: 'avg_cost', header: t('inventory.fields.avgCost'), render: (s) => formatMoney(s.average_unit_cost) },
    { key: 'min', header: t('inventory.fields.min'), render: (s) => formatQty(s.minimum_stock) },
    { key: 'reorder', header: t('inventory.fields.reorderPt'), render: (s) => formatQty(s.reorder_point) },
    { key: 'max', header: t('inventory.fields.max'), render: (s) => formatQty(s.maximum_stock) },
    { key: 'status', header: t('common.fields.status'), render: (s) => <StatusBadge status={s.reorder_status} /> },
    {
      key: 'actions', header: '', render: (s) => (
        <>
          {hasPermission('inventory.adjust') && (
            <button className="btn-link" onClick={() => setThresholdsTarget(s)}>
              {t('inventory.actions.thresholds')}
            </button>
          )}
          {hasPermission('inventory.adjust') && (
            <button className="btn-link" style={{ marginLeft: 8 }} onClick={() => setAdjustTarget(s)}>
              {t('inventory.actions.adjust')}
            </button>
          )}
          {hasPermission('inventory.scrap') && (
            <button className="btn-link" style={{ marginLeft: 8, color: '#b91c1c' }} onClick={() => setScrapTarget(s)}>
              {t('inventory.actions.scrap')}
            </button>
          )}
        </>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('inventory.titles.warehouseStock')}</h1>
      <div role="tablist" aria-label={t('inventory.tooltips.itemGroup')} style={{ display: 'flex', gap: 0, marginBottom: 14, borderBottom: '1px solid #e5e7eb', flexWrap: 'wrap' }}>
        {ITEM_GROUPS.map((g) => (
          <button
            key={g.value}
            role="tab"
            aria-selected={itemGroup === g.value}
            title={translated(g.hintKey, g.hint)}
            onClick={() => {
              setItemGroup(g.value);
              setPage(1);
            }}
            style={{
              padding: '8px 16px',
              fontSize: 14,
              fontWeight: 600,
              background: 'none',
              border: 'none',
              borderBottom: itemGroup === g.value ? '2px solid #1d4ed8' : '2px solid transparent',
              color: itemGroup === g.value ? '#1d4ed8' : '#6b7280',
              cursor: 'pointer',
            }}
          >
            {g.label}
          </button>
        ))}
      </div>
      {itemGroup === 'USED_SPAREPARTS' ? (
        <UsedSparepartsTab />
      ) : itemGroup === 'USED_TIRES' ? (
        <UsedTiresTab />
      ) : (
        <>
          <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
            {REORDER_STATUSES.map((s) => (
              <button
                key={s}
                onClick={() => {
                  setReorderStatus(s);
                  setPage(1);
                }}
                className={reorderStatus === s ? 'btn-primary' : 'btn-secondary'}
                style={{ padding: '4px 10px', fontSize: 12 }}
              >
                {s ? statusLabel(s) : t('common.actions.all')}
              </button>
            ))}
          </div>
          <Toolbar
            search={search}
            onSearchChange={(v) => {
              setSearch(v);
              setPage(1);
            }}
          />
          {error && <ErrorState message={error} />}
          {!error && loading && <LoadingState />}
          {!error && !loading && data.length === 0 && <EmptyState label={t('inventory.empty.noStockRecordsFound')} />}
          {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <AdjustModal target={adjustTarget} onClose={() => setAdjustTarget(null)} onAdjusted={() => setReloadKey((k) => k + 1)} />
      <ScrapModal target={scrapTarget} onClose={() => setScrapTarget(null)} onScrapped={() => setReloadKey((k) => k + 1)} />
      <ThresholdsModal target={thresholdsTarget} onClose={() => setThresholdsTarget(null)} onSaved={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

/**
 * "Next Improvement Tenant Portal": Inventory Configuration's Minimum
 * Stock / Reorder Point / Maximum Stock — per-Warehouse policy (a
 * Product can stock differently at each Warehouse), not a Product
 * Master field, so this lives on WarehouseStock via the existing
 * updateThresholds endpoint (already backend-complete; this was its
 * missing frontend entry point).
 */
function ThresholdsModal({ target, onClose, onSaved }: { target: WarehouseStockItem | null; onClose: () => void; onSaved: () => void }) {
  const [minimumStock, setMinimumStock] = useState('');
  const [reorderPoint, setReorderPoint] = useState('');
  const [maximumStock, setMaximumStock] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (target) {
      setMinimumStock(target.minimum_stock ?? '');
      setReorderPoint(target.reorder_point ?? '');
      setMaximumStock(target.maximum_stock ?? '');
      setErrors({});
    }
  }, [target]);

  async function submit() {
    if (!target) return;
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/inventory/${target.id}/thresholds`, {
        minimum_stock: minimumStock || null, reorder_point: reorderPoint || null, maximum_stock: maximumStock || null,
      });
      onSaved();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={!!target} title={t('inventory.modals.stockThresholdsValue', { value: target?.product?.name ?? '' })} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>
        {t('inventory.help.appliesOnlyWarehouse')}{target?.warehouse?.name ?? ''}{t('inventory.help.leaveFieldBlankNoThreshold')}
      </p>
      <FormField label={t('inventory.fields.minimumStock')} errors={errors.minimum_stock}>
        <NumericInput step="0.0001" min="0" value={minimumStock} onChange={(e) => setMinimumStock(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('inventory.fields.reorderPoint')} errors={errors.reorder_point}>
        <NumericInput step="0.0001" min="0" value={reorderPoint} onChange={(e) => setReorderPoint(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('inventory.fields.maximumStock')} errors={errors.maximum_stock}>
        <NumericInput step="0.0001" min="0" value={maximumStock} onChange={(e) => setMaximumStock(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {t('inventory.actions.saveThresholds')}
        </button>
      </div>
    </Modal>
  );
}

function AdjustModal({ target, onClose, onAdjusted }: { target: WarehouseStockItem | null; onClose: () => void; onAdjusted: () => void }) {
  const [quantity, setQuantity] = useState('');
  const [direction, setDirection] = useState<'PLUS' | 'MINUS'>('PLUS');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    if (!target) return;
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/inventory/adjust', {
        warehouse_id: target.warehouse_id, product_id: target.product_id, quantity, direction, reason,
      });
      setQuantity('');
      setReason('');
      onAdjusted();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={!!target} title={t('inventory.modals.adjustStockValue', { value: target?.product?.name ?? '' })} onClose={onClose}>
      <FormField label={t('inventory.fields.direction')} errors={errors.direction} required>
        <select value={direction} onChange={(e) => setDirection(e.target.value as 'PLUS' | 'MINUS')} style={inputStyle}>
          <option value="PLUS">{t('inventory.fields.increase')}</option>
          <option value="MINUS">{t('inventory.fields.decrease')}</option>
        </select>
      </FormField>
      <FormField label={t('common.fields.quantity')} errors={errors.quantity} required>
        <NumericInput step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('inventory.fields.reasonRequiredAudited')} errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !quantity || !reason} onClick={submit}>
          {t('inventory.actions.submitAdjustment')}
        </button>
      </div>
    </Modal>
  );
}

/** G-20: InventoryService::scrap() existed since Phase 4 with no route/permission/UI ever calling it. */
function ScrapModal({ target, onClose, onScrapped }: { target: WarehouseStockItem | null; onClose: () => void; onScrapped: () => void }) {
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    if (!target) return;
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/inventory/scrap', {
        warehouse_id: target.warehouse_id, product_id: target.product_id, quantity, reason,
      });
      setQuantity('');
      setReason('');
      onScrapped();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={!!target} title={t('inventory.modals.scrapStockValue', { value: target?.product?.name ?? '' })} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>
        {t('inventory.warnings.permanentlyRemovesAvailableHandStockMax', { value: formatQty(target?.quantity_available ?? 0) })}
      </p>
      <FormField label={t('common.fields.quantity')} errors={errors.quantity} required>
        <NumericInput step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('inventory.fields.reasonRequiredAudited')} errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !quantity || !reason} onClick={submit}>
          {t('inventory.actions.confirmScrap')}
        </button>
      </div>
    </Modal>
  );
}
