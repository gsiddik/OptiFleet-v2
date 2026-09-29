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
import type { ComponentGroup } from '../../types';
import { abbreviationError, componentGroupLabel, normalizeAbbreviationInput } from '../../utils/componentGroup';

type View = 'active' | 'deleted' | 'all';

const muted = { color: '#6b7280', fontSize: 12 };

/**
 * Component Group Master list + create/edit/soft-delete/restore, shared by the
 * tenant portal (/app/component-groups — own groups editable, platform
 * baseline read-only) and the platform portal (/platform/component-groups —
 * the shared baseline). The backend stays authoritative for every rule shown
 * here (abbreviation format, uniqueness, SKU lock, permissions).
 */
export function ComponentGroupManager({
  apiBase,
  canManageRow,
  extraActions,
  intro,
}: {
  apiBase: string;
  canManageRow: (group: ComponentGroup) => boolean;
  extraActions?: (group: ComponentGroup, reload: () => void) => ReactNode;
  intro?: ReactNode;
}) {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [view, setView] = useState<View>('active');
  const [sort, setSort] = useState('sequence');
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [editing, setEditing] = useState<ComponentGroup | null>(null);
  const [creating, setCreating] = useState(false);
  const [deleting, setDeleting] = useState<ComponentGroup | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [allGroups, setAllGroups] = useState<ComponentGroup[]>([]);

  const reload = () => setReloadKey((k) => k + 1);
  const trashed = view === 'deleted' ? 'only' : view === 'all' ? 'with' : undefined;

  const { data, meta, loading, error } = useApiList<ComponentGroup>(
    apiBase,
    { search, status: status || undefined, trashed, sort, direction, page, per_page: 20 },
    reloadKey,
  );

  useEffect(() => {
    apiClient
      .get(apiBase, { params: { per_page: 200, trashed: 'with' } })
      .then((res) => setAllGroups(res.data.data))
      .catch(() => setAllGroups([]));
  }, [apiBase, reloadKey]);

  function onSort(key: string) {
    if (sort === key) setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
    else {
      setSort(key);
      setDirection('asc');
    }
    setPage(1);
  }

  async function confirmDelete() {
    if (!deleting) return;
    setActionError(null);
    try {
      await apiClient.delete(`${apiBase}/${deleting.id}`);
      setDeleting(null);
      reload();
    } catch (err) {
      setDeleting(null);
      setActionError(extractApiError(err).message);
    }
  }

  async function restore(group: ComponentGroup) {
    setActionError(null);
    try {
      await apiClient.post(`${apiBase}/${group.id}/restore`);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    }
  }

  const parentLabel = (id: string | null) => (id ? componentGroupLabel(allGroups.find((g) => g.id === id)) : '—');

  const columns: Column<ComponentGroup>[] = [
    { key: 'code', header: 'Code', sortable: true, render: (g) => g.code },
    {
      key: 'abbreviation',
      header: 'Abbr.',
      sortable: true,
      render: (g) =>
        g.abbreviation ? (
          <strong style={{ fontFamily: 'monospace', fontSize: 14, letterSpacing: 1 }}>{g.abbreviation}</strong>
        ) : (
          <span style={{ color: '#b45309', fontSize: 12 }} title="Legacy group: set a 3-letter abbreviation">
            Missing
          </span>
        ),
    },
    { key: 'name', header: 'Name', sortable: true, render: (g) => g.name },
    { key: 'parent_id', header: 'Parent', render: (g) => parentLabel(g.parent_id) },
    {
      key: 'description',
      header: 'Description',
      render: (g) => (
        <span title={g.description ?? ''} style={{ display: 'inline-block', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {g.description || '—'}
        </span>
      ),
    },
    { key: 'is_system', header: 'Source', render: (g) => (g.is_system ? 'System' : 'Tenant') },
    { key: 'status', header: 'Status', sortable: true, render: (g) => (g.is_deleted ? <StatusBadge status="Deleted" /> : <StatusBadge status={g.status} />) },
    {
      key: 'usage',
      header: 'Usage',
      render: (g) => (g.is_used ? <span title="Used by Products — abbreviation locked">Used</span> : <span style={muted}>Unused</span>),
    },
    { key: 'updated_at', header: 'Updated', sortable: true, render: (g) => (g.updated_at ? new Date(g.updated_at).toLocaleDateString() : '—') },
    {
      key: 'actions',
      header: '',
      render: (g) => (
        <div style={{ display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {extraActions?.(g, reload)}
          {canManageRow(g) && !g.is_deleted && hasPermission('component_group.update') && (
            <button className="btn-link" onClick={() => setEditing(g)}>
              Edit
            </button>
          )}
          {canManageRow(g) && !g.is_deleted && hasPermission('component_group.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(g)}>
              Delete
            </button>
          )}
          {canManageRow(g) && g.is_deleted && hasPermission('component_group.delete') && (
            <button className="btn-link" onClick={() => restore(g)}>
              Restore
            </button>
          )}
        </div>
      ),
    },
  ];

  const deleteMessage = deleting
    ? `Delete "${componentGroupLabel(deleting)}"? This Component Group will no longer be available for new data. Existing Products and historical transactions will remain unchanged.` +
      (deleting.is_used
        ? ` It is already used by Products: their SKUs, classification and history keep "${deleting.abbreviation ?? deleting.name}", and the abbreviation can never be reused.`
        : '')
    : '';

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 8 }}>Component Groups</h1>
      {intro && <p style={{ ...muted, fontSize: 13, marginTop: 0, marginBottom: 16 }}>{intro}</p>}
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('component_group.create') && (
            <button className="btn-primary" onClick={() => setCreating(true)}>
              New Component Group
            </button>
          )
        }
      >
        <select
          aria-label="Status filter"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 140 }}
        >
          <option value="">All statuses</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
        <select
          aria-label="Record filter"
          value={view}
          onChange={(e) => {
            setView(e.target.value as View);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 160 }}
        >
          <option value="active">Not deleted</option>
          <option value="deleted">Deleted only</option>
          <option value="all">All records</option>
        </select>
      </Toolbar>
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No component groups found." />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} sort={sort} direction={direction} onSort={onSort} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      {(creating || editing) && (
        <ComponentGroupFormModal
          apiBase={apiBase}
          group={editing ?? undefined}
          parentOptions={allGroups.filter((g) => !g.is_deleted && g.status === 'ACTIVE')}
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
        title="Delete Component Group"
        message={deleteMessage}
        confirmLabel="Delete"
        onCancel={() => setDeleting(null)}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function ComponentGroupFormModal({
  apiBase,
  group,
  parentOptions,
  onClose,
  onSaved,
}: {
  apiBase: string;
  group?: ComponentGroup;
  parentOptions: ComponentGroup[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(group?.code ?? '');
  const [name, setName] = useState(group?.name ?? '');
  const [abbreviation, setAbbreviation] = useState(group?.abbreviation ?? '');
  const [abbreviationTouched, setAbbreviationTouched] = useState(false);
  const [parentId, setParentId] = useState(group?.parent_id ?? '');
  const [sequence, setSequence] = useState(group?.sequence?.toString() ?? '0');
  const [description, setDescription] = useState(group?.description ?? '');
  const [status, setStatus] = useState<'ACTIVE' | 'INACTIVE'>(group?.status ?? 'ACTIVE');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const locked = !!group?.abbreviation_locked;
  const inlineAbbreviationError = !locked && abbreviationTouched ? abbreviationError(abbreviation) : null;

  async function submit() {
    setAbbreviationTouched(true);
    if (!locked && abbreviationError(abbreviation)) return;

    setSubmitting(true);
    setErrors({});
    setFormError(null);
    const payload: Record<string, unknown> = {
      name,
      parent_id: parentId || null,
      sequence: Number(sequence),
      description: description || null,
      status,
    };
    if (!locked) payload.abbreviation = abbreviation;
    try {
      if (group) {
        await apiClient.put(`${apiBase}/${group.id}`, payload);
      } else {
        await apiClient.post(apiBase, { ...payload, code });
      }
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      if (!apiError.errors) setFormError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  const abbreviationErrors = [...(inlineAbbreviationError ? [inlineAbbreviationError] : []), ...(errors.abbreviation ?? [])];

  return (
    <Modal open title={group ? 'Edit Component Group' : 'New Component Group'} onClose={onClose}>
      {formError && <ErrorState message={formError} />}
      <FormField label="Code" errors={errors.code} required={!group}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!group} placeholder="e.g. CG-BRAKE" />
      </FormField>
      {group && <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>Code is the stable identifier and cannot be changed.</div>}
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Abbreviation" errors={abbreviationErrors} required={!locked}>
        <input
          value={abbreviation}
          onChange={(e) => setAbbreviation(normalizeAbbreviationInput(e.target.value))}
          onBlur={() => setAbbreviationTouched(true)}
          maxLength={3}
          minLength={3}
          pattern="[A-Z]{3}"
          disabled={locked}
          style={{ ...inputStyle, width: 110, fontFamily: 'monospace', letterSpacing: 2, textTransform: 'uppercase' }}
          placeholder="ENG"
        />
      </FormField>
      <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>
        {locked
          ? 'This abbreviation is already used by Products (Product SKUs) and can no longer be changed.'
          : 'Exactly 3 letters. Used as part of Product SKU. Example: ENG, BRK, HYD. It cannot be reused once assigned, and is locked after Products use this group.'}
      </div>
      <FormField label="Parent Group" errors={errors.parent_id}>
        <select value={parentId} onChange={(e) => setParentId(e.target.value)} style={inputStyle}>
          <option value="">— None (top-level) —</option>
          {parentOptions
            .filter((g) => g.id !== group?.id)
            .map((g) => (
              <option key={g.id} value={g.id}>
                {componentGroupLabel(g)}
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
        <button className="btn-primary" disabled={submitting || (!group && !code) || !name} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
