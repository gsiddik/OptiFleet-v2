/**
 * Telematics / odometer display rules. Pure: no React, no API client, so they can be tested directly.
 * The backend decides everything that matters (what is accepted, quarantined or applied); these helpers only turn its answers into
 * labels, tones and the one hint a form needs. No kilometres are computed here: values stay decimal strings from the API.
 */

export type TelematicsState =
  | 'disabled'
  | 'not_linked'
  | 'not_activated'
  | 'pending'
  | 'not_subscribed'
  | 'provider_unavailable'
  | 'error'
  | 'stale'
  | 'partial'
  | 'ok';

export type Tone = 'good' | 'warn' | 'bad' | 'neutral';

export const TELEMATICS_STATES: readonly TelematicsState[] = [
  'disabled',
  'not_linked',
  'not_activated',
  'pending',
  'not_subscribed',
  'provider_unavailable',
  'error',
  'stale',
  'partial',
  'ok',
];

const STATE_TONE: Record<TelematicsState, Tone> = {
  disabled: 'neutral',
  not_linked: 'neutral',
  not_activated: 'neutral',
  pending: 'neutral',
  not_subscribed: 'warn',
  provider_unavailable: 'warn',
  error: 'bad',
  stale: 'warn',
  partial: 'warn',
  ok: 'good',
};

export function stateTone(state: string): Tone {
  return STATE_TONE[state as TelematicsState] ?? 'neutral';
}

/** i18n keys for a feed state; an unknown state (a newer backend) falls back to a generic label instead of raw text. */
export function stateKeys(state: string): { label: string; help: string } {
  const known = (TELEMATICS_STATES as readonly string[]).includes(state) ? state : 'unknown';
  return { label: `telematics.state.${known}`, help: `telematics.stateHelp.${known}` };
}

/** The switch is offered only where it can do something: OptiNexus configured and this organization linked to it. */
export function canSwitch(integration: { enabled: boolean; linked: boolean }): boolean {
  return integration.enabled && integration.linked;
}

/** A device or feed that has not reported for this long is described in whole minutes, hours or days (null = never reported). */
export function ageParts(seconds: number | null | undefined): { unit: 'minutes' | 'hours' | 'days'; value: number } | null {
  if (seconds == null || !Number.isFinite(seconds) || seconds < 0) return null;
  if (seconds < 3600) return { unit: 'minutes', value: Math.floor(seconds / 60) };
  if (seconds < 48 * 3600) return { unit: 'hours', value: Math.floor(seconds / 3600) };
  return { unit: 'days', value: Math.floor(seconds / 86400) };
}

const DECIMAL = /^\d+(\.\d+)?$/;

/** Compares two non-negative decimal strings exactly (no floats). Null when either is not a plain non-negative decimal. */
export function compareDecimal(a: string, b: string): -1 | 0 | 1 | null {
  const x = a.trim();
  const y = b.trim();
  if (!DECIMAL.test(x) || !DECIMAL.test(y)) return null;
  const [xi, xf = ''] = x.split('.');
  const [yi, yf = ''] = y.split('.');
  const scale = Math.max(xf.length, yf.length);
  const digits = (i: string, f: string) => (i + f.padEnd(scale, '0')).replace(/^0+(?=\d)/, '');
  const left = digits(xi, xf);
  const right = digits(yi, yf);
  if (left.length !== right.length) return left.length < right.length ? -1 : 1;
  return left < right ? -1 : left > right ? 1 : 0;
}

/** True when saving `entered` would lower the odometer, so the form asks for a correction reason before the server has to. */
export function lowersOdometer(current: string, entered: string): boolean {
  return compareDecimal(entered, current) === -1;
}

/** Codes the server answers with when it wants a correction reason, so the form can reveal that field after a first attempt. */
export const REASON_REQUIRED_CODES: readonly string[] = ['ODOMETER_BELOW_CURRENT', 'ODOMETER_TIME_CONFLICT'];

export function asksForReason(code: string | undefined): boolean {
  return code !== undefined && REASON_REQUIRED_CODES.includes(code);
}

/** Minimum characters of a correction reason (the server enforces it; this only keeps the button honest). */
export const MIN_REASON_LENGTH = 10;
export const MIN_REVIEW_NOTE_LENGTH = 5;

export type ReviewDecision = 'dismiss' | 'accept' | 'rebase';

/**
 * What a person may decide for a device under review. All three always need a note; `rebase` may carry the real odometer of the
 * vehicle (default: the value recorded now). `accept` is offered for every reason: the server refuses it (with a clear message) when
 * it needs a calibration that has not been done.
 */
export function reviewDecisions(): readonly ReviewDecision[] {
  return ['dismiss', 'accept', 'rebase'];
}

export function reviewReasonKey(reason: string | null | undefined): string {
  switch (reason) {
    case 'COUNTER_DECREASE':
    case 'IMPLAUSIBLE_JUMP':
    case 'DEVICE_CHANGED':
      return `telematics.review.reason.${reason}`;
    default:
      return 'telematics.review.reason.unknown';
  }
}

export function qualityTone(quality: string | null | undefined): Tone {
  switch (quality) {
    case 'OK':
      return 'good';
    case 'SUSPECT':
      return 'bad';
    case 'LATE':
    case 'UNCALIBRATED':
      return 'warn';
    default:
      return 'neutral';
  }
}

/** i18n key for a ledger source ('manual', 'optinexus', ...). The `source` of a vehicle that predates the ledger is null = not recorded. */
export function sourceKey(source: string | null | undefined): string {
  switch (source) {
    case 'manual':
    case 'optinexus':
    case 'inspection':
    case 'work_order':
    case 'tire_operation':
    case 'vehicle_release':
      return `vehicle.odometer.source.${source}`;
    case null:
    case undefined:
      return 'vehicle.odometer.source.unknown';
    default:
      return 'vehicle.odometer.source.other';
  }
}

export function dispositionKey(disposition: string | null | undefined): string {
  switch (disposition) {
    case 'applied':
    case 'stored':
    case 'held':
    case 'late':
    case 'quarantined':
    case 'correction':
      return `vehicle.odometer.disposition.${disposition}`;
    default:
      return 'vehicle.odometer.disposition.other';
  }
}

/** A fresh idempotency key for one submit of the entry form: a retry of the same submit never records twice. */
export function newIdempotencyKey(): string {
  const c = (globalThis as { crypto?: { randomUUID?: () => string } }).crypto;
  if (c?.randomUUID) return c.randomUUID();
  const hex = (n: number) => Array.from({ length: n }, () => Math.floor(Math.random() * 16).toString(16)).join('');
  return `${hex(8)}-${hex(4)}-4${hex(3)}-8${hex(3)}-${hex(12)}`;
}
