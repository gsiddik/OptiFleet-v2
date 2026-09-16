import { useState } from 'react';
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
import type { Branch } from '../../../types';

export function BranchesPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<Branch | null>(null);

  const { data, meta, loading, error } = useApiList<Branch>(
    '/app/branches',
    { search, status, page, per_page: 10 },
    reloadKey,
  );

  async function toggleStatus(branch: Branch) {
    const action = branch.status === 'ACTIVE' ? 'deactivate' : 'activate';
    await apiClient.post(`/app/branches/${branch.id}/${action}`);
    setReloadKey((k) => k + 1);
  }

  const columns: Column<Branch>[] = [
    { key: 'code', header: 'Code', render: (b) => b.code },
    { key: 'name', header: 'Name', render: (b) => b.name },
    { key: 'city', header: 'City', render: (b) => b.city ?? '—' },
    { key: 'status', header: 'Status', render: (b) => <StatusBadge status={b.status} /> },
    {
      key: 'actions',
      header: '',
      render: (b) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('branch.update') && (
            <button className="btn-link" onClick={() => setEditing(b)}>
              Edit
            </button>
          )}
          {(hasPermission('branch.activate') || hasPermission('branch.deactivate')) && (
            <button className="btn-link" onClick={() => toggleStatus(b)}>
              {b.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Branches</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('branch.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Branch
            </button>
          ) : null
        }
      >
        <select value={status} onChange={(e) => setStatus(e.target.value)} style={{ ...inputStyle, width: 150 }}>
          <option value="">All statuses</option>
          <option value="DRAFT">Draft</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
          <option value="CLOSED">Closed</option>
        </select>
      </Toolbar>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No branches found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <BranchFormModal open={showCreate} onClose={() => setShowCreate(false)} onSaved={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <BranchFormModal
          open
          branch={editing}
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

function BranchFormModal({
  open,
  branch,
  onClose,
  onSaved,
}: {
  open: boolean;
  branch?: Branch;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(branch?.code ?? '');
  const [name, setName] = useState(branch?.name ?? '');
  const [city, setCity] = useState(branch?.city ?? '');
  const [province, setProvince] = useState(branch?.province ?? '');
  const [address, setAddress] = useState(branch?.address ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    const payload = { code, name, city, province, address };
    try {
      if (branch) {
        await apiClient.put(`/app/branches/${branch.id}`, payload);
      } else {
        await apiClient.post('/app/branches', payload);
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
    <Modal open={open} title={branch ? 'Edit Branch' : 'New Branch'} onClose={onClose}>
      <FormField label="Code" errors={errors.code} required={!branch}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!branch} />
      </FormField>
      <FormField label="Name" errors={errors.name} required={!branch}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="City" errors={errors.city}>
        <input value={city} onChange={(e) => setCity(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Province" errors={errors.province}>
        <input value={province} onChange={(e) => setProvince(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Address" errors={errors.address}>
        <input value={address} onChange={(e) => setAddress(e.target.value)} style={inputStyle} />
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
