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
import { NumericInput } from '../NumericInput';
import { formatTimestampDate } from '../../utils/date';
import { t } from '../../i18n/i18n';

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
    { key: 'code', header: t('common.fields.code'), sortable: true, render: (g) => g.code },
    {
      key: 'abbreviation',
      header: t('common.fields.abbr'),
      sortable: true,
      render: (g) =>
        g.abbreviation ? (
          <strong style={{ fontFamily: 'monospace', fontSize: 14, letterSpacing: 1 }}>{g.abbreviation}</strong>
        ) : (
          <span style={{ color: '#b45309', fontSize: 12 }} title={t('common.tooltips.legacyGroupSet3LetterAbbreviation')}>
            {t('common.fields.missing')}
          </span>
        ),
    },
    { key: 'name', header: t('common.fields.name'), sortable: true, render: (g) => g.name },
    { key: 'parent_id', header: t('common.fields.parent'), render: (g) => parentLabel(g.parent_id) },
    {
      key: 'description',
      header: t('common.fields.description'),
      render: (g) => (
        <span title={g.description ?? ''} style={{ display: 'inline-block', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {g.description || '—'}
        </span>
      ),
    },
    { key: 'is_system', header: t('common.fields.source'), render: (g) => (g.is_system ? t('common.fields.system') : t('common.fields.tenant')) },
    { key: 'status', header: t('common.fields.status'), sortable: true, render: (g) => (g.is_deleted ? <StatusBadge status="Deleted" /> : <StatusBadge status={g.status} />) },
    {
      key: 'usage',
      header: t('common.fields.usage'),
      render: (g) => (g.is_used ? <span title={t('common.tooltips.usedProductsAbbreviationLocked')}>{t('common.fields.used')}</span> : <span style={muted}>{t('common.fields.unused')}</span>),
    },
    { key: 'updated_at', header: t('common.fields.updated'), sortable: true, render: (g) => (g.updated_at ? formatTimestampDate(g.updated_at) : '—') },
    {
      key: 'actions',
      header: '',
      render: (g) => (
        <div style={{ display: 'flex', gap: 8, whiteSpace: 'nowrap' }}>
          {extraActions?.(g, reload)}
          {canManageRow(g) && !g.is_deleted && hasPermission('component_group.update') && (
            <button className="btn-link" onClick={() => setEditing(g)}>
              {t('common.actions.edit')}
            </button>
          )}
          {canManageRow(g) && !g.is_deleted && hasPermission('component_group.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(g)}>
              {t('common.actions.delete')}
            </button>
          )}
          {canManageRow(g) && g.is_deleted && hasPermission('component_group.delete') && (
            <button className="btn-link" onClick={() => restore(g)}>
              {t('common.actions.restore')}
            </button>
          )}
        </div>
      ),
    },
  ];

  const deleteMessage = deleting
    ? t('common.warnings.deleteValueComponentGroupNoLonger', { value: componentGroupLabel(deleting) }) +
      (deleting.is_used
        ? t('common.help.alreadyUsedProductsTheirSkusClassification', { value: deleting.abbreviation ?? deleting.name })
        : '')
    : '';

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 8 }}>{t('common.titles.componentGroups')}</h1>
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
              {t('common.actions.newComponentGroup')}
            </button>
          )
        }
      >
        <select
          aria-label={t('common.fields.statusFilter')}
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 140 }}
        >
          <option value="">{t('common.filters.allStatuses')}</option>
          <option value="ACTIVE">{t('common.fields.active')}</option>
          <option value="INACTIVE">{t('common.fields.inactive')}</option>
        </select>
        <select
          aria-label={t('common.fields.recordFilter')}
          value={view}
          onChange={(e) => {
            setView(e.target.value as View);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 160 }}
        >
          <option value="active">{t('common.fields.notDeleted')}</option>
          <option value="deleted">{t('common.fields.deletedOnly')}</option>
          <option value="all">{t('common.filters.allRecords')}</option>
        </select>
      </Toolbar>
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('common.empty.noComponentGroupsFound')} />}
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
        title={t('common.confirm.deleteComponentGroup')}
        message={deleteMessage}
        confirmLabel={t('common.actions.delete')}
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
    <Modal open title={group ? t('common.modals.editComponentGroup') : t('common.actions.newComponentGroup')} onClose={onClose}>
      {formError && <ErrorState message={formError} />}
      <FormField label={t('common.fields.code')} errors={errors.code} required={!group}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!group} placeholder={t('common.placeholders.eGCgBrake')} />
      </FormField>
      {group && <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>{t('common.help.codeStableIdentifierCannotChanged')}</div>}
      <FormField label={t('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.abbreviation')} errors={abbreviationErrors} required={!locked}>
        <input
          value={abbreviation}
          onChange={(e) => setAbbreviation(normalizeAbbreviationInput(e.target.value))}
          onBlur={() => setAbbreviationTouched(true)}
          maxLength={3}
          minLength={3}
          pattern="[A-Z]{3}"
          disabled={locked}
          style={{ ...inputStyle, width: 110, fontFamily: 'monospace', letterSpacing: 2, textTransform: 'uppercase' }}
          placeholder={t('common.placeholders.eng')}
        />
      </FormField>
      <div style={{ ...muted, marginTop: -10, marginBottom: 12 }}>
        {locked
          ? t('common.help.abbreviationAlreadyUsedProductsProductSkus')
          : t('common.help.exactly3LettersUsedPartProduct')}
      </div>
      <FormField label={t('common.fields.parentGroup')} errors={errors.parent_id}>
        <select value={parentId} onChange={(e) => setParentId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.noneTopLevel')}</option>
          {parentOptions
            .filter((g) => g.id !== group?.id)
            .map((g) => (
              <option key={g.id} value={g.id}>
                {componentGroupLabel(g)}
              </option>
            ))}
        </select>
      </FormField>
      <FormField label={t('common.fields.sequence')} errors={errors.sequence}>
        <NumericInput value={sequence} onChange={(e) => setSequence(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.description')} errors={errors.description}>
        <textarea value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <FormField label={t('common.fields.status')} errors={errors.status}>
        <select value={status} onChange={(e) => setStatus(e.target.value as 'ACTIVE' | 'INACTIVE')} style={inputStyle}>
          <option value="ACTIVE">{t('common.fields.active')}</option>
          <option value="INACTIVE">{t('common.fields.inactive')}</option>
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || (!group && !code) || !name} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
