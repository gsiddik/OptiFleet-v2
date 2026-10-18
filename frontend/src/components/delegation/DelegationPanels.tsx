import type { ApiErrorShape } from '../../api/client';
import { classifyFailure, type FailureKind } from '../../api/delegation';
import { t } from '../../i18n/i18n';
import { invitationRefusalMessage } from './invitationPresentation';
import { seatsView } from './seatsPresentation';
import type { DelegationSeats } from '../../api/delegation';

/** Says that access here is managed centrally and where a change is saved. Shown on both delegation pages. */
export function CentralNotice() {
  return (
    <div
      role="note"
      style={{ background: '#eff6ff', border: '1px solid #bfdbfe', color: '#1e3a8a', borderRadius: 8, padding: '10px 14px', fontSize: 13, marginBottom: 14 }}
    >
      {t('delegation.banner.managedCentrally')}
    </div>
  );
}

/** The user seats of this organization for this product, as OptiNexus counted them (used, reserved by open invitations, limit, available). */
export function SeatsNotice({ seats }: { seats: DelegationSeats | null | undefined }) {
  const view = seatsView(seats);
  if (view === null) return null;
  const warning = view.tone === 'warning';
  return (
    <div
      role={warning ? 'alert' : 'status'}
      style={{
        background: warning ? '#fffbeb' : '#eff6ff', border: `1px solid ${warning ? '#fde68a' : '#bfdbfe'}`, color: warning ? '#92400e' : '#1e3a8a',
        borderRadius: 8, padding: '10px 14px', fontSize: 13, marginBottom: 14,
      }}
    >
      {view.title && <strong>{view.title} </strong>}
      {view.text}
    </div>
  );
}

const TONES: Record<FailureKind, { background: string; border: string; color: string }> = {
  forbidden: { background: '#fffbeb', border: '#fde68a', color: '#92400e' },
  unavailable: { background: '#f3f4f6', border: '#d1d5db', color: '#374151' },
  error: { background: '#fef2f2', border: '#fecaca', color: '#b91c1c' },
};

/**
 * A failed request, explained: not allowed (a retry will not help), temporarily unavailable or offline (a retry may), or an
 * error to read and report. Shows the server's translated message and the reference to quote; never a stack trace.
 */
export function FailurePanel({ kind, error, onRetry }: { kind: FailureKind; error: ApiErrorShape; onRetry?: () => void }) {
  const tone = TONES[kind];
  const offline = error.status === undefined;
  return (
    <div role="alert" style={{ background: tone.background, border: `1px solid ${tone.border}`, color: tone.color, borderRadius: 8, padding: 16, fontSize: 14 }}>
      <strong style={{ display: 'block', marginBottom: 4 }}>{t(`delegation.state.${kind}`)}</strong>
      <div>{offline ? t('delegation.state.offline') : error.message}</div>
      {error.outcome_unknown && <div style={{ marginTop: 6 }}>{t('delegation.state.outcomeUnknown')}</div>}
      {error.correlation_id && (
        <div style={{ marginTop: 6, fontSize: 12, opacity: 0.8 }}>{t('delegation.state.reference', { id: error.correlation_id })}</div>
      )}
      {kind !== 'forbidden' && onRetry && (
        <button className="btn-secondary" style={{ marginTop: 10 }} onClick={onRetry}>
          {t('delegation.state.retry')}
        </button>
      )}
    </div>
  );
}

/**
 * A failed invitation request. A refusal of the invitation contract (already invited, already a member, wait before resending, a
 * link that was already used) is an answer to read and act on, so it is shown as a plain notice with the sentence for that case;
 * anything else (outage, not allowed, unexpected) keeps the usual panel with the reference to quote.
 */
export function ActionFailure({ error }: { error: ApiErrorShape }) {
  const refusal = invitationRefusalMessage(error);
  if (refusal === null) return <FailurePanel kind={classifyFailure(error)} error={error} />;
  return (
    <div role="alert" style={{ background: TONES.forbidden.background, border: `1px solid ${TONES.forbidden.border}`, color: TONES.forbidden.color, borderRadius: 8, padding: '10px 14px', fontSize: 13 }}>
      {refusal}
    </div>
  );
}
