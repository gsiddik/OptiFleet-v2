/**
 * How the user seats are shown, as pure functions of what OptiNexus counted (contract 2d): every figure is OptiNexus' own and nothing is
 * calculated here beyond choosing the wording. A limit that could not be decided is said so, never shown as unlimited. The server still
 * decides every addition; this only explains it.
 */
import type { ApiErrorShape } from '../../api/client';
import type { DelegationSeats } from '../../api/delegation';
import { t } from '../../i18n/i18n';

export type SeatsTone = 'info' | 'warning';

const UNDECIDED_KEYS: Record<string, string> = {
  ENTITLEMENT_MISSING: 'delegation.seats.undecided.entitlementMissing',
  ENTITLEMENT_INVALID: 'delegation.seats.undecided.entitlementInvalid',
  ENTITLEMENT_UNAVAILABLE: 'delegation.seats.undecided.entitlementUnavailable',
};

/** The notice for the seats of this product, or null when OptiNexus sent no figures (an older OptiNexus). */
export function seatsView(seats: DelegationSeats | null | undefined): { tone: SeatsTone; title: string | null; text: string } | null {
  if (!seats) return null;
  const figures = { used: seats.used, reserved: seats.reserved, limit: seats.limit ?? '', available: seats.available ?? '', overBy: seats.over_by };

  if (seats.state === 'NOT_CONTROLLED') return { tone: 'info', title: null, text: t('delegation.seats.notControlled', figures) };
  if (seats.state === 'UNLIMITED') return { tone: 'info', title: null, text: t('delegation.seats.unlimited', figures) };
  if (seats.state === 'LIMITED') {
    if (seats.over_limit) return { tone: 'warning', title: t('delegation.seats.overTitle', figures), text: t('delegation.seats.overBody', figures) };
    return { tone: seats.can_add ? 'info' : 'warning', title: null, text: `${t('delegation.seats.summary', figures)}${seats.can_add ? '' : ` ${t('delegation.seats.full')}`}` };
  }

  const key = UNDECIDED_KEYS[seats.state];
  return { tone: 'warning', title: null, text: `${t('delegation.seats.usedOnly', figures)}${key ? ` ${t(key)}` : ''}` };
}

/** Codes of the seat contract: refusals of an addition (nothing was added), shown as a plain notice with OptiNexus' own figures. */
export const SEAT_REFUSALS: readonly string[] = [
  'SEAT_LIMIT_REACHED',
  'SEAT_OVER_LIMIT',
  'SEAT_ENTITLEMENT_MISSING',
  'SEAT_ENTITLEMENT_INVALID',
  'SEAT_ENTITLEMENT_UNAVAILABLE',
  'SEAT_CONTENTION',
];

/** The refusal in the server's own words (already translated) plus the figures it carried and, for a transient one, the wait. */
export function seatRefusalMessage(error: ApiErrorShape): string {
  const details = error.details ?? {};
  const whole = (key: string) => (typeof details[key] === 'number' ? (details[key] as number) : null);
  const used = whole('used');
  const reserved = whole('reserved');
  const limit = whole('limit');
  const wait = typeof error.retry_after_seconds === 'number' && error.retry_after_seconds > 0 ? error.retry_after_seconds : null;

  const parts = [error.message];
  if (used !== null && reserved !== null && limit !== null) parts.push(t('delegation.seats.figures', { used, reserved, limit }));
  if (wait !== null) parts.push(t('delegation.seats.retryIn', { seconds: wait }));

  return parts.join(' ');
}
