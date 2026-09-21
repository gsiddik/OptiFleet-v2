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

const USAGE_TYPES = [
  { value: 'CAR', label: 'Car' },
  { value: 'TRUCK', label: 'Truck' },
  { value: 'BUS', label: 'Bus' },
  { value: 'HEAVY_EQUIPMENT', label: 'Heavy Equipment' },
];

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
    { key: 'code', header: 'Code', render: (b) => b.code },
    { key: 'name', header: 'Name', render: (b) => b.name },
    { key: 'usage_types', header: 'Brand Of', render: (b) => (b.usage_types && b.usage_types.length > 0 ? b.usage_types.join(', ') : b.usage_type ?? '—') },
    { key: 'is_system', header: 'Source', render: (b) => (b.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', render: (b) => <StatusBadge status={b.status} /> },
    {
      key: 'actions',
      header: '',
      render: (b) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <Link className="btn-link" to={`/app/master-data/vehicle-models?vehicle_brand_id=${b.id}`}>
            Models
          </Link>
          {!b.is_system && (
            <>
              {hasPermission('vehicle_brand.update') && (
                <button className="btn-link" onClick={() => setEditing(b)}>
                  Edit
                </button>
              )}
              {hasPermission('vehicle_brand.update') && (
                <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(b)}>
                  Delete
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
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vehicle Brands</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('vehicle_brand.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Brand
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vehicle brands found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <BrandFormModal open={showCreate} onClose={() => setShowCreate(false)} onSaved={() => setReloadKey((k) => k + 1)} />
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
        title="Delete Vehicle Brand"
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
  const [usageTypes, setUsageTypes] = useState<string[]>(brand?.usage_types ?? (brand?.usage_type ? [brand.usage_type] : []));
  const [logoFile, setLogoFile] = useState<File | null>(null);
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

  function toggleUsageType(value: string) {
    setUsageTypes((types) => (types.includes(value) ? types.filter((t) => t !== value) : [...types, value]));
  }

  function handleFileChange(file: File | undefined) {
    if (!file) return;
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setErrors({ logo: ['Only JPG or PNG images are accepted.'] });
      return;
    }
    setErrors({});
    setLogoFile(file);
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const payload = { name, usage_types: usageTypes };
      let brandId = brand?.id;
      if (brand) {
        await apiClient.put(`/app/vehicle-brands/${brand.id}`, payload);
      } else {
        const res = await apiClient.post('/app/vehicle-brands', { code, ...payload });
        brandId = res.data.data.id;
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
    <Modal open={open} title={brand ? 'Edit Vehicle Brand' : 'New Vehicle Brand'} onClose={onClose}>
      <FormField label="Code" errors={errors.code} required={!brand}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!brand} />
      </FormField>
      <FormField label="Name" errors={errors.name} required={!brand}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Brand Of" errors={errors.usage_types}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          {USAGE_TYPES.map((t) => (
            <label key={t.value} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input type="checkbox" checked={usageTypes.includes(t.value)} onChange={() => toggleUsageType(t.value)} /> {t.label}
            </label>
          ))}
        </div>
      </FormField>
      <FormField label="Logo (JPG or PNG)" errors={errors.logo ?? errors.file}>
        <input type="file" accept="image/jpeg,image/png" onChange={(e) => handleFileChange(e.target.files?.[0])} style={inputStyle} />
      </FormField>
      {logoPreview && <img src={logoPreview} alt={name} style={{ maxWidth: 100, marginBottom: 12, borderRadius: 4 }} />}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
