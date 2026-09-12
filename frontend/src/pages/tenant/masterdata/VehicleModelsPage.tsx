import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VehicleBrandItem, VehicleModelItem } from '../../../types';

export function VehicleModelsPage() {
  const { hasPermission } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const brandFilter = searchParams.get('vehicle_brand_id') ?? '';
  const [brands, setBrands] = useState<VehicleBrandItem[]>([]);
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<VehicleModelItem | null>(null);
  const [deleting, setDeleting] = useState<VehicleModelItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/vehicle-brands', { params: { per_page: 200 } }).then((res) => setBrands(res.data.data)).catch(() => setBrands([]));
  }, []);

  const { data, meta, loading, error } = useApiList<VehicleModelItem>(
    '/app/vehicle-models',
    { vehicle_brand_id: brandFilter || undefined, page, per_page: 15 },
    reloadKey,
  );

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/vehicle-models/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<VehicleModelItem>[] = [
    { key: 'code', header: 'Code', render: (m) => m.code },
    { key: 'name', header: 'Name', render: (m) => m.name },
    { key: 'brand', header: 'Brand', render: (m) => m.brand?.name ?? '—' },
    { key: 'is_system', header: 'Source', render: (m) => (m.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', render: (m) => <StatusBadge status={m.status} /> },
    {
      key: 'actions',
      header: '',
      render: (m) =>
        !m.is_system && (
          <div style={{ display: 'flex', gap: 8 }}>
            {hasPermission('vehicle_brand.update') && (
              <button className="btn-link" onClick={() => setEditing(m)}>
                Edit
              </button>
            )}
            {hasPermission('vehicle_brand.update') && (
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(m)}>
                Delete
              </button>
            )}
          </div>
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vehicle Models</h1>
      <Toolbar
        actions={
          hasPermission('vehicle_brand.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Model
            </button>
          ) : null
        }
      >
        <select
          value={brandFilter}
          onChange={(e) => {
            setSearchParams(e.target.value ? { vehicle_brand_id: e.target.value } : {});
            setPage(1);
          }}
          style={{ ...inputStyle, width: 200 }}
        >
          <option value="">All brands</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vehicle models found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <ModelFormModal open={showCreate} brands={brands} defaultBrandId={brandFilter} onClose={() => setShowCreate(false)} onSaved={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <ModelFormModal
          open
          model={editing}
          brands={brands}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Delete Vehicle Model"
        message={deleteError ?? `Delete "${deleting?.name}"? This cannot be undone.`}
        confirmLabel="Delete"
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function ModelFormModal({
  open,
  model,
  brands,
  defaultBrandId,
  onClose,
  onSaved,
}: {
  open: boolean;
  model?: VehicleModelItem;
  brands: VehicleBrandItem[];
  defaultBrandId?: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [vehicleBrandId, setVehicleBrandId] = useState(model?.vehicle_brand_id ?? defaultBrandId ?? '');
  const [code, setCode] = useState(model?.code ?? '');
  const [name, setName] = useState(model?.name ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (model) {
        await apiClient.put(`/app/vehicle-models/${model.id}`, { name });
      } else {
        await apiClient.post('/app/vehicle-models', { vehicle_brand_id: vehicleBrandId, code, name });
      }
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title={model ? 'Edit Vehicle Model' : 'New Vehicle Model'} onClose={onClose}>
      {!model && (
        <FormField label="Brand" errors={errors.vehicle_brand_id}>
          <select value={vehicleBrandId} onChange={(e) => setVehicleBrandId(e.target.value)} style={inputStyle}>
            <option value="">Select…</option>
            {brands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!model} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || (!model && !vehicleBrandId)} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
