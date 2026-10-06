import { useEffect, useState, type ReactNode } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../api/client';
import { FormField, inputStyle } from '../FormField';
import { Modal } from '../Modal';
import { StatusBadge } from '../StatusBadge';
import { Table, type Column } from '../Table';
import { Toolbar } from '../Toolbar';
import { Pagination } from '../Pagination';
import { ConfirmDialog } from '../ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../States';
import { useApiList } from '../../hooks/useApiList';
import { useAuth } from '../../auth/AuthContext';
import type { ComponentCategory, ComponentGroup, ComponentItemType, ComponentSubcategory } from '../../types';
import { componentGroupLabel } from '../../utils/componentGroup';
import { NumericInput } from '../NumericInput';
import { formatTimestampDate } from '../../utils/date';

/**
 * Category / Assembly and Subcategory / Component Family management, shared by
 * the tenant portal (baseline read-only, own rows editable) and the platform
 * portal (baseline editable). Parent pickers and filters are driven by the
 * backend lists; every rule (code uniqueness, re-parenting of used rows,
 * Item Type narrowing, effective availability) is enforced server-side.
 */

type View = 'active' | 'deleted' | 'all';

const ITEM_TYPE_LABELS: Record<ComponentItemType, string> = {
  SPARE_PART: 'Sparepart',
  CONSUMABLE: 'Consumable',
  TIRE: 'Tire',
  RIM: 'Rim',
  TOOL: 'Tools',
  EQUIPMENT: 'Equipment',
};
const ITEM_TYPES = Object.keys(ITEM_TYPE_LABELS) as ComponentItemType[];
const muted = { color: '#6b7280', fontSize: 12 };
const DELETE_MESSAGE = 'This classification will no longer be available for new Products or transactions. Existing records will remain unchanged.';

interface Endpoints {
  groups: string;
  categories: string;
  subcategories: string;
}

function trashedParam(view: View) {
  return view === 'deleted' ? 'only' : view === 'all' ? 'with' : undefined;
}

function useGroups(url: string) {
  const [groups, setGroups] = useState<ComponentGroup[]>([]);
  useEffect(() => {
    apiClient.get(url, { params: { per_page: 200, trashed: 'with' } }).then((res) => setGroups(res.data.data)).catch(() => setGroups([]));
  }, [url]);
  return groups;
}

function useCategories(url: string, groupId: string, reloadKey = 0) {
  const [categories, setCategories] = useState<ComponentCategory[]>([]);
  useEffect(() => {
    if (!groupId) return;
    apiClient
      .get(url, { params: { component_group_id: groupId, per_page: 500, trashed: 'with' } })
      .then((res) => setCategories(res.data.data))
      .catch(() => setCategories([]));
  }, [url, groupId, reloadKey]);
  return groupId ? categories : [];
}

const isLive = (row: { is_deleted?: boolean; deleted_at?: string | null; status?: string }) => !row.is_deleted && !row.deleted_at && row.status === 'ACTIVE';
const deletedSuffix = (row?: { deleted_at?: string | null } | null) => (row?.deleted_at ? ' (deleted)' : '');

function StatusCell({ row }: { row: { is_deleted?: boolean; status: string } }) {
  return row.is_deleted ? <StatusBadge status="Deleted" /> : <StatusBadge status={row.status} />;
}

function Filters({ children, view, setView, status, setStatus }: { children?: ReactNode; view: View; setView: (v: View) => void; status: string; setStatus: (s: string) => void }) {
  return (
    <>
      {children}
      <select aria-label="Status filter" value={status} onChange={(e) => setStatus(e.target.value)} style={{ ...inputStyle, width: 130 }}>
        <option value="">All statuses</option>
        <option value="ACTIVE">Active</option>
        <option value="INACTIVE">Inactive</option>
      </select>
      <select aria-label="Record filter" value={view} onChange={(e) => setView(e.target.value as View)} style={{ ...inputStyle, width: 140 }}>
        <option value="active">Not deleted</option>
        <option value="deleted">Deleted only</option>
        <option value="all">All records</option>
      </select>
    </>
  );
}

function useRowActions(apiBase: string, reload: () => void) {
  const [actionError, setActionError] = useState<string | null>(null);
  async function run(fn: () => Promise<unknown>) {
    setActionError(null);
    try {
      await fn();
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    }
  }
  return {
    actionError,
    remove: (id: string) => run(() => apiClient.delete(`${apiBase}/${id}`)),
    restore: (id: string) => run(() => apiClient.post(`${apiBase}/${id}/restore`)),
  };
}

// ============================================================================ Categories

export function ComponentCategoryManager({ endpoints, canManageRow, intro }: { endpoints: Endpoints; canManageRow: (row: ComponentCategory) => boolean; intro?: ReactNode }) {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [groupId, setGroupId] = useState('');
  const [status, setStatus] = useState('');
  const [view, setView] = useState<View>('active');
  const [sort, setSort] = useState('sequence');
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [editing, setEditing] = useState<ComponentCategory | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<ComponentCategory | null>(null);
  const reload = () => setReloadKey((k) => k + 1);
  const groups = useGroups(endpoints.groups);
  const { actionError, remove, restore } = useRowActions(endpoints.categories, reload);

  const { data, meta, loading, error } = useApiList<ComponentCategory>(
    endpoints.categories,
    { search, component_group_id: groupId || undefined, status: status || undefined, trashed: trashedParam(view), sort, direction, page, per_page: 25 },
    reloadKey,
  );

  const columns: Column<ComponentCategory>[] = [
    { key: 'code', header: 'Code', sortable: true, render: (c) => <span style={{ fontFamily: 'monospace', fontSize: 13 }}>{c.code}</span> },
    { key: 'name', header: 'Name', sortable: true, render: (c) => c.name },
    { key: 'group', header: 'Component Group', render: (c) => (c.component_group ? componentGroupLabel(c.component_group) + deletedSuffix(c.component_group) : '—') },
    { key: 'description', header: 'Description', render: (c) => <span style={muted}>{c.description || '—'}</span> },
    { key: 'is_system', header: 'Source', render: (c) => (c.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', sortable: true, render: (c) => <StatusCell row={c} /> },
    { key: 'usage', header: 'Usage', render: (c) => (c.is_used ? 'Used' : <span style={muted}>Unused</span>) },
    { key: 'updated_at', header: 'Updated', sortable: true, render: (c) => (c.updated_at ? formatTimestampDate(c.updated_at) : '—') },
    {
      key: 'actions',
      header: '',
      render: (c) => (
        <div style={{ display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {canManageRow(c) && !c.is_deleted && hasPermission('component_category.update') && (
            <button className="btn-link" onClick={() => setEditing(c)}>
              Edit
            </button>
          )}
          {canManageRow(c) && !c.is_deleted && hasPermission('component_category.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(c)}>
              Delete
            </button>
          )}
          {canManageRow(c) && c.is_deleted && hasPermission('component_category.delete') && (
            <button className="btn-link" onClick={() => restore(c.id)}>
              Restore
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 8 }}>Component Categories</h1>
      {intro && <p style={{ ...muted, fontSize: 13, marginTop: 0, marginBottom: 16 }}>{intro}</p>}
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('component_category.create') && (
            <button className="btn-primary" onClick={() => setCreating(true)}>
              New Category
            </button>
          )
        }
      >
        <Filters view={view} setView={(v) => { setView(v); setPage(1); }} status={status} setStatus={(v) => { setStatus(v); setPage(1); }}>
          <select aria-label="Component Group filter" value={groupId} onChange={(e) => { setGroupId(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 220 }}>
            <option value="">All component groups</option>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>
                {componentGroupLabel(g)}
              </option>
            ))}
          </select>
        </Filters>
      </Toolbar>
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No categories found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table
            columns={columns}
            rows={data}
            sort={sort}
            direction={direction}
            onSort={(key) => {
              if (sort === key) setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
              else {
                setSort(key);
                setDirection('asc');
              }
            }}
          />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      {(creating || editing) && (
        <CategoryFormModal
          apiBase={endpoints.categories}
          category={editing ?? undefined}
          groups={groups.filter((g) => isLive(g) || g.id === editing?.component_group_id)}
          defaultGroupId={groupId}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={() => {
            setCreating(false);
            setEditing(null);
            reload();
          }}
        />
      )}
      <ConfirmDialog
        open={!!deleting}
        title="Delete Category"
        message={`Delete "${deleting?.name}"? ${DELETE_MESSAGE} Its Subcategories become unavailable for new data too, without being deleted.`}
        confirmLabel="Delete"
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) remove(deleting.id);
          setDeleting(null);
        }}
      />
    </div>
  );
}

function CategoryFormModal({
  apiBase,
  category,
  groups,
  defaultGroupId,
  onClose,
  onSaved,
}: {
  apiBase: string;
  category?: ComponentCategory;
  groups: ComponentGroup[];
  defaultGroupId: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [groupId, setGroupId] = useState(category?.component_group_id ?? defaultGroupId);
  const [code, setCode] = useState(category?.code ?? '');
  const [name, setName] = useState(category?.name ?? '');
  const [description, setDescription] = useState(category?.description ?? '');
  const [sequence, setSequence] = useState(String(category?.sequence ?? 0));
  const [status, setStatus] = useState<'ACTIVE' | 'INACTIVE'>(category?.status ?? 'ACTIVE');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const parentLocked = !!category?.is_used;

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    const payload: Record<string, unknown> = { name, description: description || null, sequence: Number(sequence), status };
    if (!parentLocked) payload.component_group_id = groupId;
    try {
      if (category) await apiClient.put(`${apiBase}/${category.id}`, payload);
      else await apiClient.post(apiBase, { ...payload, code });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      if (!apiError.errors) setFormError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={category ? 'Edit Category' : 'New Category'} onClose={onClose}>
      {formError && <ErrorState message={formError} />}
      <FormField label="Component Group" errors={errors.component_group_id} required>
        <select value={groupId} onChange={(e) => setGroupId(e.target.value)} disabled={parentLocked} style={inputStyle}>
          <option value="">Select…</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
            </option>
          ))}
        </select>
      </FormField>
      {parentLocked && <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>Used by Products — cannot be moved to another Component Group.</div>}
      <FormField label="Code" errors={errors.code} required={!category}>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))} disabled={!!category} style={{ ...inputStyle, fontFamily: 'monospace' }} placeholder="e.g. DISC_BRAKE" />
      </FormField>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label="Sort Order" errors={errors.sequence}>
        <NumericInput value={sequence} onChange={(e) => setSequence(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Status" errors={errors.status}>
        <select value={status} onChange={(e) => setStatus(e.target.value as 'ACTIVE' | 'INACTIVE')} style={inputStyle}>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !groupId || !name || (!category && !code)} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}

// ============================================================================ Subcategories

export function ComponentSubcategoryManager({ endpoints, canManageRow, intro }: { endpoints: Endpoints; canManageRow: (row: ComponentSubcategory) => boolean; intro?: ReactNode }) {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [groupId, setGroupId] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [itemType, setItemType] = useState('');
  const [status, setStatus] = useState('');
  const [view, setView] = useState<View>('active');
  const [sort, setSort] = useState('sequence');
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [editing, setEditing] = useState<ComponentSubcategory | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<ComponentSubcategory | null>(null);
  const reload = () => setReloadKey((k) => k + 1);
  const groups = useGroups(endpoints.groups);
  const categories = useCategories(endpoints.categories, groupId);
  const { actionError, remove, restore } = useRowActions(endpoints.subcategories, reload);

  const { data, meta, loading, error } = useApiList<ComponentSubcategory>(
    endpoints.subcategories,
    {
      search,
      component_group_id: groupId || undefined,
      component_category_id: categoryId || undefined,
      item_type: itemType || undefined,
      status: status || undefined,
      trashed: trashedParam(view),
      sort,
      direction,
      page,
      per_page: 25,
    },
    reloadKey,
  );

  const columns: Column<ComponentSubcategory>[] = [
    { key: 'code', header: 'Code', sortable: true, render: (s) => <span style={{ fontFamily: 'monospace', fontSize: 13 }}>{s.code}</span> },
    { key: 'name', header: 'Name', sortable: true, render: (s) => s.name },
    { key: 'group', header: 'Component Group', render: (s) => (s.category?.component_group ? componentGroupLabel(s.category.component_group) + deletedSuffix(s.category.component_group) : '—') },
    { key: 'category', header: 'Category', render: (s) => (s.category ? s.category.name + deletedSuffix(s.category) : '—') },
    {
      key: 'item_types',
      header: 'Allowed Item Types',
      render: (s) => ((s.item_types ?? []).length ? (s.item_types ?? []).map((t) => ITEM_TYPE_LABELS[t]).join(', ') : <span style={muted}>Any</span>),
    },
    { key: 'description', header: 'Description', render: (s) => <span style={muted}>{s.description || '—'}</span> },
    { key: 'status', header: 'Status', sortable: true, render: (s) => <StatusCell row={s} /> },
    { key: 'usage', header: 'Usage', render: (s) => (s.is_used ? 'Used' : <span style={muted}>Unused</span>) },
    { key: 'updated_at', header: 'Updated', sortable: true, render: (s) => (s.updated_at ? formatTimestampDate(s.updated_at) : '—') },
    {
      key: 'actions',
      header: '',
      render: (s) => (
        <div style={{ display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {canManageRow(s) && !s.is_deleted && hasPermission('component_subcategory.update') && (
            <button className="btn-link" onClick={() => setEditing(s)}>
              Edit
            </button>
          )}
          {canManageRow(s) && !s.is_deleted && hasPermission('component_subcategory.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(s)}>
              Delete
            </button>
          )}
          {canManageRow(s) && s.is_deleted && hasPermission('component_subcategory.delete') && (
            <button className="btn-link" onClick={() => restore(s.id)}>
              Restore
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 8 }}>Component Subcategories</h1>
      {intro && <p style={{ ...muted, fontSize: 13, marginTop: 0, marginBottom: 16 }}>{intro}</p>}
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('component_subcategory.create') && (
            <button className="btn-primary" onClick={() => setCreating(true)}>
              New Subcategory
            </button>
          )
        }
      >
        <Filters view={view} setView={(v) => { setView(v); setPage(1); }} status={status} setStatus={(v) => { setStatus(v); setPage(1); }}>
          <select
            aria-label="Component Group filter"
            value={groupId}
            onChange={(e) => {
              setGroupId(e.target.value);
              setCategoryId('');
              setPage(1);
            }}
            style={{ ...inputStyle, width: 200 }}
          >
            <option value="">All component groups</option>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>
                {componentGroupLabel(g)}
              </option>
            ))}
          </select>
          <select aria-label="Category filter" value={categoryId} disabled={!groupId} onChange={(e) => { setCategoryId(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 180 }}>
            <option value="">All categories</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name + deletedSuffix(c)}
              </option>
            ))}
          </select>
          <select aria-label="Item Type filter" value={itemType} onChange={(e) => { setItemType(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 150 }}>
            <option value="">Any Item Type</option>
            {ITEM_TYPES.map((t) => (
              <option key={t} value={t}>
                {ITEM_TYPE_LABELS[t]}
              </option>
            ))}
          </select>
        </Filters>
      </Toolbar>
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No subcategories found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table
            columns={columns}
            rows={data}
            sort={sort}
            direction={direction}
            onSort={(key) => {
              if (sort === key) setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
              else {
                setSort(key);
                setDirection('asc');
              }
            }}
          />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      {(creating || editing) && (
        <SubcategoryFormModal
          endpoints={endpoints}
          subcategory={editing ?? undefined}
          groups={groups.filter((g) => isLive(g) || g.id === editing?.category?.component_group_id)}
          defaultGroupId={groupId}
          defaultCategoryId={categoryId}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={() => {
            setCreating(false);
            setEditing(null);
            reload();
          }}
        />
      )}
      <ConfirmDialog
        open={!!deleting}
        title="Delete Subcategory"
        message={`Delete "${deleting?.name}"? ${DELETE_MESSAGE}`}
        confirmLabel="Delete"
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) remove(deleting.id);
          setDeleting(null);
        }}
      />
    </div>
  );
}

function SubcategoryFormModal({
  endpoints,
  subcategory,
  groups,
  defaultGroupId,
  defaultCategoryId,
  onClose,
  onSaved,
}: {
  endpoints: Endpoints;
  subcategory?: ComponentSubcategory;
  groups: ComponentGroup[];
  defaultGroupId: string;
  defaultCategoryId: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [groupId, setGroupId] = useState(subcategory?.category?.component_group_id ?? defaultGroupId);
  const [categoryId, setCategoryId] = useState(subcategory?.component_category_id ?? defaultCategoryId);
  const [code, setCode] = useState(subcategory?.code ?? '');
  const [name, setName] = useState(subcategory?.name ?? '');
  const [description, setDescription] = useState(subcategory?.description ?? '');
  const [sequence, setSequence] = useState(String(subcategory?.sequence ?? 0));
  const [status, setStatus] = useState<'ACTIVE' | 'INACTIVE'>(subcategory?.status ?? 'ACTIVE');
  const [itemTypes, setItemTypes] = useState<ComponentItemType[]>(subcategory?.item_types ?? []);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const categories = useCategories(endpoints.categories, groupId);
  const parentLocked = !!subcategory?.is_used;

  function toggleItemType(type: ComponentItemType) {
    setItemTypes((current) => (current.includes(type) ? current.filter((t) => t !== type) : [...current, type]));
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    const payload: Record<string, unknown> = { name, description: description || null, sequence: Number(sequence), status, item_types: itemTypes };
    if (!parentLocked) payload.component_category_id = categoryId;
    try {
      if (subcategory) await apiClient.put(`${endpoints.subcategories}/${subcategory.id}`, payload);
      else await apiClient.post(endpoints.subcategories, { ...payload, code });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      if (!apiError.errors) setFormError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  const categoryOptions = categories.filter((c) => isLive(c) || c.id === subcategory?.component_category_id);

  return (
    <Modal open title={subcategory ? 'Edit Subcategory' : 'New Subcategory'} onClose={onClose} width={520}>
      {formError && <ErrorState message={formError} />}
      <FormField label="Component Group" required>
        <select
          value={groupId}
          disabled={parentLocked}
          onChange={(e) => {
            setGroupId(e.target.value);
            setCategoryId('');
          }}
          style={inputStyle}
        >
          <option value="">Select…</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Category" errors={errors.component_category_id} required>
        <select value={categoryId} disabled={parentLocked || !groupId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
          <option value="">{groupId ? 'Select…' : 'Select a Component Group first'}</option>
          {categoryOptions.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name + deletedSuffix(c)}
            </option>
          ))}
        </select>
      </FormField>
      {parentLocked && <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>Used by Products — cannot be moved to another Category.</div>}
      <FormField label="Code" errors={errors.code} required={!subcategory}>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))} disabled={!!subcategory} style={{ ...inputStyle, fontFamily: 'monospace' }} placeholder="e.g. BRAKE_PAD" />
      </FormField>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Allowed Item Types" errors={errors.item_types}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px 16px' }}>
          {ITEM_TYPES.map((t) => (
            <label key={t} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input type="checkbox" checked={itemTypes.includes(t)} onChange={() => toggleItemType(t)} />
              {ITEM_TYPE_LABELS[t]}
            </label>
          ))}
        </div>
      </FormField>
      <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>Leave all unchecked for no restriction. Enforced when a Product selects this Subcategory.</div>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label="Sort Order" errors={errors.sequence}>
        <NumericInput value={sequence} onChange={(e) => setSequence(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Status" errors={errors.status}>
        <select value={status} onChange={(e) => setStatus(e.target.value as 'ACTIVE' | 'INACTIVE')} style={inputStyle}>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !categoryId || !name || (!subcategory && !code)} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
