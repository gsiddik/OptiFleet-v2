import { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { Tenant } from '../../../types';
import { formatTimestampDate } from '../../../utils/date';
import { t as tt } from '../../../i18n/i18n';

export function TenantListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState('created_at');
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);

  const { data, meta, loading, error } = useApiList<Tenant>(
    '/platform/tenants',
    { search, status, page, sort, direction, per_page: 10 },
    reloadKey,
  );

  const columns: Column<Tenant>[] = [
    { key: 'code', header: tt('common.fields.code'), sortable: true, render: (t) => <Link to={`/platform/tenants/${t.id}`}>{t.code}</Link> },
    { key: 'name', header: tt('common.fields.name'), sortable: true, render: (t) => t.name },
    { key: 'status', header: tt('common.fields.status'), sortable: true, render: (t) => <StatusBadge status={t.status} /> },
    { key: 'created_at', header: tt('platform.tenants.fields.created'), sortable: true, render: (t) => formatTimestampDate(t.created_at) },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.tenants.titles.tenantManagement')}</h1>

      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('tenant.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('platform.tenants.actions.newTenant')}
            </button>
          ) : null
        }
      >
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 160 }}
        >
          <option value="">{tt('common.filters.allStatuses')}</option>
          <option value="DRAFT">{tt('common.fields.draft')}</option>
          <option value="ACTIVE">{tt('common.fields.active')}</option>
          <option value="INACTIVE">{tt('common.fields.inactive')}</option>
          <option value="SUSPENDED">{tt('platform.tenants.fields.suspended')}</option>
        </select>
      </Toolbar>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.tenants.empty.noTenantsFound')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table
            columns={columns}
            rows={data}
            sort={sort}
            direction={direction}
            onSort={(key) => {
              if (sort === key) setDirection(direction === 'asc' ? 'desc' : 'asc');
              else {
                setSort(key);
                setDirection('asc');
              }
            }}
          />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <CreateTenantModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateTenantModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  function reset() {
    setCode('');
    setName('');
    setErrors({});
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/platform/tenants', { code, name });
      reset();
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
    <Modal open={open} title={tt('platform.tenants.modals.newTenant')} onClose={onClose}>
      <FormField label={tt('common.fields.code')} errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} placeholder={tt('platform.tenants.placeholders.eGAcme')} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} placeholder={tt('platform.tenants.placeholders.companyName')} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('common.actions.creating') : tt('platform.tenants.actions.createTenant')}
        </button>
      </div>
    </Modal>
  );
}
