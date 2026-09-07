import { useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WarehouseStockItem } from '../../../types';

const REORDER_STATUSES = ['', 'HEALTHY', 'LOW_STOCK', 'REORDER_REQUIRED', 'OUT_OF_STOCK'];

export function WarehouseStockListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reorderStatus, setReorderStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [adjustTarget, setAdjustTarget] = useState<WarehouseStockItem | null>(null);
  const { data, loading, error } = useApiList<WarehouseStockItem>('/app/inventory', { search: search || undefined, reorder_status: reorderStatus || undefined }, reloadKey);

  const columns: Column<WarehouseStockItem>[] = [
    { key: 'product', header: 'Product', render: (s) => s.product?.name ?? s.product_id },
    { key: 'warehouse', header: 'Warehouse', render: (s) => s.warehouse?.name ?? s.warehouse_id },
    { key: 'on_hand', header: 'On Hand', render: (s) => s.quantity_on_hand },
    { key: 'reserved', header: 'Reserved', render: (s) => s.quantity_reserved },
    { key: 'available', header: 'Available', render: (s) => s.quantity_available },
    { key: 'avg_cost', header: 'Avg Cost', render: (s) => s.average_unit_cost },
    { key: 'status', header: 'Status', render: (s) => <StatusBadge status={s.reorder_status} /> },
    {
      key: 'actions', header: '', render: (s) => hasPermission('inventory.adjust') && (
        <button className="btn-link" onClick={() => setAdjustTarget(s)}>
          Adjust
        </button>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Warehouse Stock</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {REORDER_STATUSES.map((s) => (
          <button key={s} onClick={() => setReorderStatus(s)} className={reorderStatus === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar search={search} onSearchChange={setSearch} />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No stock records found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <AdjustModal target={adjustTarget} onClose={() => setAdjustTarget(null)} onAdjusted={() => setReloadKey((k) => k + 1)} />
    </div>
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
      <FormField label="Direction" errors={errors.direction}>
        <select value={direction} onChange={(e) => setDirection(e.target.value as 'PLUS' | 'MINUS')} style={inputStyle}>
          <option value="PLUS">Increase (+)</option>
          <option value="MINUS">Decrease (-)</option>
        </select>
      </FormField>
      <FormField label="Quantity" errors={errors.quantity}>
        <input type="number" step="0.0001" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Reason (required, audited)" errors={errors.reason}>
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
