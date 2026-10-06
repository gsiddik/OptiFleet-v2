import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
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
import type { VehicleBrandItem } from '../../../types';
import { t as tt } from '../../../i18n/i18n';

export function VehicleBrandsPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<VehicleBrandItem | null>(null);
  const [deleting, setDeleting] = useState<VehicleBrandItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const { data, meta, loading, error } = useApiList<VehicleBrandItem>(
    '/app/vehicle-brands',
    { search, page, per_page: 15 },
    reloadKey,
  );

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/vehicle-brands/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<VehicleBrandItem>[] = [
    { key: 'code', header: tt('common.fields.code'), render: (b) => b.code },
    { key: 'name', header: tt('common.fields.name'), render: (b) => b.name },
    { key: 'vehicle_categories', header: tt('masterData.fields.brandOf'), render: (b) => (b.vehicle_categories ?? []).map((c) => c.name).join(', ') || '—' },
    { key: 'is_system', header: tt('common.fields.source'), render: (b) => (b.is_system ? tt('common.fields.system') : tt('common.fields.tenant')) },
    { key: 'status', header: tt('common.fields.status'), render: (b) => <StatusBadge status={b.status} /> },
    {
      key: 'actions',
      header: '',
      render: (b) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <Link className="btn-link" to={`/app/master-data/vehicle-models?vehicle_brand_id=${b.id}`}>
            {tt('masterData.actions.models')}
          </Link>
          {!b.is_system && (
            <>
              {hasPermission('vehicle_brand.update') && (
                <button className="btn-link" onClick={() => setEditing(b)}>
                  {tt('common.actions.edit')}
                </button>
              )}
              {hasPermission('vehicle_brand.update') && (
                <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(b)}>
                  {tt('common.actions.delete')}
                </button>
              )}
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('masterData.titles.vehicleBrands')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('vehicle_brand.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('masterData.actions.newBrand')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('masterData.empty.noVehicleBrandsFound')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      {showCreate && (
        <BrandFormModal
          open
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            setShowCreate(false);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {editing && (
        <BrandFormModal
          open
          brand={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title={tt('masterData.confirm.deleteVehicleBrand')}
        message={deleteError ?? tt('platform.masterdata.confirm.deleteNameCannotUndone', { name: deleting?.name })}
        confirmLabel={tt('common.actions.delete')}
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function BrandFormModal({
  open,
  brand,
  onClose,
  onSaved,
}: {
  open: boolean;
  brand?: VehicleBrandItem;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(brand?.code ?? '');
  const [name, setName] = useState(brand?.name ?? '');
  // "Brand Of" = Vehicle Category master ids (active categories, never a hardcoded list).
  const [categoryIds, setCategoryIds] = useState<string[]>((brand?.vehicle_categories ?? []).map((c) => c.id));
  const [categoryOptions, setCategoryOptions] = useState<{ id: string; name: string }[] | null>(null);
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [createdBrandId, setCreatedBrandId] = useState<string | null>(null);
  const [logoPreview, setLogoPreview] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    if (logoFile) {
      objectUrl = URL.createObjectURL(logoFile);
      setLogoPreview(objectUrl);
    } else if (brand?.logo_available) {
      apiClient.get(`/app/vehicle-brands/${brand.id}/logo`, { responseType: 'blob' }).then((res) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(res.data);
        setLogoPreview(objectUrl);
      });
    } else if (brand?.logo_url) {
      setLogoPreview(brand.logo_url);
    } else {
      setLogoPreview(null);
    }

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [brand, logoFile]);

  useEffect(() => {
    if (!open) return;
    apiClient
      .get('/app/vehicle-brands/category-options')
      .then((res) => setCategoryOptions(res.data.data))
      .catch(() => setCategoryOptions([]));
  }, [open]);

  // A category the brand already has but that was deactivated since stays listed (checked).
  const shownCategories = [
    ...(categoryOptions ?? []),
    ...(brand?.vehicle_categories ?? []).filter((c) => !(categoryOptions ?? []).some((o) => o.id === c.id)).map((c) => ({ id: c.id, name: `${c.name} (inactive)` })),
  ];

  function toggleCategory(value: string) {
    setCategoryIds((ids) => (ids.includes(value) ? ids.filter((t) => t !== value) : [...ids, value]));
  }

  function handleFileChange(file: File | undefined) {
    if (!file) return;
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setErrors({ logo: [tt('masterData.help.onlyJpgPngImagesAccepted')] });
      return;
    }
    setErrors({});
    setLogoFile(file);
  }

  // Closing after a brand was created but its logo failed still refreshes the list.
  const close = () => (createdBrandId ? onSaved() : onClose());

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const payload = { name, vehicle_category_ids: categoryIds };
      // A brand created on an earlier attempt whose logo upload then failed is updated, not
      // created again (the retry would otherwise fail on the duplicate code).
      let brandId = brand?.id ?? createdBrandId;
      if (brandId) {
        await apiClient.put(`/app/vehicle-brands/${brandId}`, payload);
      } else {
        const res = await apiClient.post('/app/vehicle-brands', { code, ...payload });
        brandId = res.data.data.id as string;
        setCreatedBrandId(brandId);
      }
      if (logoFile && brandId) {
        const form = new FormData();
        form.append('file', logoFile);
        await apiClient.post(`/app/vehicle-brands/${brandId}/logo`, form);
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
    <Modal open={open} title={brand ? tt('masterData.modals.editVehicleBrand') : tt('masterData.modals.newVehicleBrand')} onClose={close}>
      <FormField label={tt('common.fields.code')} errors={errors.code} required={!brand}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!brand} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required={!brand}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('masterData.fields.brandOf')} errors={errors.vehicle_category_ids}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxHeight: 220, overflowY: 'auto' }}>
          {categoryOptions === null && <span style={{ fontSize: 12, color: '#9ca3af' }}>{tt('masterData.help.loadingVehicleCategories')}</span>}
          {categoryOptions !== null && shownCategories.length === 0 && (
            <span style={{ fontSize: 12, color: '#9ca3af' }}>{tt('masterData.empty.noActiveVehicleCategoriesAddThem')}</span>
          )}
          {shownCategories.map((c) => (
            <label key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input type="checkbox" checked={categoryIds.includes(c.id)} onChange={() => toggleCategory(c.id)} /> {c.name}
            </label>
          ))}
        </div>
      </FormField>
      <FormField label={tt('account.fields.logoJpgOrPng')} errors={errors.logo ?? errors.file}>
        <input type="file" accept="image/jpeg,image/png" onChange={(e) => handleFileChange(e.target.files?.[0])} style={inputStyle} />
      </FormField>
      {logoPreview && <img src={logoPreview} alt={name} style={{ maxWidth: 100, marginBottom: 12, borderRadius: 4 }} />}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={close}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
