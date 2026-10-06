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

const REORDER_STATUSES = ['', 'HEALTHY', 'LOW_STOCK', 'REORDER_REQUIRED', 'OUT_OF_STOCK'];

/** Tabs follow the canonical Item Type (classified server-side via `item_group`). */
const ITEM_GROUPS = [
  { value: 'PARTS_SUPPLIES', label: 'Parts & Supplies', hint: 'Spare Parts, Consumables, Rims, Tires' },
  { value: 'TOOLS_EQUIPMENT', label: 'Tools & Equipment', hint: 'Tools, Equipment' },
  // Used parts from Used Sparepart Processing — kept apart so used and new stock never mix.
  { value: 'USED_SPAREPARTS', label: 'Used Spareparts', hint: 'Reusable, Quarantine and Repair-pending used parts' },
  // REUSE tires: a separate used tire quantity, issued through Part Requests (Used lines).
  { value: 'USED_TIRES', label: 'Used Tires', hint: 'REUSE tires per warehouse, issued through Part Requests' },
] as const;

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
    { key: 'product', header: 'Product', render: (s) => s.product?.name ?? s.product_id },
    { key: 'warehouse', header: 'Warehouse', render: (s) => s.warehouse?.name ?? s.warehouse_id },
    { key: 'on_hand', header: 'On Hand', render: (s) => formatQty(s.quantity_on_hand) },
    { key: 'reserved', header: 'Reserved', render: (s) => formatQty(s.quantity_reserved) },
    { key: 'available', header: 'Available', render: (s) => formatQty(s.quantity_available) },
    { key: 'avg_cost', header: 'Avg Cost', render: (s) => formatMoney(s.average_unit_cost) },
    { key: 'min', header: 'Min', render: (s) => formatQty(s.minimum_stock) },
    { key: 'reorder', header: 'Reorder Pt.', render: (s) => formatQty(s.reorder_point) },
    { key: 'max', header: 'Max', render: (s) => formatQty(s.maximum_stock) },
    { key: 'status', header: 'Status', render: (s) => <StatusBadge status={s.reorder_status} /> },
    {
      key: 'actions', header: '', render: (s) => (
        <>
          {hasPermission('inventory.adjust') && (
            <button className="btn-link" onClick={() => setThresholdsTarget(s)}>
              Thresholds
            </button>
          )}
          {hasPermission('inventory.adjust') && (
            <button className="btn-link" style={{ marginLeft: 8 }} onClick={() => setAdjustTarget(s)}>
              Adjust
            </button>
          )}
          {hasPermission('inventory.scrap') && (
            <button className="btn-link" style={{ marginLeft: 8, color: '#b91c1c' }} onClick={() => setScrapTarget(s)}>
              Scrap
            </button>
          )}
        </>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Warehouse Stock</h1>
      <div role="tablist" aria-label="Item group" style={{ display: 'flex', gap: 0, marginBottom: 14, borderBottom: '1px solid #e5e7eb', flexWrap: 'wrap' }}>
        {ITEM_GROUPS.map((g) => (
          <button
            key={g.value}
            role="tab"
            aria-selected={itemGroup === g.value}
            title={g.hint}
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
                {s ? statusLabel(s) : 'All'}
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
          {!error && !loading && data.length === 0 && <EmptyState label="No stock records found." />}
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
    <Modal open={!!target} title={`Stock Thresholds — ${target?.product?.name ?? ''}`} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>
        Applies only to this Warehouse ({target?.warehouse?.name ?? ''}). Leave a field blank for no threshold.
      </p>
      <FormField label="Minimum Stock" errors={errors.minimum_stock}>
        <NumericInput step="0.0001" min="0" value={minimumStock} onChange={(e) => setMinimumStock(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Reorder Point" errors={errors.reorder_point}>
        <NumericInput step="0.0001" min="0" value={reorderPoint} onChange={(e) => setReorderPoint(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Maximum Stock" errors={errors.maximum_stock}>
        <NumericInput step="0.0001" min="0" value={maximumStock} onChange={(e) => setMaximumStock(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          Save Thresholds
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
    <Modal open={!!target} title={`Adjust Stock — ${target?.product?.name ?? ''}`} onClose={onClose}>
      <FormField label="Direction" errors={errors.direction} required>
        <select value={direction} onChange={(e) => setDirection(e.target.value as 'PLUS' | 'MINUS')} style={inputStyle}>
          <option value="PLUS">Increase (+)</option>
          <option value="MINUS">Decrease (-)</option>
        </select>
      </FormField>
      <FormField label="Quantity" errors={errors.quantity} required>
        <NumericInput step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Reason (required, audited)" errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !quantity || !reason} onClick={submit}>
          Submit Adjustment
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
    <Modal open={!!target} title={`Scrap Stock — ${target?.product?.name ?? ''}`} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>
        Permanently removes available on-hand stock (max {formatQty(target?.quantity_available ?? 0)}). This cannot be undone.
      </p>
      <FormField label="Quantity" errors={errors.quantity} required>
        <NumericInput step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Reason (required, audited)" errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !quantity || !reason} onClick={submit}>
          Confirm Scrap
        </button>
      </div>
    </Modal>
  );
}
