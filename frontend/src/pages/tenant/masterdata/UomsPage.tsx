import { useState } from 'react';
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
import type { UomItem } from '../../../types';
import { t as tt } from '../../../i18n/i18n';

const MEASURE_TYPES = ['LENGTH', 'PACKAGING', 'CAPACITY', 'WEIGHT', 'PRESSURE'];

export function UomsPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<UomItem | null>(null);
  const [deleting, setDeleting] = useState<UomItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const { data, meta, loading, error } = useApiList<UomItem>('/app/uoms', { search: search || undefined, page, per_page: 15 }, reloadKey);

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/uoms/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const columns: Column<UomItem>[] = [
    { key: 'code', header: tt('common.fields.code'), render: (u) => u.code },
    { key: 'name', header: tt('common.fields.name'), render: (u) => u.name },
    { key: 'measure_type', header: tt('masterData.fields.typeOfMeasure'), render: (u) => u.measure_type ?? '—' },
    { key: 'description', header: tt('common.fields.description'), render: (u) => u.description ?? '—' },
    { key: 'is_system', header: tt('common.fields.source'), render: (u) => (u.is_system ? tt('common.fields.system') : tt('common.fields.tenant')) },
    { key: 'status', header: tt('common.fields.status'), render: (u) => <StatusBadge status={u.status} /> },
    {
      key: 'actions',
      header: '',
      render: (u) =>
        !u.is_system && (
          <div style={{ display: 'flex', gap: 8 }}>
            {hasPermission('product.update') && (
              <button className="btn-link" onClick={() => setEditing(u)}>
                {tt('common.actions.edit')}
              </button>
            )}
            {hasPermission('product.delete') && (
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(u)}>
                {tt('common.actions.delete')}
              </button>
            )}
          </div>
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('masterData.titles.unitsOfMeasure')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('product.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('masterData.actions.newUnit')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('masterData.empty.noUnitsMeasureFound')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      {showCreate && (
        <UomFormModal
          open
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            setShowCreate(false);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {editing && (
        <UomFormModal
          open
          uom={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title={tt('masterData.confirm.deleteUnitOfMeasure')}
        message={deleteError ?? tt('platform.masterdata.confirm.deleteNameCannotUndone', { name: deleting?.name })}
        confirmLabel={tt('common.actions.delete')}
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function UomFormModal({ open, uom, onClose, onSaved }: { open: boolean; uom?: UomItem; onClose: () => void; onSaved: () => void }) {
  const [code, setCode] = useState(uom?.code ?? '');
  const [name, setName] = useState(uom?.name ?? '');
  const [measureType, setMeasureType] = useState(uom?.measure_type ?? '');
  const [description, setDescription] = useState(uom?.description ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (uom) {
        await apiClient.put(`/app/uoms/${uom.id}`, { name, measure_type: measureType || null, description: description || null });
      } else {
        await apiClient.post('/app/uoms', { code, name, measure_type: measureType || undefined, description: description || undefined });
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
    <Modal open={open} title={uom ? tt('masterData.modals.editUnitOfMeasure') : tt('masterData.modals.newUnitOfMeasure')} onClose={onClose}>
      <FormField label={tt('common.fields.code')} errors={errors.code} required={!uom}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} disabled={!!uom} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required={!uom}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('masterData.fields.typeOfMeasure')} errors={errors.measure_type}>
        <select value={measureType ?? ''} onChange={(e) => setMeasureType(e.target.value)} style={inputStyle}>
          <option value="">{tt('masterData.fields.none')}</option>
          {MEASURE_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('common.fields.description')} errors={errors.description}>
        <input value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
