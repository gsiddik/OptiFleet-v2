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
import type { ComponentAssetItem, ComponentGroup, ProductItem } from '../../../types';

const STATUSES = ['', 'IN_STOCK', 'INSTALLED', 'ACTIVE', 'FAILED', 'REMOVED', 'UNDER_REPAIR', 'RECONDITIONED', 'SCRAPPED'];

export function ComponentAssetListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ComponentAssetItem>('/app/component-assets', { current_status: status || undefined, search: search || undefined }, reloadKey);

  const columns: Column<ComponentAssetItem>[] = [
    { key: 'serial', header: 'Serial / Asset #', render: (c) => <Link to={`/app/component-assets/${c.id}`}>{c.serial_number ?? c.asset_number ?? c.id}</Link> },
    { key: 'product', header: 'Product', render: (c) => c.product?.name ?? '—' },
    { key: 'group', header: 'Component Group', render: (c) => c.component_group?.name ?? '—' },
    { key: 'vehicle', header: 'Vehicle', render: (c) => c.current_vehicle?.registration_number ?? '—' },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.current_status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Component Assets</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('component_asset.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Component Asset
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No component assets found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [groups, setGroups] = useState<ComponentGroup[]>([]);
  const [productId, setProductId] = useState('');
  const [componentGroupId, setComponentGroupId] = useState('');
  const [serialNumber, setSerialNumber] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/products', { params: { per_page: 100 } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
    apiClient.get('/app/component-groups', { params: { per_page: 100 } }).then((res) => setGroups(res.data.data)).catch(() => setGroups([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/component-assets', { product_id: productId || undefined, component_group_id: componentGroupId || undefined, serial_number: serialNumber || undefined });
      setSerialNumber('');
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
    <Modal open={open} title="New Component Asset" onClose={onClose}>
      <FormField label="Product" errors={errors.product_id}>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Component Group" errors={errors.component_group_id}>
        <select value={componentGroupId} onChange={(e) => setComponentGroupId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Serial Number" errors={errors.serial_number}>
        <input value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
