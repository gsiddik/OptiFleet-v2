import { useEffect, useState } from 'react';
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
import type { ComponentGroup, VehicleCategory } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';

export function VehicleCategoriesPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<VehicleCategory | null>(null);
  const [mapping, setMapping] = useState<VehicleCategory | null>(null);
  const [deleting, setDeleting] = useState<VehicleCategory | null>(null);

  const { data, meta, loading, error } = useApiList<VehicleCategory>(
    '/app/vehicle-categories',
    { search, page, per_page: 15 },
    reloadKey,
  );

  async function confirmDelete() {
    if (!deleting) return;
    await apiClient.delete(`/app/vehicle-categories/${deleting.id}`);
    setDeleting(null);
    setReloadKey((k) => k + 1);
  }

  const columns: Column<VehicleCategory>[] = [
    { key: 'code', header: 'Code', render: (c) => c.code },
    { key: 'name', header: 'Name', render: (c) => c.name },
    { key: 'is_system', header: 'Source', render: (c) => (c.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
    {
      key: 'actions',
      header: '',
      render: (c) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('component_group.map') && (
            <button className="btn-link" onClick={() => setMapping(c)}>
              Component Groups
            </button>
          )}
          {hasPermission('vehicle_category.update') && !c.is_system && (
            <>
              <button className="btn-link" onClick={() => setEditing(c)}>
                Edit
              </button>
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(c)}>
                Deactivate
              </button>
            </>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vehicle Categories</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('vehicle_category.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Category
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vehicle categories found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <CategoryFormModal open={showCreate} onClose={() => setShowCreate(false)} onSaved={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <CategoryFormModal
          open
          category={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {mapping && (
        <ComponentGroupMappingModal
          category={mapping}
          onClose={() => setMapping(null)}
          onSaved={() => {
            setMapping(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Deactivate Vehicle Category"
        message={`Deactivate "${deleting?.name}"? This can be reversed by an administrator.`}
        confirmLabel="Deactivate"
        onCancel={() => setDeleting(null)}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function CategoryFormModal({
  open,
  category,
  onClose,
  onSaved,
}: {
  open: boolean;
  category?: VehicleCategory;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(category?.code ?? '');
  const [name, setName] = useState(category?.name ?? '');
  const [description, setDescription] = useState(category?.description ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (category) {
        await apiClient.put(`/app/vehicle-categories/${category.id}`, { name, description });
      } else {
        await apiClient.post('/app/vehicle-categories', { code, name, description });
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
    <Modal open={open} title={category ? 'Edit Vehicle Category' : 'New Vehicle Category'} onClose={onClose}>
      <FormField label="Code" errors={errors.code} required={!category}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!category} />
      </FormField>
      <FormField label="Name" errors={errors.name} required={!category}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
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

function ComponentGroupMappingModal({
  category,
  onClose,
  onSaved,
}: {
  category: VehicleCategory;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [allGroups, setAllGroups] = useState<ComponentGroup[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([
      apiClient.get('/app/component-groups', { params: { per_page: 100 } }),
      apiClient.get(`/app/vehicle-categories/${category.id}`),
    ]).then(([groupsRes, catRes]) => {
      setAllGroups(groupsRes.data.data);
      const ids: string[] = catRes.data.data.component_groups.map((g: ComponentGroup) => g.id);
      setSelected(new Set(ids));
      setLoading(false);
    });
  }, [category.id]);

  function toggle(id: string) {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  }

  // "Select All" is a UI helper over the groups shown here (only active ones can be newly
  // mapped); it is never stored. Unchecking clears the whole (unsaved) selection.
  const selectable = allGroups.filter((g) => g.status === 'ACTIVE' || selected.has(g.id));
  const selectedCount = selectable.filter((g) => selected.has(g.id)).length;
  const allSelected = selectable.length > 0 && selectedCount === selectable.length;
  const someSelected = selectedCount > 0 && !allSelected;

  function toggleAll() {
    setSelected(allSelected ? new Set() : new Set(selectable.map((g) => g.id)));
  }

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/app/vehicle-categories/${category.id}/component-groups`, {
        component_group_ids: Array.from(selected),
      });
      onSaved();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={`Component Groups — ${category.name}`} onClose={onClose} width={480}>
      {loading ? (
        <LoadingState />
      ) : (
        <div style={{ maxHeight: 340, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10 }}>
          {error && <ErrorState message={error} />}
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, padding: '3px 0 6px', fontWeight: 600, borderBottom: '1px solid #f3f4f6', marginBottom: 4 }}>
            <input
              type="checkbox"
              checked={allSelected}
              ref={(el) => {
                if (el) el.indeterminate = someSelected;
              }}
              disabled={selectable.length === 0}
              onChange={toggleAll}
            />
            Select All ({selectedCount}/{selectable.length})
          </label>
          {allGroups.map((g) => (
            <label key={g.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, padding: '3px 0' }}>
              <input type="checkbox" checked={selected.has(g.id)} disabled={g.status !== 'ACTIVE' && !selected.has(g.id)} onChange={() => toggle(g.id)} />
              {componentGroupLabel(g)}
              {g.status !== 'ACTIVE' && <span style={{ fontSize: 11, color: '#9ca3af' }}>(inactive)</span>}
            </label>
          ))}
        </div>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || loading} onClick={submit}>
          {submitting ? 'Saving…' : 'Save Mapping'}
        </button>
      </div>
    </Modal>
  );
}
