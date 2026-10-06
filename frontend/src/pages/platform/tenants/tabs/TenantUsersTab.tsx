import { useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../../api/client';
import { FormField, inputStyle } from '../../../../components/FormField';
import { Modal } from '../../../../components/Modal';
import { StatusBadge } from '../../../../components/StatusBadge';
import { Table, type Column } from '../../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../../components/States';
import { useApiList } from '../../../../hooks/useApiList';
import { useAuth } from '../../../../auth/AuthContext';
import { t } from '../../../../i18n/i18n';

interface TenantMembershipRow {
  id: string;
  user_id: string;
  name: string;
  email: string;
  status: string;
  roles: string[];
}

export function TenantUsersTab({ tenantId }: { tenantId: string }) {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showInvite, setShowInvite] = useState(false);
  const { data, loading, error } = useApiList<TenantMembershipRow>(`/platform/tenants/${tenantId}/users`, {}, reloadKey);

  async function toggleStatus(row: TenantMembershipRow) {
    const status = row.status === 'active' ? 'inactive' : 'active';
    await apiClient.patch(`/platform/tenants/${tenantId}/users/${row.id}`, { status });
    setReloadKey((k) => k + 1);
  }

  const columns: Column<TenantMembershipRow>[] = [
    { key: 'name', header: t('common.fields.name'), render: (r) => r.name },
    { key: 'email', header: t('common.fields.email'), render: (r) => r.email },
    { key: 'roles', header: t('common.fields.roles'), render: (r) => r.roles.join(', ') || '—' },
    { key: 'status', header: t('common.fields.status'), render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions',
      header: '',
      render: (r) =>
        hasPermission('user.update') ? (
          <button className="btn-link" onClick={() => toggleStatus(r)}>
            {r.status === 'active' ? t('common.actions.deactivate') : t('common.actions.activate')}
          </button>
        ) : null,
    },
  ];

  return (
    <div>
      {hasPermission('user.create') && (
        <div style={{ marginBottom: 12, textAlign: 'right' }}>
          <button className="btn-primary" onClick={() => setShowInvite(true)}>
            {t('platform.tenants.actions.addUser')}
          </button>
        </div>
      )}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('platform.tenants.empty.noUsersTenant')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data.map((d) => ({ ...d, id: d.id }))} />}

      <InviteUserModal
        tenantId={tenantId}
        open={showInvite}
        onClose={() => setShowInvite(false)}
        onCreated={() => setReloadKey((k) => k + 1)}
      />
    </div>
  );
}

function InviteUserModal({
  tenantId,
  open,
  onClose,
  onCreated,
}: {
  tenantId: string;
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/platform/tenants/${tenantId}/users`, { name, email, password });
      setName('');
      setEmail('');
      setPassword('');
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
    <Modal open={open} title={t('platform.tenants.modals.addTenantUser')} onClose={onClose}>
      <FormField label={t('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.email')} errors={errors.email} required>
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.password')} errors={errors.password} required>
        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? t('common.actions.adding') : t('platform.tenants.actions.addUser2')}
        </button>
      </div>
    </Modal>
  );
}
