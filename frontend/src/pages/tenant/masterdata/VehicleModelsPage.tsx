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
import { t } from '../../../i18n/i18n';

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
    { key: 'code', header: t('common.fields.code'), render: (m) => m.code },
    { key: 'name', header: t('common.fields.name'), render: (m) => m.name },
    { key: 'brand', header: t('common.fields.brand'), render: (m) => m.brand?.name ?? '—' },
    { key: 'is_system', header: t('common.fields.source'), render: (m) => (m.is_system ? t('common.fields.system') : t('common.fields.tenant')) },
    { key: 'status', header: t('common.fields.status'), render: (m) => <StatusBadge status={m.status} /> },
    {
      key: 'actions',
      header: '',
      render: (m) =>
        !m.is_system && (
          <div style={{ display: 'flex', gap: 8 }}>
            {hasPermission('vehicle_brand.update') && (
              <button className="btn-link" onClick={() => setEditing(m)}>
                {t('common.actions.edit')}
              </button>
            )}
            {hasPermission('vehicle_brand.update') && (
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(m)}>
                {t('common.actions.delete')}
              </button>
            )}
          </div>
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('masterData.titles.vehicleModels')}</h1>
      <Toolbar
        actions={
          hasPermission('vehicle_brand.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('masterData.actions.newModel')}
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
          <option value="">{t('masterData.filters.allBrands')}</option>
          {brands.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('masterData.empty.noVehicleModelsFound')} />}
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
        title={t('masterData.confirm.deleteVehicleModel')}
        message={deleteError ?? t('platform.masterdata.confirm.deleteNameCannotUndone', { name: deleting?.name })}
        confirmLabel={t('common.actions.delete')}
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
    <Modal open={open} title={model ? t('masterData.modals.editVehicleModel') : t('masterData.modals.newVehicleModel')} onClose={onClose}>
      {!model && (
        <FormField label={t('common.fields.brand')} errors={errors.vehicle_brand_id} required>
          <select value={vehicleBrandId} onChange={(e) => setVehicleBrandId(e.target.value)} style={inputStyle}>
            <option value="">{t('common.fields.select')}</option>
            {brands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <FormField label={t('common.fields.code')} errors={errors.code} required={!model}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!model} />
      </FormField>
      <FormField label={t('common.fields.name')} errors={errors.name} required={!model}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || (!model && !vehicleBrandId)} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
