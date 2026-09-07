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

export function ComponentGroupsPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<ComponentGroup | null>(null);
  const [mapping, setMapping] = useState<ComponentGroup | null>(null);
  const [deleting, setDeleting] = useState<ComponentGroup | null>(null);
  const [allGroups, setAllGroups] = useState<ComponentGroup[]>([]);

  const { data, meta, loading, error } = useApiList<ComponentGroup>(
    '/app/component-groups',
    { search, page, per_page: 20 },
    reloadKey,
  );

  useEffect(() => {
    apiClient.get('/app/component-groups', { params: { per_page: 200 } }).then((res) => setAllGroups(res.data.data));
  }, [reloadKey]);

  async function confirmDelete() {
    if (!deleting) return;
    await apiClient.delete(`/app/component-groups/${deleting.id}`);
    setDeleting(null);
    setReloadKey((k) => k + 1);
  }

  const parentName = (id: string | null) => allGroups.find((g) => g.id === id)?.name ?? '—';

  const columns: Column<ComponentGroup>[] = [
    { key: 'sequence', header: 'Seq', render: (g) => g.sequence },
    { key: 'code', header: 'Code', render: (g) => g.code },
    { key: 'name', header: 'Name', render: (g) => g.name },
    { key: 'parent_id', header: 'Parent', render: (g) => parentName(g.parent_id) },
    { key: 'is_system', header: 'Source', render: (g) => (g.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', render: (g) => <StatusBadge status={g.status} /> },
    {
      key: 'actions',
      header: '',
      render: (g) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('component_group.map') && (
            <button className="btn-link" onClick={() => setMapping(g)}>
              Vehicle Categories
            </button>
          )}
          {hasPermission('component_group.update') && !g.is_system && (
            <>
              <button className="btn-link" onClick={() => setEditing(g)}>
                Edit
              </button>
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(g)}>
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
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Component Groups</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('component_group.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Component Group
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No component groups found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <GroupFormModal
        open={showCreate}
        allGroups={allGroups}
        onClose={() => setShowCreate(false)}
        onSaved={() => setReloadKey((k) => k + 1)}
      />
      {editing && (
        <GroupFormModal
          open
          group={editing}
          allGroups={allGroups}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {mapping && (
        <VehicleCategoryMappingModal
          group={mapping}
          onClose={() => setMapping(null)}
          onSaved={() => {
            setMapping(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Deactivate Component Group"
        message={`Deactivate "${deleting?.name}"? This can be reversed by an administrator.`}
        confirmLabel="Deactivate"
        onCancel={() => setDeleting(null)}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function GroupFormModal({
  open,
  group,
  allGroups,
  onClose,
  onSaved,
}: {
  open: boolean;
  group?: ComponentGroup;
  allGroups: ComponentGroup[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(group?.code ?? '');
  const [name, setName] = useState(group?.name ?? '');
  const [parentId, setParentId] = useState(group?.parent_id ?? '');
  const [sequence, setSequence] = useState(group?.sequence?.toString() ?? '0');
  const [description, setDescription] = useState(group?.description ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (group) {
        await apiClient.put(`/app/component-groups/${group.id}`, {
          name,
          parent_id: parentId || null,
          sequence: Number(sequence),
          description,
        });
      } else {
        await apiClient.post('/app/component-groups', {
          code,
          name,
          parent_id: parentId || null,
          sequence: Number(sequence),
          description,
        });
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
    <Modal open={open} title={group ? 'Edit Component Group' : 'New Component Group'} onClose={onClose}>
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!group} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Parent Group" errors={errors.parent_id}>
        <select value={parentId} onChange={(e) => setParentId(e.target.value)} style={inputStyle}>
          <option value="">— None (top-level) —</option>
          {allGroups.filter((g) => g.id !== group?.id).map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Sequence" errors={errors.sequence}>
        <input type="number" value={sequence} onChange={(e) => setSequence(e.target.value)} style={inputStyle} />
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

function VehicleCategoryMappingModal({
  group,
  onClose,
  onSaved,
}: {
  group: ComponentGroup;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [allCategories, setAllCategories] = useState<VehicleCategory[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    Promise.all([
      apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }),
      apiClient.get(`/app/component-groups/${group.id}`),
    ]).then(([catRes, groupRes]) => {
      setAllCategories(catRes.data.data);
      const ids: string[] = groupRes.data.data.vehicle_categories.map((c: VehicleCategory) => c.id);
      setSelected(new Set(ids));
      setLoading(false);
    });
  }, [group.id]);

  function toggle(id: string) {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  }

  async function submit() {
    setSubmitting(true);
    try {
      await apiClient.post(`/app/component-groups/${group.id}/vehicle-categories`, {
        vehicle_category_ids: Array.from(selected),
      });
      onSaved();
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={`Vehicle Categories — ${group.name}`} onClose={onClose} width={480}>
      {loading ? (
        <LoadingState />
      ) : (
        <div style={{ maxHeight: 340, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10 }}>
          {allCategories.map((c) => (
            <label key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, padding: '3px 0' }}>
              <input type="checkbox" checked={selected.has(c.id)} onChange={() => toggle(c.id)} />
              {c.name}
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
