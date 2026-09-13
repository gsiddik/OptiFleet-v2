import { useState } from 'react';
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
    { key: 'usage_type', header: 'Brand Of', render: (b) => b.usage_type ?? '—' },
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
  const [logoUrl, setLogoUrl] = useState(brand?.logo_url ?? '');
  const [usageType, setUsageType] = useState(brand?.usage_type ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const payload = { name, logo_url: logoUrl || null, usage_type: usageType || null };
      if (brand) {
        await apiClient.put(`/app/vehicle-brands/${brand.id}`, payload);
      } else {
        await apiClient.post('/app/vehicle-brands', { code, ...payload });
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
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!brand} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Brand Of (optional)" errors={errors.usage_type}>
        <select value={usageType} onChange={(e) => setUsageType(e.target.value)} style={inputStyle}>
          <option value="">Unspecified</option>
          <option value="CAR">Car</option>
          <option value="TRUCK">Truck</option>
          <option value="BUS">Bus</option>
          <option value="HEAVY_EQUIPMENT">Heavy Equipment</option>
        </select>
      </FormField>
      <FormField label="Logo URL (optional)" errors={errors.logo_url}>
        <input value={logoUrl} onChange={(e) => setLogoUrl(e.target.value)} style={inputStyle} />
      </FormField>
      {logoUrl && <img src={logoUrl} alt={name} style={{ maxWidth: 100, marginBottom: 12, borderRadius: 4 }} />}
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
