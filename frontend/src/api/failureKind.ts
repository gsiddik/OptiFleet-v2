import type { ApiErrorShape } from './client';

/** How a screen presents a failed request: not allowed, temporarily unavailable (a retry may help), or an error to read and report. */
export type FailureKind = 'forbidden' | 'unavailable' | 'error';

/**
 * Pure, so that every central screen classifies the same way: HTTP 403 is "not allowed" (a retry will not help); no answer at all
 * (offline, blocked, timeout), HTTP 503 or a retryable answer is "unavailable"; anything else is an error to read.
 */
export function classifyFailure(error: ApiErrorShape): FailureKind {
  if (error.status === 403) return 'forbidden';
  if (error.status === undefined || error.status === 503 || error.retryable === true || error.code === 'GOVERNANCE_UNAVAILABLE') return 'unavailable';
  return 'error';
}
