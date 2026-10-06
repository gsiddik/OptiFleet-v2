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
import { t as tt, translatedRecord } from '../../i18n/i18n';

/**
 * Category / Assembly and Subcategory / Component Family management, shared by
 * the tenant portal (baseline read-only, own rows editable) and the platform
 * portal (baseline editable). Parent pickers and filters are driven by the
 * backend lists; every rule (code uniqueness, re-parenting of used rows,
 * Item Type narrowing, effective availability) is enforced server-side.
 */

type View = 'active' | 'deleted' | 'all';

const ITEM_TYPE_LABELS: Record<ComponentItemType, string> = translatedRecord({
  SPARE_PART: 'Sparepart',
  CONSUMABLE: 'Consumable',
  TIRE: 'Tire',
  RIM: 'Rim',
  TOOL: 'Tools',
  EQUIPMENT: 'Equipment',
}, { SPARE_PART: 'masterData.itemType.sparePart', CONSUMABLE: 'masterData.itemType.consumable', TIRE: 'masterData.itemType.tire', RIM: 'masterData.itemType.rim', TOOL: 'masterData.itemType.tool', EQUIPMENT: 'masterData.itemType.equipment' });
const ITEM_TYPES = Object.keys(ITEM_TYPE_LABELS) as ComponentItemType[];
const muted = { color: '#6b7280', fontSize: 12 };

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
const deletedSuffix = (row?: { deleted_at?: string | null } | null) => (row?.deleted_at ? tt('common.fields.deletedMarker') : '');

function StatusCell({ row }: { row: { is_deleted?: boolean; status: string } }) {
  return row.is_deleted ? <StatusBadge status="Deleted" /> : <StatusBadge status={row.status} />;
}

function Filters({ children, view, setView, status, setStatus }: { children?: ReactNode; view: View; setView: (v: View) => void; status: string; setStatus: (s: string) => void }) {
  return (
    <>
      {children}
      <select aria-label={tt('common.fields.statusFilter')} value={status} onChange={(e) => setStatus(e.target.value)} style={{ ...inputStyle, width: 130 }}>
        <option value="">{tt('common.filters.allStatuses')}</option>
        <option value="ACTIVE">{tt('common.fields.active')}</option>
        <option value="INACTIVE">{tt('common.fields.inactive')}</option>
      </select>
      <select aria-label={tt('common.fields.recordFilter')} value={view} onChange={(e) => setView(e.target.value as View)} style={{ ...inputStyle, width: 140 }}>
        <option value="active">{tt('common.fields.notDeleted')}</option>
        <option value="deleted">{tt('common.fields.deletedOnly')}</option>
        <option value="all">{tt('common.filters.allRecords')}</option>
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
    { key: 'code', header: tt('common.fields.code'), sortable: true, render: (c) => <span style={{ fontFamily: 'monospace', fontSize: 13 }}>{c.code}</span> },
    { key: 'name', header: tt('common.fields.name'), sortable: true, render: (c) => c.name },
    { key: 'group', header: tt('common.fields.componentGroup'), render: (c) => (c.component_group ? componentGroupLabel(c.component_group) + deletedSuffix(c.component_group) : '—') },
    { key: 'description', header: tt('common.fields.description'), render: (c) => <span style={muted}>{c.description || '—'}</span> },
    { key: 'is_system', header: tt('common.fields.source'), render: (c) => (c.is_system ? tt('common.fields.system') : tt('common.fields.tenant')) },
    { key: 'status', header: tt('common.fields.status'), sortable: true, render: (c) => <StatusCell row={c} /> },
    { key: 'usage', header: tt('common.fields.usage'), render: (c) => (c.is_used ? tt('common.fields.used') : <span style={muted}>{tt('common.fields.unused')}</span>) },
    { key: 'updated_at', header: tt('common.fields.updated'), sortable: true, render: (c) => (c.updated_at ? formatTimestampDate(c.updated_at) : '—') },
    {
      key: 'actions',
      header: '',
      render: (c) => (
        <div style={{ display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {canManageRow(c) && !c.is_deleted && hasPermission('component_category.update') && (
            <button className="btn-link" onClick={() => setEditing(c)}>
              {tt('common.actions.edit')}
            </button>
          )}
          {canManageRow(c) && !c.is_deleted && hasPermission('component_category.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(c)}>
              {tt('common.actions.delete')}
            </button>
          )}
          {canManageRow(c) && c.is_deleted && hasPermission('component_category.delete') && (
            <button className="btn-link" onClick={() => restore(c.id)}>
              {tt('common.actions.restore')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 8 }}>{tt('common.titles.componentCategories')}</h1>
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
              {tt('common.actions.newCategory')}
            </button>
          )
        }
      >
        <Filters view={view} setView={(v) => { setView(v); setPage(1); }} status={status} setStatus={(v) => { setStatus(v); setPage(1); }}>
          <select aria-label={tt('common.fields.componentGroupFilter')} value={groupId} onChange={(e) => { setGroupId(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 220 }}>
            <option value="">{tt('common.filters.allComponentGroups')}</option>
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
      {!error && !loading && data.length === 0 && <EmptyState label={tt('common.empty.noCategoriesFound')} />}
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
        title={tt('common.confirm.deleteCategory')}
        message={tt('common.confirm.deleteNameDeleteMessageSubcategoriesBecome', { name: deleting?.name, DELETE_MESSAGE: tt('common.warnings.classificationNoLongerAvailableNewProducts') })}
        confirmLabel={tt('common.actions.delete')}
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
    <Modal open title={category ? tt('common.modals.editCategory') : tt('common.actions.newCategory')} onClose={onClose}>
      {formError && <ErrorState message={formError} />}
      <FormField label={tt('common.fields.componentGroup')} errors={errors.component_group_id} required>
        <select value={groupId} onChange={(e) => setGroupId(e.target.value)} disabled={parentLocked} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
            </option>
          ))}
        </select>
      </FormField>
      {parentLocked && <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>{tt('common.help.usedProductsCannotMovedAnotherComponent')}</div>}
      <FormField label={tt('common.fields.code')} errors={errors.code} required={!category}>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))} disabled={!!category} style={{ ...inputStyle, fontFamily: 'monospace' }} placeholder={tt('common.placeholders.eGDiscBrake')} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.description')} errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label={tt('common.fields.sortOrder')} errors={errors.sequence}>
        <NumericInput value={sequence} onChange={(e) => setSequence(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.status')} errors={errors.status}>
        <select value={status} onChange={(e) => setStatus(e.target.value as 'ACTIVE' | 'INACTIVE')} style={inputStyle}>
          <option value="ACTIVE">{tt('common.fields.active')}</option>
          <option value="INACTIVE">{tt('common.fields.inactive')}</option>
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !groupId || !name || (!category && !code)} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
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
    { key: 'code', header: tt('common.fields.code'), sortable: true, render: (s) => <span style={{ fontFamily: 'monospace', fontSize: 13 }}>{s.code}</span> },
    { key: 'name', header: tt('common.fields.name'), sortable: true, render: (s) => s.name },
    { key: 'group', header: tt('common.fields.componentGroup'), render: (s) => (s.category?.component_group ? componentGroupLabel(s.category.component_group) + deletedSuffix(s.category.component_group) : '—') },
    { key: 'category', header: tt('common.fields.category'), render: (s) => (s.category ? s.category.name + deletedSuffix(s.category) : '—') },
    {
      key: 'item_types',
      header: tt('common.fields.allowedItemTypes'),
      render: (s) => ((s.item_types ?? []).length ? (s.item_types ?? []).map((t) => ITEM_TYPE_LABELS[t]).join(', ') : <span style={muted}>{tt('common.fields.any')}</span>),
    },
    { key: 'description', header: tt('common.fields.description'), render: (s) => <span style={muted}>{s.description || '—'}</span> },
    { key: 'status', header: tt('common.fields.status'), sortable: true, render: (s) => <StatusCell row={s} /> },
    { key: 'usage', header: tt('common.fields.usage'), render: (s) => (s.is_used ? tt('common.fields.used') : <span style={muted}>{tt('common.fields.unused')}</span>) },
    { key: 'updated_at', header: tt('common.fields.updated'), sortable: true, render: (s) => (s.updated_at ? formatTimestampDate(s.updated_at) : '—') },
    {
      key: 'actions',
      header: '',
      render: (s) => (
        <div style={{ display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {canManageRow(s) && !s.is_deleted && hasPermission('component_subcategory.update') && (
            <button className="btn-link" onClick={() => setEditing(s)}>
              {tt('common.actions.edit')}
            </button>
          )}
          {canManageRow(s) && !s.is_deleted && hasPermission('component_subcategory.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(s)}>
              {tt('common.actions.delete')}
            </button>
          )}
          {canManageRow(s) && s.is_deleted && hasPermission('component_subcategory.delete') && (
            <button className="btn-link" onClick={() => restore(s.id)}>
              {tt('common.actions.restore')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 8 }}>{tt('common.titles.componentSubcategories')}</h1>
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
              {tt('common.actions.newSubcategory')}
            </button>
          )
        }
      >
        <Filters view={view} setView={(v) => { setView(v); setPage(1); }} status={status} setStatus={(v) => { setStatus(v); setPage(1); }}>
          <select
            aria-label={tt('common.fields.componentGroupFilter')}
            value={groupId}
            onChange={(e) => {
              setGroupId(e.target.value);
              setCategoryId('');
              setPage(1);
            }}
            style={{ ...inputStyle, width: 200 }}
          >
            <option value="">{tt('common.filters.allComponentGroups')}</option>
            {groups.map((g) => (
              <option key={g.id} value={g.id}>
                {componentGroupLabel(g)}
              </option>
            ))}
          </select>
          <select aria-label={tt('common.fields.categoryFilter')} value={categoryId} disabled={!groupId} onChange={(e) => { setCategoryId(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 180 }}>
            <option value="">{tt('common.filters.allCategories')}</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name + deletedSuffix(c)}
              </option>
            ))}
          </select>
          <select aria-label={tt('common.fields.itemTypeFilter')} value={itemType} onChange={(e) => { setItemType(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 150 }}>
            <option value="">{tt('common.filters.anyItemType')}</option>
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
      {!error && !loading && data.length === 0 && <EmptyState label={tt('common.empty.noSubcategoriesFound')} />}
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
        title={tt('common.confirm.deleteSubcategory')}
        message={tt('common.confirm.deleteNameDeleteMessage', { name: deleting?.name, DELETE_MESSAGE: tt('common.warnings.classificationNoLongerAvailableNewProducts') })}
        confirmLabel={tt('common.actions.delete')}
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
    <Modal open title={subcategory ? tt('common.modals.editSubcategory') : tt('common.actions.newSubcategory')} onClose={onClose} width={520}>
      {formError && <ErrorState message={formError} />}
      <FormField label={tt('common.fields.componentGroup')} required>
        <select
          value={groupId}
          disabled={parentLocked}
          onChange={(e) => {
            setGroupId(e.target.value);
            setCategoryId('');
          }}
          style={inputStyle}
        >
          <option value="">{tt('common.fields.select')}</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('common.fields.category')} errors={errors.component_category_id} required>
        <select value={categoryId} disabled={parentLocked || !groupId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
          <option value="">{groupId ? tt('common.fields.select') : tt('common.fields.selectComponentGroupFirst')}</option>
          {categoryOptions.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name + deletedSuffix(c)}
            </option>
          ))}
        </select>
      </FormField>
      {parentLocked && <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>{tt('common.help.usedProductsCannotMovedAnotherCategory')}</div>}
      <FormField label={tt('common.fields.code')} errors={errors.code} required={!subcategory}>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))} disabled={!!subcategory} style={{ ...inputStyle, fontFamily: 'monospace' }} placeholder={tt('common.placeholders.eGBrakePad')} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.allowedItemTypes')} errors={errors.item_types}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px 16px' }}>
          {ITEM_TYPES.map((t) => (
            <label key={t} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
              <input type="checkbox" checked={itemTypes.includes(t)} onChange={() => toggleItemType(t)} />
              {ITEM_TYPE_LABELS[t]}
            </label>
          ))}
        </div>
      </FormField>
      <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>{tt('common.help.leaveAllUncheckedNoRestrictionEnforced')}</div>
      <FormField label={tt('common.fields.description')} errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label={tt('common.fields.sortOrder')} errors={errors.sequence}>
        <NumericInput value={sequence} onChange={(e) => setSequence(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.status')} errors={errors.status}>
        <select value={status} onChange={(e) => setStatus(e.target.value as 'ACTIVE' | 'INACTIVE')} style={inputStyle}>
          <option value="ACTIVE">{tt('common.fields.active')}</option>
          <option value="INACTIVE">{tt('common.fields.inactive')}</option>
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !categoryId || !name || (!subcategory && !code)} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
