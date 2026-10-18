/**
 * How an invitation is shown, as pure functions of what OptiNexus reported (nothing is decided here: status, expiry, delivery and
 * whether it can be resent or revoked all come from the answer). Kept free of React so every state can be unit tested in both
 * languages.
 */
import type { ApiErrorShape } from '../../api/client';
import type { DelegatedInvitation, InvitationLocale, InvitationStatus } from '../../api/delegation';
import { hasKey, t } from '../../i18n/i18n';
import { formatDateTime } from '../../utils/date';

export type Tone = 'info' | 'success' | 'warning' | 'danger' | 'neutral';

export const TONE_STYLES: Record<Tone, { background: string; color: string }> = {
  info: { background: '#eff6ff', color: '#1e3a8a' },
  success: { background: '#f0fdf4', color: '#166534' },
  warning: { background: '#fef3c7', color: '#92400e' },
  danger: { background: '#fef2f2', color: '#b91c1c' },
  neutral: { background: '#f3f4f6', color: '#374151' },
};

/**
 * The statuses of the OptiNexus invitation vocabulary, in the order of the filter. They are labelled under
 * `delegation.invitations.status.*` (their own texts), not through the application's status registry of local documents.
 */
export const INVITATION_FILTER_OPTIONS: readonly InvitationStatus[] = ['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED'];

const STATUS_TONE: Record<string, Tone> = { PENDING: 'info', ACCEPTED: 'success', REVOKED: 'neutral', EXPIRED: 'warning' };
const DELIVERY_TONE: Record<string, Tone> = { QUEUED: 'neutral', SENT: 'success', FAILED: 'danger' };

/** The label of a status or delivery state, or the reported text itself when a newer OptiNexus sends one this screen does not know. */
function labelOf(group: 'status' | 'delivery', value: string): string {
  const key = `delegation.invitations.${group}.${value.toLowerCase()}`;
  return hasKey(key) ? t(key) : value;
}

/** The status chip and the one line under it that explains what it means for the invitation link (expired, revoked, used, valid). */
export function invitationStatusView(invitation: Pick<DelegatedInvitation, 'status' | 'expires_at' | 'accepted_at' | 'revoked_at'>): { label: string; tone: Tone; hint: string } {
  const status = String(invitation.status);
  const date: Record<string, string | null> = {
    PENDING: invitation.expires_at,
    EXPIRED: invitation.expires_at,
    ACCEPTED: invitation.accepted_at,
    REVOKED: invitation.revoked_at,
  };
  const hintKey = `delegation.invitations.hint.${status.toLowerCase()}`;

  return {
    label: labelOf('status', status),
    tone: STATUS_TONE[status] ?? 'neutral',
    hint: INVITATION_FILTER_OPTIONS.includes(status as InvitationStatus) && hasKey(hintKey) ? t(hintKey, { date: formatDateTime(date[status]) }) : '',
  };
}

/**
 * The delivery of the e-mail, only while the link can still be used (PENDING, EXPIRED): once accepted or revoked, how the e-mail
 * went is no longer of interest. A failed delivery carries the recovery hint and OptiNexus' code for support.
 */
export function invitationDeliveryView(invitation: Pick<DelegatedInvitation, 'status' | 'delivery' | 'last_sent_at'>): { label: string; tone: Tone; hint: string; code: string | null } | null {
  const status = invitation.delivery?.status;
  if (!status || (invitation.status !== 'PENDING' && invitation.status !== 'EXPIRED')) return null;

  const hintKey = `delegation.invitations.delivery.${status.toLowerCase()}Hint`;
  const when = invitation.delivery.attempted_at ?? invitation.last_sent_at;
  return {
    label: labelOf('delivery', status),
    tone: DELIVERY_TONE[status] ?? 'neutral',
    hint: hasKey(hintKey) ? t(hintKey, { date: formatDateTime(when) }) : '',
    code: status === 'FAILED' && invitation.delivery.error_code ? t('delegation.invitations.delivery.code', { code: invitation.delivery.error_code }) : null,
  };
}

/** Which actions the row offers: what OptiNexus allows for this invitation AND what this person may do (central permission check). */
export function invitationActions(invitation: Pick<DelegatedInvitation, 'can_resend' | 'can_revoke'>, can: (permission: string) => boolean): { resend: boolean; revoke: boolean } {
  return { resend: invitation.can_resend === true && can('user.create'), revoke: invitation.can_revoke === true && can('user.update') };
}

/** The language of a new invitation starts as the language of the screen. */
export function defaultInvitationLocale(uiLocale: string): InvitationLocale {
  return uiLocale === 'id' ? 'id' : 'en';
}

/** Codes of the invitation contract that are refusals of a request (not outages): shown as a plain notice, not as an error panel. */
export const INVITATION_REFUSALS: readonly string[] = [
  'INVITATION_PENDING',
  'ALREADY_MEMBER',
  'ROLE_NOT_ASSIGNABLE',
  'INVITATION_NOT_FOUND',
  'INVITATION_NOT_PENDING',
  'INVITATION_RESEND_COOLDOWN',
  'INVITATION_RESEND_LIMIT',
  'INVITATION_RATE_LIMITED',
  'DIRECT_USER_CREATION_DISABLED',
];

/**
 * The sentence for a refusal of the invitation API, or null when the failure is something else (outage, permission, unexpected).
 * The server already translated its message; the cases that need the details (which status blocks, how long to wait) say more.
 */
export function invitationRefusalMessage(error: ApiErrorShape): string | null {
  if (!error.code || !INVITATION_REFUSALS.includes(error.code)) return null;

  const wait = typeof error.retry_after_seconds === 'number' && error.retry_after_seconds > 0 ? error.retry_after_seconds : null;
  if (error.code === 'INVITATION_RESEND_COOLDOWN' && wait !== null) return t('delegation.invitations.errors.cooldown', { seconds: wait });
  if (error.code === 'INVITATION_RATE_LIMITED' && wait !== null) return t('delegation.invitations.errors.rateLimited', { seconds: wait });
  if (error.code === 'INVITATION_NOT_PENDING') {
    const blocking = error.details?.status;
    if (blocking === 'ACCEPTED') return t('delegation.invitations.errors.alreadyAccepted');
    if (blocking === 'REVOKED') return t('delegation.invitations.errors.alreadyRevoked');
  }

  return error.message;
}

/** The moment (ms) until which Resend stays off after a cooldown refusal, or null when the answer carries no wait. */
export function cooldownDeadline(error: ApiErrorShape, now: number): number | null {
  if (error.code !== 'INVITATION_RESEND_COOLDOWN' || typeof error.retry_after_seconds !== 'number' || error.retry_after_seconds <= 0) return null;
  return now + error.retry_after_seconds * 1000;
}

/** Whole seconds left until a deadline, 0 when there is none or it has passed. */
export function secondsLeft(deadline: number | undefined, now: number): number {
  return deadline === undefined ? 0 : Math.max(0, Math.ceil((deadline - now) / 1000));
}
