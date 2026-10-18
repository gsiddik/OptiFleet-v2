import { useState } from 'react';
import { classifyFailure, delegationApi, extractApiError, type DelegatedRole, type DelegatedUser, type InvitationStatus } from '../../../api/delegation';
import type { ApiErrorShape } from '../../../api/client';
import { useAuth } from '../../../auth/AuthContext';
import { ActionFailure, CentralNotice, FailurePanel } from '../../../components/delegation/DelegationPanels';
import { InvitationsPanel } from '../../../components/delegation/InvitationsPanel';
import { defaultInvitationLocale, INVITATION_FILTER_OPTIONS } from '../../../components/delegation/invitationPresentation';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { DataScopeModal } from '../../../components/DataScopeModal';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { EmptyState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { useDelegationResource } from '../../../hooks/useDelegationResource';
import { t } from '../../../i18n/i18n';
import { appLocale } from '../../../i18n/locale';

type Tab = 'users' | 'invitations';

/**
 * Users of an organization whose access is managed centrally (OptiNexus). The list, the roles and every change come from the
 * OptiNexus delegation API through OptiFleet's backend; OptiFleet keeps no copy and no password. Who may do this is decided by
 * OptiNexus (organization administrators) on top of the user.* permissions checked here.
 *
 * A new person is invited, not created: OptiNexus e-mails the link, the person sets their own password there (which also verifies
 * the e-mail OptiFleet requires at sign-in) and then signs in here with OptiNexus. The Invitations tab follows each invitation.
 */
export function CentralUsersPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [tab, setTab] = useState<Tab>('users');
  const [inviting, setInviting] = useState(false);
  const [invitationStatus, setInvitationStatus] = useState<InvitationStatus | ''>('');
  const [invitationsVersion, setInvitationsVersion] = useState(0);
  const [editingRoles, setEditingRoles] = useState<DelegatedUser | null>(null);
  const [revoking, setRevoking] = useState<DelegatedUser | null>(null);
  const [scopeOf, setScopeOf] = useState<DelegatedUser | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<ApiErrorShape | null>(null);

  const users = useDelegationResource(() => delegationApi.users(search), [search]);
  const canSeeRoles = hasPermission('role.view');
  // The assignable roles are needed by the add and role dialogs only; a person without role.view just does not get them.
  const catalog = useDelegationResource(() => (canSeeRoles ? delegationApi.catalog() : Promise.resolve(null)), [canSeeRoles]);
  const roles: DelegatedRole[] = catalog.status === 'ready' && catalog.data ? catalog.data.roles : [];

  async function revoke(user: DelegatedUser) {
    setActionError(null);
    try {
      await delegationApi.revokeUser(user.id);
      setNotice(t('delegation.users.revoked', { name: user.name ?? user.email ?? '' }));
    } catch (err) {
      setActionError(extractApiError(err));
    } finally {
      setRevoking(null);
      users.reload();
    }
  }

  const tabButton = (value: Tab, text: string) => (
    <button
      role="tab"
      aria-selected={tab === value}
      onClick={() => {
        setTab(value);
        setNotice(null);
        setActionError(null);
      }}
      style={{
        padding: '8px 16px',
        fontSize: 14,
        fontWeight: 600,
        background: 'none',
        border: 'none',
        borderBottom: tab === value ? '2px solid #1d4ed8' : '2px solid transparent',
        color: tab === value ? '#1d4ed8' : '#6b7280',
        cursor: 'pointer',
      }}
    >
      {text}
    </button>
  );

  const columns: Column<DelegatedUser>[] = [
    { key: 'name', header: t('common.fields.name'), render: (u) => u.name ?? '—' },
    { key: 'email', header: t('common.fields.email'), render: (u) => u.email ?? '—' },
    {
      key: 'roles',
      header: t('access.sections.roles'),
      render: (u) => (
        <span style={{ display: 'inline-flex', flexWrap: 'wrap', gap: 4, alignItems: 'center' }}>
          {u.is_tenant_admin && <span style={chip('#fef3c7', '#92400e')}>{t('delegation.users.administrator')}</span>}
          {u.roles.map((r) => (
            <span key={r.id} style={chip('#eff6ff', '#1e3a8a')}>
              {r.name}
            </span>
          ))}
          {!u.is_tenant_admin && u.roles.length === 0 && <span style={{ color: '#9ca3af' }}>{t('delegation.users.noRoles')}</span>}
        </span>
      ),
    },
    { key: 'status', header: t('common.fields.status'), render: (u) => (u.status ? <StatusBadge status={u.status} /> : '—') },
    {
      key: 'actions',
      header: '',
      render: (u) => (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          {hasPermission('user.assign') && !u.is_tenant_admin && (
            <button className="btn-link" onClick={() => setEditingRoles(u)}>
              {t('delegation.users.manageRoles')}
            </button>
          )}
          {hasPermission('user.assign') && u.local_member_id && (
            <button className="btn-link" onClick={() => setScopeOf(u)}>
              {t('delegation.users.dataScope')}
            </button>
          )}
          {hasPermission('user.update') && !u.is_tenant_admin && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setRevoking(u)}>
              {t('delegation.users.removeAccess')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('access.titles.users')}</h1>
      <CentralNotice />
      <div role="tablist" aria-label={t('access.titles.users')} style={{ display: 'flex', marginBottom: 14, borderBottom: '1px solid #e5e7eb', flexWrap: 'wrap' }}>
        {tabButton('users', t('delegation.tabs.users'))}
        {tabButton('invitations', t('delegation.tabs.invitations'))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('user.create') && users.status !== 'forbidden' ? (
            <button className="btn-primary" onClick={() => setInviting(true)}>
              {t('delegation.invitations.inviteUser')}
            </button>
          ) : null
        }
      >
        {tab === 'invitations' && (
          <select
            aria-label={t('delegation.invitations.filterStatus')}
            value={invitationStatus}
            onChange={(e) => setInvitationStatus(e.target.value as InvitationStatus | '')}
            style={{ ...inputStyle, width: 'auto' }}
          >
            <option value="">{t('common.filters.allStatuses')}</option>
            {INVITATION_FILTER_OPTIONS.map((value) => (
              <option key={value} value={value}>
                {t(`delegation.invitations.status.${value.toLowerCase()}`)}
              </option>
            ))}
          </select>
        )}
      </Toolbar>
      {notice && (
        <div role="status" style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', color: '#166534', borderRadius: 8, padding: '8px 12px', fontSize: 13, marginBottom: 12 }}>
          {notice}
        </div>
      )}
      {actionError && (
        <div style={{ marginBottom: 12 }}>
          <FailurePanel kind={classifyFailure(actionError)} error={actionError} />
        </div>
      )}

      {tab === 'users' && (
        <>
          {users.status === 'loading' && <LoadingState label={t('common.actions.loading')} />}
          {(users.status === 'forbidden' || users.status === 'unavailable' || users.status === 'error') && (
            <FailurePanel kind={users.status} error={users.error} onRetry={users.reload} />
          )}
          {users.status === 'ready' && users.data.length === 0 && <EmptyState label={search ? t('delegation.users.emptySearch') : t('delegation.users.empty')} />}
          {users.status === 'ready' && users.data.length > 0 && <Table columns={columns} rows={users.data} />}
        </>
      )}
      {tab === 'invitations' && <InvitationsPanel search={search} status={invitationStatus} version={invitationsVersion} onNotice={setNotice} />}

      {inviting && (
        <InviteUserModal
          roles={roles}
          onClose={() => setInviting(false)}
          onInvited={(message) => {
            setInviting(false);
            setNotice(message);
            setTab('invitations');
            setInvitationsVersion((v) => v + 1);
          }}
        />
      )}
      {editingRoles && (
        <UserRolesModal
          user={editingRoles}
          roles={roles}
          onClose={() => setEditingRoles(null)}
          onSaved={() => {
            setEditingRoles(null);
            setNotice(null);
            users.reload();
          }}
        />
      )}
      {scopeOf?.local_member_id && <DataScopeModal memberId={scopeOf.local_member_id} name={scopeOf.name ?? scopeOf.email ?? ''} onClose={() => setScopeOf(null)} />}
      <ConfirmDialog
        open={revoking !== null}
        title={t('delegation.users.removeAccess')}
        message={t('delegation.users.removeConfirm', { name: revoking?.name ?? revoking?.email ?? '' })}
        confirmLabel={t('delegation.users.removeAccess')}
        onConfirm={() => revoking && revoke(revoking)}
        onCancel={() => setRevoking(null)}
      />
    </div>
  );
}

function chip(background: string, color: string) {
  return { background, color, padding: '2px 8px', borderRadius: 6, fontSize: 12 } as const;
}

function RoleChoices({ roles, selected, onToggle }: { roles: DelegatedRole[]; selected: Set<string>; onToggle: (id: string) => void }) {
  if (roles.length === 0) return <div style={{ fontSize: 13, color: '#6b7280' }}>{t('delegation.users.noAssignableRoles')}</div>;
  return (
    <div style={{ display: 'grid', gap: 6, maxHeight: 240, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10 }}>
      {roles.map((r) => (
        <label key={r.id} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13 }}>
          <input type="checkbox" checked={selected.has(r.id)} onChange={() => onToggle(r.id)} />
          <span>
            <strong>{r.name}</strong>
            {r.description && <span style={{ display: 'block', color: '#6b7280', fontSize: 12 }}>{r.description}</span>}
          </span>
        </label>
      ))}
    </div>
  );
}

function toggled(set: Set<string>, id: string): Set<string> {
  const next = new Set(set);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  return next;
}

function InviteUserModal({ roles, onClose, onInvited }: { roles: DelegatedRole[]; onClose: () => void; onInvited: (message: string) => void }) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [locale, setLocale] = useState(() => defaultInvitationLocale(appLocale()));
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<ApiErrorShape | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFailure(null);
    try {
      const invitation = await delegationApi.invite({ name, email, role_ids: Array.from(selected), locale });
      onInvited(t('delegation.invitations.created', { email: invitation.email ?? email }));
    } catch (err) {
      const error = extractApiError(err);
      setErrors(error.errors ?? {});
      setFailure(error);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('delegation.invitations.inviteUser')} onClose={onClose}>
      {failure && !failure.errors && (
        <div style={{ marginBottom: 12 }}>
          <ActionFailure error={failure} />
        </div>
      )}
      <FormField label={t('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.email')} errors={errors.email} required>
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('delegation.users.rolesOptional')} errors={errors.role_ids}>
        <RoleChoices roles={roles} selected={selected} onToggle={(id) => setSelected((s) => toggled(s, id))} />
      </FormField>
      <FormField label={t('delegation.invitations.language')} errors={errors.locale} hint={t('delegation.invitations.languageHint')}>
        <select value={locale} onChange={(e) => setLocale(defaultInvitationLocale(e.target.value))} style={inputStyle}>
          <option value="en">{t('common.language.en')}</option>
          <option value="id">{t('common.language.id')}</option>
        </select>
      </FormField>
      <p style={{ fontSize: 12, color: '#6b7280' }}>{t('delegation.invitations.howItWorks')}</p>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={onClose} disabled={submitting}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !name || !email} onClick={submit}>
          {submitting ? t('delegation.invitations.sending') : t('delegation.invitations.send')}
        </button>
      </div>
    </Modal>
  );
}

function UserRolesModal({ user, roles, onClose, onSaved }: { user: DelegatedUser; roles: DelegatedRole[]; onClose: () => void; onSaved: () => void }) {
  const assignable = new Set(roles.map((r) => r.id));
  const [selected, setSelected] = useState<Set<string>>(() => new Set(user.roles.map((r) => r.id).filter((id) => assignable.has(id))));
  const [failure, setFailure] = useState<ApiErrorShape | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const kept = user.roles.filter((r) => !assignable.has(r.id));

  async function submit() {
    setSubmitting(true);
    setFailure(null);
    try {
      // Roles an operator assigned that are not delegable are not sent: OptiNexus leaves them as they are.
      await delegationApi.setUserRoles(user.id, Array.from(selected));
      onSaved();
    } catch (err) {
      setFailure(extractApiError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('delegation.users.rolesOf', { name: user.name ?? user.email ?? '' })} onClose={onClose}>
      {failure && (
        <div style={{ marginBottom: 12 }}>
          <FailurePanel kind={classifyFailure(failure)} error={failure} />
        </div>
      )}
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('delegation.users.rolesHint')}</p>
      <RoleChoices roles={roles} selected={selected} onToggle={(id) => setSelected((s) => toggled(s, id))} />
      {kept.length > 0 && (
        <p style={{ fontSize: 12, color: '#6b7280' }}>{t('delegation.users.keptRoles', { roles: kept.map((r) => r.name).join(', ') })}</p>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={onClose} disabled={submitting}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
