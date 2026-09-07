import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { Branch, Workshop } from '../../../types';

export function WorkshopsPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<Workshop | null>(null);
  const [branches, setBranches] = useState<Branch[]>([]);

  useEffect(() => {
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
  }, []);

  const { data, meta, loading, error } = useApiList<Workshop>('/app/workshops', { search, page, per_page: 10 }, reloadKey);

  async function toggleStatus(w: Workshop) {
    const action = w.status === 'ACTIVE' ? 'deactivate' : 'activate';
    await apiClient.post(`/app/workshops/${w.id}/${action}`);
    setReloadKey((k) => k + 1);
  }

  const branchName = (id: string | null) => branches.find((b) => b.id === id)?.name ?? '—';

  const columns: Column<Workshop>[] = [
    { key: 'code', header: 'Code', render: (w) => w.code },
    { key: 'name', header: 'Name', render: (w) => w.name },
    { key: 'branch_id', header: 'Branch', render: (w) => branchName(w.branch_id) },
    { key: 'workshop_type', header: 'Type', render: (w) => w.workshop_type },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
    {
      key: 'actions',
      header: '',
      render: (w) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('workshop.update') && (
            <button className="btn-link" onClick={() => setEditing(w)}>
              Edit
            </button>
          )}
          {(hasPermission('workshop.activate') || hasPermission('workshop.deactivate')) && (
            <button className="btn-link" onClick={() => toggleStatus(w)}>
              {w.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workshops</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('workshop.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Workshop
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No workshops found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <WorkshopFormModal
        open={showCreate}
        branches={branches}
        onClose={() => setShowCreate(false)}
        onSaved={() => setReloadKey((k) => k + 1)}
      />
      {editing && (
        <WorkshopFormModal
          open
          workshop={editing}
          branches={branches}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function WorkshopFormModal({
  open,
  workshop,
  branches,
  onClose,
  onSaved,
}: {
  open: boolean;
  workshop?: Workshop;
  branches: Branch[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(workshop?.code ?? '');
  const [name, setName] = useState(workshop?.name ?? '');
  const [branchId, setBranchId] = useState(workshop?.branch_id ?? '');
  const [type, setType] = useState(workshop?.workshop_type ?? 'INTERNAL');
  const [capacity, setCapacity] = useState(workshop?.capacity?.toString() ?? '');
  const [bays, setBays] = useState(workshop?.number_of_service_bays?.toString() ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    const payload = {
      code,
      name,
      branch_id: branchId || null,
      workshop_type: type,
      capacity: capacity ? Number(capacity) : null,
      number_of_service_bays: bays ? Number(bays) : null,
    };
    try {
      if (workshop) {
        await apiClient.put(`/app/workshops/${workshop.id}`, payload);
      } else {
        await apiClient.post('/app/workshops', payload);
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
    <Modal open={open} title={workshop ? 'Edit Workshop' : 'New Workshop'} onClose={onClose}>
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!workshop} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Branch" errors={errors.branch_id}>
        <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
          <option value="">— None —</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Type" errors={errors.workshop_type}>
        <select value={type} onChange={(e) => setType(e.target.value as Workshop['workshop_type'])} style={inputStyle}>
          <option value="INTERNAL">Internal</option>
          <option value="SATELLITE">Satellite</option>
          <option value="MOBILE">Mobile</option>
        </select>
      </FormField>
      <FormField label="Capacity" errors={errors.capacity}>
        <input type="number" value={capacity} onChange={(e) => setCapacity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Service Bays" errors={errors.number_of_service_bays}>
        <input type="number" value={bays} onChange={(e) => setBays(e.target.value)} style={inputStyle} />
      </FormField>
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
