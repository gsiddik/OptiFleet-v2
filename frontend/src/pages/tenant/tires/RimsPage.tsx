import { useState } from 'react';
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
import type { RimItem } from '../../../types';

/** Final reconciliation — G-09: Rim observed as a real, working VMS master-data screen. */
export function RimsPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<RimItem | null>(null);
  const [deleting, setDeleting] = useState<RimItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const { data, meta, loading, error } = useApiList<RimItem>('/app/rims', { search: search || undefined, page, per_page: 15 }, reloadKey);

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/rims/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<RimItem>[] = [
    { key: 'code', header: 'Code', render: (r) => r.code },
    { key: 'brand', header: 'Brand', render: (r) => r.brand },
    { key: 'material', header: 'Material', render: (r) => r.material ?? '—' },
    { key: 'size', header: 'Width x Diameter', render: (r) => `${r.width_inch ?? '—'}" x ${r.diameter_inch ?? '—'}"` },
    { key: 'bolt', header: 'Bolt Pattern', render: (r) => (r.bolt_holes ? `${r.bolt_holes}x${r.pcd_mm ?? '—'}mm` : '—') },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions',
      header: '',
      render: (r) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('rim.manage') && (
            <button className="btn-link" onClick={() => setEditing(r)}>
              Edit
            </button>
          )}
          {hasPermission('rim.manage') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(r)}>
              Delete
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Rim</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('rim.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Rim
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No rims found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      {showCreate && (
        <RimFormModal
          open
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            setShowCreate(false);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {editing && (
        <RimFormModal
          open
          rim={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Delete Rim"
        message={deleteError ?? `Delete "${deleting?.brand} ${deleting?.code}"? This cannot be undone.`}
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

function RimFormModal({ open, rim, onClose, onSaved }: { open: boolean; rim?: RimItem; onClose: () => void; onSaved: () => void }) {
  const [code, setCode] = useState(rim?.code ?? '');
  const [brand, setBrand] = useState(rim?.brand ?? '');
  const [material, setMaterial] = useState(rim?.material ?? '');
  const [widthInch, setWidthInch] = useState(rim?.width_inch ?? '');
  const [diameterInch, setDiameterInch] = useState(rim?.diameter_inch ?? '');
  const [discThicknessMm, setDiscThicknessMm] = useState(rim?.disc_thickness_mm ?? '');
  const [offsetMm, setOffsetMm] = useState(rim?.offset_mm ?? '');
  const [boltHoles, setBoltHoles] = useState(rim?.bolt_holes != null ? String(rim.bolt_holes) : '');
  const [boltDiameterMm, setBoltDiameterMm] = useState(rim?.bolt_diameter_mm ?? '');
  const [pcdMm, setPcdMm] = useState(rim?.pcd_mm ?? '');
  const [hubHoleDiameterMm, setHubHoleDiameterMm] = useState(rim?.hub_hole_diameter_mm ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const payload = {
        brand,
        material: material || undefined,
        width_inch: widthInch || undefined,
        diameter_inch: diameterInch || undefined,
        disc_thickness_mm: discThicknessMm || undefined,
        offset_mm: offsetMm || undefined,
        bolt_holes: boltHoles || undefined,
        bolt_diameter_mm: boltDiameterMm || undefined,
        pcd_mm: pcdMm || undefined,
        hub_hole_diameter_mm: hubHoleDiameterMm || undefined,
      };
      if (rim) {
        await apiClient.put(`/app/rims/${rim.id}`, payload);
      } else {
        await apiClient.post('/app/rims', { ...payload, code });
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
    <Modal open={open} title={rim ? 'Edit Rim' : 'New Rim'} onClose={onClose} width={520}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Code" errors={errors.code} required={!rim}>
          <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!rim} />
        </FormField>
        <FormField label="Brand" errors={errors.brand} required={!rim}>
          <input value={brand} onChange={(e) => setBrand(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Material" errors={errors.material}>
          <input value={material ?? ''} onChange={(e) => setMaterial(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Width (in)" errors={errors.width_inch}>
          <input type="number" step="0.1" value={widthInch ?? ''} onChange={(e) => setWidthInch(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Diameter (in)" errors={errors.diameter_inch}>
          <input type="number" step="0.1" value={diameterInch ?? ''} onChange={(e) => setDiameterInch(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Disc Thickness (mm)" errors={errors.disc_thickness_mm}>
          <input type="number" step="0.1" value={discThicknessMm ?? ''} onChange={(e) => setDiscThicknessMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Offset (mm)" errors={errors.offset_mm}>
          <input type="number" step="0.1" value={offsetMm ?? ''} onChange={(e) => setOffsetMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Bolt Holes" errors={errors.bolt_holes}>
          <input type="number" step="1" value={boltHoles} onChange={(e) => setBoltHoles(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Bolt Diameter (mm)" errors={errors.bolt_diameter_mm}>
          <input type="number" step="0.1" value={boltDiameterMm ?? ''} onChange={(e) => setBoltDiameterMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="PCD (mm)" errors={errors.pcd_mm}>
          <input type="number" step="0.1" value={pcdMm ?? ''} onChange={(e) => setPcdMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Hub Hole Diameter (mm)" errors={errors.hub_hole_diameter_mm}>
          <input type="number" step="0.1" value={hubHoleDiameterMm ?? ''} onChange={(e) => setHubHoleDiameterMm(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !brand || (!rim && !code)} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
