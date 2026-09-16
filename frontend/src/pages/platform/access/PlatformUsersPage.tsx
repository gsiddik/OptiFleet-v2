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

interface PlatformUserRow {
  id: string;
  name: string;
  email: string;
  status: string;
}

export function PlatformUsersPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, meta, loading, error } = useApiList<PlatformUserRow>('/platform/users', { search, page }, reloadKey);

  async function toggleStatus(row: PlatformUserRow) {
    await apiClient.patch(`/platform/users/${row.id}`, { status: row.status === 'active' ? 'inactive' : 'active' });
    setReloadKey((k) => k + 1);
  }

  const columns: Column<PlatformUserRow>[] = [
    { key: 'name', header: 'Name', render: (r) => r.name },
    { key: 'email', header: 'Email', render: (r) => r.email },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions',
      header: '',
      render: (r) =>
        hasPermission('user.update') ? (
          <button className="btn-link" onClick={() => toggleStatus(r)}>
            {r.status === 'active' ? 'Deactivate' : 'Activate'}
          </button>
        ) : null,
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Platform Users</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('user.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New User
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No platform users found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <CreateUserModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateUserModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/platform/users', { name, email, password });
      setName('');
      setEmail('');
      setPassword('');
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
    <Modal open={open} title="New Platform User" onClose={onClose}>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Email" errors={errors.email} required>
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Password" errors={errors.password} required>
        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Creating…' : 'Create User'}
        </button>
      </div>
    </Modal>
  );
}
