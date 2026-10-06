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
import type { BundleItem } from '../../../types';
import { t } from '../../../i18n/i18n';

export function BundleListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<BundleItem>('/platform/bundles', { search }, reloadKey);

  const columns: Column<BundleItem>[] = [
    { key: 'code', header: t('common.fields.code'), render: (b) => <Link to={`/platform/bundles/${b.id}`}>{b.code}</Link> },
    { key: 'name', header: t('common.fields.name'), render: (b) => b.name },
    { key: 'modules', header: t('platform.bundles.fields.modules'), render: (b) => b.modules?.length ?? 0 },
    { key: 'status', header: t('common.fields.status'), render: (b) => <StatusBadge status={b.status} /> },
    { key: 'is_active', header: t('common.fields.active'), render: (b) => <StatusBadge status={b.is_active ? 'ACTIVE' : 'INACTIVE'} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('platform.bundles.titles.bundles')}</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('bundle.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('platform.bundles.actions.newBundle')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('platform.bundles.empty.noBundlesFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateBundleModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateBundleModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/platform/bundles', { code: code.toUpperCase(), name, description });
      setCode('');
      setName('');
      setDescription('');
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
    <Modal open={open} title={t('platform.bundles.modals.newBundle')} onClose={onClose}>
      <FormField label={t('common.fields.code')} errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} placeholder={t('platform.bundles.placeholders.eGOptifleetCustom')} />
      </FormField>
      <FormField label={t('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.description')} errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? t('common.actions.creating') : t('platform.bundles.actions.createBundle')}
        </button>
      </div>
    </Modal>
  );
}
