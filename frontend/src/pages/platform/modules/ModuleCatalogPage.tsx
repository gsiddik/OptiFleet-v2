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
import { t } from '../../../i18n/i18n';

export function ModuleCatalogPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ModuleCatalogItem>('/platform/modules', { search }, reloadKey);

  const columns: Column<ModuleCatalogItem>[] = [
    { key: 'code', header: t('common.fields.code'), render: (m) => <Link to={`/platform/modules/${m.id}`}>{m.code}</Link> },
    { key: 'name', header: t('common.fields.name'), render: (m) => m.name },
    { key: 'category', header: t('common.fields.category'), render: (m) => m.category },
    { key: 'is_core', header: t('platform.modules.fields.core'), render: (m) => (m.is_core ? t('common.fields.yes') : t('common.fields.no')) },
    { key: 'is_sellable', header: t('platform.modules.fields.sellable'), render: (m) => (m.is_sellable ? t('common.fields.yes') : t('common.fields.no')) },
    { key: 'status', header: t('common.fields.status'), render: (m) => <StatusBadge status={m.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('platform.modules.titles.moduleCatalog')}</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('module.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('platform.modules.actions.newModule')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('platform.modules.empty.noModulesFound')} />}
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
    <Modal open={open} title={t('platform.modules.modals.newModule')} onClose={onClose}>
      <FormField label={t('common.fields.code')} errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.category')} errors={errors.category} required>
        <input value={category} onChange={(e) => setCategory(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? t('common.actions.creating') : t('platform.modules.actions.createModule')}
        </button>
      </div>
    </Modal>
  );
}
