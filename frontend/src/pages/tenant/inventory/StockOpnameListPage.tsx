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
import type { StockOpnameItem, Warehouse } from '../../../types';
import { t } from '../../../i18n/i18n';

export function StockOpnameListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<StockOpnameItem>('/app/stock-opnames', {}, reloadKey);

  const columns: Column<StockOpnameItem>[] = [
    { key: 'number', header: t('inventory.fields.opnameNumber'), render: (o) => <Link to={`/app/stock-opnames/${o.id}`}>{o.opname_number}</Link> },
    { key: 'warehouse', header: t('common.fields.warehouse'), render: (o) => o.warehouse?.name ?? o.warehouse_id },
    { key: 'status', header: t('common.fields.status'), render: (o) => <StatusBadge status={o.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('inventory.titles.stockOpname')}</h1>
      <Toolbar
        actions={
          hasPermission('inventory.stock_opname') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('inventory.actions.newCount')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('inventory.empty.noStockOpnamesFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateOpnameModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateOpnameModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/stock-opnames', { warehouse_id: warehouseId });
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
    <Modal open={open} title={t('inventory.modals.newStockOpname')} onClose={onClose}>
      <FormField label={t('common.fields.warehouse')} errors={errors.warehouse_id} required>
        <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.select')}</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <p style={{ fontSize: 12, color: '#9ca3af' }}>{t('inventory.help.snapshotsCurrentSystemQuantitiesEveryProduct')}</p>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !warehouseId} onClick={submit}>
          {t('inventory.actions.startCount')}
        </button>
      </div>
    </Modal>
  );
}
