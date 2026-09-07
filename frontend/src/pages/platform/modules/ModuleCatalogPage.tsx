import { useState } from 'react';
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
import type { ModuleCatalogItem } from '../../../types';

export function ModuleCatalogPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ModuleCatalogItem>('/platform/modules', { search }, reloadKey);

  const columns: Column<ModuleCatalogItem>[] = [
    { key: 'code', header: 'Code', render: (m) => <Link to={`/platform/modules/${m.id}`}>{m.code}</Link> },
    { key: 'name', header: 'Name', render: (m) => m.name },
    { key: 'category', header: 'Category', render: (m) => m.category },
    { key: 'is_core', header: 'Core', render: (m) => (m.is_core ? 'Yes' : 'No') },
    { key: 'is_sellable', header: 'Sellable', render: (m) => (m.is_sellable ? 'Yes' : 'No') },
    { key: 'status', header: 'Status', render: (m) => <StatusBadge status={m.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Module Catalog</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('module.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Module
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No modules found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateModuleModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateModuleModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [category, setCategory] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/platform/modules', { code, name, category });
      setCode('');
      setName('');
      setCategory('');
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
    <Modal open={open} title="New Module" onClose={onClose}>
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Category" errors={errors.category}>
        <input value={category} onChange={(e) => setCategory(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Creating…' : 'Create Module'}
        </button>
      </div>
    </Modal>
  );
}
