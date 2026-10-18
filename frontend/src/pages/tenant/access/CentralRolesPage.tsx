import { useState } from 'react';
import { classifyFailure, delegationApi, extractApiError, type DelegatedRole } from '../../../api/delegation';
import type { ApiErrorShape } from '../../../api/client';
import { useAuth } from '../../../auth/AuthContext';
import { CentralNotice, FailurePanel } from '../../../components/delegation/DelegationPanels';
import { DelegatedRoleFormModal } from '../../../components/delegation/DelegatedRoleFormModal';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState, LoadingState } from '../../../components/States';
import { useDelegationResource } from '../../../hooks/useDelegationResource';
import { t } from '../../../i18n/i18n';

/**
 * Roles of an organization whose access is managed centrally (OptiNexus): custom roles built only from the permissions that
 * can be delegated, plus the roles the platform provides. OptiFleet renders what OptiNexus answers and sends changes there.
 */
export function CentralRolesPage() {
  const { hasPermission } = useAuth();
  const catalog = useDelegationResource(() => delegationApi.catalog(), []);
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<DelegatedRole | null>(null);
  const [deleting, setDeleting] = useState<DelegatedRole | null>(null);
  const [actionError, setActionError] = useState<ApiErrorShape | null>(null);

  async function remove(role: DelegatedRole) {
    setActionError(null);
    try {
      await delegationApi.deleteRole(role.id);
    } catch (err) {
      setActionError(extractApiError(err));
    } finally {
      setDeleting(null);
      catalog.reload();
    }
  }

  const roles = catalog.status === 'ready' ? catalog.data.roles : [];
  const canEdit = hasPermission('role.update');

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('access.titles.rolesAndPermissions')}</h1>
      <CentralNotice />
      {catalog.status === 'ready' && hasPermission('role.create') && (
        <div style={{ marginBottom: 12, textAlign: 'right' }}>
          <button className="btn-primary" onClick={() => setCreating(true)}>
            {t('common.actions.newRole')}
          </button>
        </div>
      )}
      {actionError && (
        <div style={{ marginBottom: 12 }}>
          <FailurePanel kind={classifyFailure(actionError)} error={actionError} />
        </div>
      )}

      {catalog.status === 'loading' && <LoadingState label={t('common.actions.loading')} />}
      {(catalog.status === 'forbidden' || catalog.status === 'unavailable' || catalog.status === 'error') && (
        <FailurePanel kind={catalog.status} error={catalog.error} onRetry={catalog.reload} />
      )}
      {catalog.status === 'ready' && (
        <>
          <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('delegation.roles.delegableCount', { count: catalog.data.delegable_count })}</p>
          {roles.length === 0 && <EmptyState label={t('delegation.roles.empty')} />}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 14 }}>
            {roles.map((role) => (
              <div key={role.id} className="card">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                  <strong>{role.name}</strong>
                  {role.template && <span style={{ fontSize: 11, color: '#9ca3af' }}>{t('delegation.roles.template')}</span>}
                </div>
                {role.description && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{role.description}</div>}
                <div style={{ fontSize: 12, color: '#6b7280', margin: '6px 0 10px' }}>{t('common.fields.permissionCount', { count: role.permissions.length })}</div>
                {role.editable && (
                  <div style={{ display: 'flex', gap: 12 }}>
                    {canEdit && (
                      <button className="btn-link" onClick={() => setEditing(role)}>
                        {t('common.actions.editRoleAndPermissions')}
                      </button>
                    )}
                    {canEdit && (
                      <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(role)}>
                        {t('common.actions.delete')}
                      </button>
                    )}
                  </div>
                )}
                {!role.editable && <div style={{ fontSize: 11, color: '#9ca3af' }}>{t('delegation.roles.notEditable')}</div>}
              </div>
            ))}
          </div>

          {(creating || editing) && (
            <DelegatedRoleFormModal
              modules={catalog.data.modules}
              role={editing ?? undefined}
              canEditPermissions={creating || hasPermission('role.assign_permission')}
              onClose={() => {
                setCreating(false);
                setEditing(null);
              }}
              onSaved={() => {
                setCreating(false);
                setEditing(null);
                catalog.reload();
              }}
            />
          )}
        </>
      )}
      <ConfirmDialog
        open={deleting !== null}
        title={t('delegation.roles.deleteTitle')}
        message={t('delegation.roles.deleteConfirm', { name: deleting?.name ?? '' })}
        confirmLabel={t('common.actions.delete')}
        onConfirm={() => deleting && remove(deleting)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
