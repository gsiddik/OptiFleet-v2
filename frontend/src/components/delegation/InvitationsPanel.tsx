import { useEffect, useState } from 'react';
import { delegationApi, extractApiError, type DelegatedInvitation, type InvitationStatus } from '../../api/delegation';
import type { ApiErrorShape } from '../../api/client';
import { useAuth } from '../../auth/AuthContext';
import { ConfirmDialog } from '../ConfirmDialog';
import { EmptyState, LoadingState } from '../States';
import { Table, type Column } from '../Table';
import { useDelegationResource } from '../../hooks/useDelegationResource';
import { t } from '../../i18n/i18n';
import { formatDateTime } from '../../utils/date';
import { ActionFailure, FailurePanel } from './DelegationPanels';
import { cooldownDeadline, invitationActions, invitationDeliveryView, invitationStatusView, secondsLeft, TONE_STYLES, type Tone } from './invitationPresentation';

function Chip({ tone, children }: { tone: Tone; children: string }) {
  return <span style={{ ...TONE_STYLES[tone], padding: '2px 8px', borderRadius: 6, fontSize: 12, whiteSpace: 'nowrap' }}>{children}</span>;
}

const hintStyle = { display: 'block', marginTop: 4, fontSize: 12, color: '#6b7280', maxWidth: 260 } as const;

/**
 * The invitations of the organization, as OptiNexus reports them (read through, nothing kept here): who was invited, status,
 * expiry, how the e-mail went and who invited. Resend and Revoke are offered only when OptiNexus says the invitation allows it
 * AND the person holds the permission; a cooldown refusal keeps Resend off for the wait OptiNexus asked for.
 */
export function InvitationsPanel({
  search,
  status,
  version,
  onNotice,
}: {
  search: string;
  status: InvitationStatus | '';
  /** Changes when the page created an invitation, so the list is read again. */
  version: number;
  onNotice: (message: string | null) => void;
}) {
  const { hasPermission } = useAuth();
  const list = useDelegationResource(() => delegationApi.invitations({ status, search }), [status, search, version]);
  const [revoking, setRevoking] = useState<DelegatedInvitation | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  // A refusal belongs to the list it was shown for: when the page creates an invitation (version changes) it no longer applies.
  const [failure, setFailure] = useState<{ error: ApiErrorShape; version: number } | null>(null);
  const actionError = failure && failure.version === version ? failure.error : null;
  const setActionError = (error: ApiErrorShape | null) => setFailure(error ? { error, version } : null);
  const [cooldowns, setCooldowns] = useState<Record<string, number>>({});
  const [now, setNow] = useState(() => Date.now());

  const waiting = Object.values(cooldowns).some((deadline) => deadline > now);
  useEffect(() => {
    if (!waiting) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [waiting]);

  const label = (i: DelegatedInvitation) => i.name ?? i.email ?? '';

  async function resend(invitation: DelegatedInvitation) {
    onNotice(null);
    setActionError(null);
    setBusy(invitation.id);
    try {
      await delegationApi.resendInvitation(invitation.id);
      onNotice(t('delegation.invitations.resent', { email: invitation.email ?? '' }));
    } catch (err) {
      const error = extractApiError(err);
      setActionError(error);
      const deadline = cooldownDeadline(error, Date.now());
      if (deadline !== null) {
        setCooldowns((current) => ({ ...current, [invitation.id]: deadline }));
        setNow(Date.now());
      }
    } finally {
      setBusy(null);
      list.reload();
    }
  }

  async function revoke(invitation: DelegatedInvitation) {
    onNotice(null);
    setActionError(null);
    setBusy(invitation.id);
    try {
      await delegationApi.revokeInvitation(invitation.id);
      onNotice(t('delegation.invitations.revoked', { name: label(invitation) }));
    } catch (err) {
      setActionError(extractApiError(err));
    } finally {
      setBusy(null);
      setRevoking(null);
      list.reload();
    }
  }

  const columns: Column<DelegatedInvitation>[] = [
    {
      key: 'person',
      header: t('delegation.invitations.columns.person'),
      render: (i) => (
        <div>
          <strong>{i.name ?? '—'}</strong>
          <div style={{ fontSize: 12, color: '#6b7280' }}>{i.email ?? '—'}</div>
        </div>
      ),
    },
    {
      key: 'roles',
      header: t('access.sections.roles'),
      render: (i) =>
        i.roles.length === 0 ? (
          <span style={{ color: '#9ca3af' }}>{t('delegation.users.noRoles')}</span>
        ) : (
          <span style={{ display: 'inline-flex', flexWrap: 'wrap', gap: 4 }}>
            {i.roles.map((r) => (
              <Chip key={r.id} tone="info">
                {r.name ?? r.code ?? ''}
              </Chip>
            ))}
          </span>
        ),
    },
    {
      key: 'status',
      header: t('common.fields.status'),
      render: (i) => {
        const view = invitationStatusView(i);
        return (
          <div>
            <Chip tone={view.tone}>{view.label}</Chip>
            {view.hint && <span style={hintStyle}>{view.hint}</span>}
          </div>
        );
      },
    },
    {
      key: 'delivery',
      header: t('delegation.invitations.columns.delivery'),
      render: (i) => {
        const view = invitationDeliveryView(i);
        if (!view) return <span style={{ color: '#9ca3af' }}>—</span>;
        return (
          <div>
            <Chip tone={view.tone}>{view.label}</Chip>
            {view.hint && <span style={hintStyle}>{view.hint}</span>}
            {view.code && <span style={{ ...hintStyle, fontFamily: 'monospace' }}>{view.code}</span>}
          </div>
        );
      },
    },
    {
      key: 'invitedBy',
      header: t('delegation.invitations.columns.invitedBy'),
      render: (i) => (
        <div>
          {i.invited_by?.name ?? '—'}
          <div style={{ fontSize: 12, color: '#6b7280' }}>{formatDateTime(i.created_at)}</div>
        </div>
      ),
    },
    {
      key: 'actions',
      header: '',
      render: (i) => {
        const actions = invitationActions(i, hasPermission);
        const left = secondsLeft(cooldowns[i.id], now);
        return (
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
            {actions.resend && (
              <button className="btn-link" disabled={busy !== null || left > 0} style={left > 0 ? { opacity: 0.55, cursor: 'not-allowed' } : undefined} onClick={() => resend(i)}>
                {left > 0 ? t('delegation.invitations.actions.resendIn', { seconds: left }) : t('delegation.invitations.actions.resend')}
              </button>
            )}
            {actions.revoke && (
              <button className="btn-link" disabled={busy !== null} style={{ color: '#b91c1c' }} onClick={() => setRevoking(i)}>
                {t('delegation.invitations.actions.revoke')}
              </button>
            )}
          </div>
        );
      },
    },
  ];

  return (
    <div>
      {actionError && (
        <div style={{ marginBottom: 12 }}>
          <ActionFailure error={actionError} />
        </div>
      )}
      {list.status === 'loading' && <LoadingState label={t('common.actions.loading')} />}
      {(list.status === 'forbidden' || list.status === 'unavailable' || list.status === 'error') && <FailurePanel kind={list.status} error={list.error} onRetry={list.reload} />}
      {list.status === 'ready' && list.data.length === 0 && (
        <EmptyState label={search ? t('delegation.invitations.emptySearch') : status ? t('delegation.invitations.emptyStatus') : t('delegation.invitations.empty')} />
      )}
      {list.status === 'ready' && list.data.length > 0 && <Table columns={columns} rows={list.data} />}
      <ConfirmDialog
        open={revoking !== null}
        title={t('delegation.invitations.actions.revokeTitle')}
        message={t('delegation.invitations.actions.revokeConfirm', { name: revoking ? label(revoking) : '' })}
        confirmLabel={t('delegation.invitations.actions.revoke')}
        onConfirm={() => revoking && revoke(revoking)}
        onCancel={() => setRevoking(null)}
      />
    </div>
  );
}
