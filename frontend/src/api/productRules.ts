import type { ApiErrorShape } from './client';

// Pure rules of the product switcher (no network, no browser), kept apart so that they are tested without either.

export type LaunchFailure = 'session_ended' | 'not_available' | 'unavailable' | 'error';

/** What a failed switch means for the screen. Pure so that it is tested without a browser. */
export function classifyLaunchFailure(error: ApiErrorShape): LaunchFailure {
  if (error.code === 'SESSION_ENDED_CENTRALLY') return 'session_ended';
  if (error.code === 'PRODUCT_NOT_AVAILABLE') return 'not_available';
  if (error.status === undefined || error.status === 503 || error.retryable === true || error.code === 'GOVERNANCE_UNAVAILABLE') return 'unavailable';
  return 'error';
}

/** Only an http(s) address without embedded credentials is ever navigated to, whatever the server said. */
export function isNavigableUrl(url: unknown): url is string {
  if (typeof url !== 'string') return false;
  try {
    const parsed = new URL(url);
    return (parsed.protocol === 'https:' || parsed.protocol === 'http:') && parsed.username === '' && parsed.password === '';
  } catch {
    return false;
  }
}

/** The OptiNexus tenant id a link asks for (`?tenant_hint=`): only a UUID counts, anything else is ignored. */
export function tenantHintFrom(value: string | null | undefined): string | null {
  return value && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value) ? value.toLowerCase() : null;
}
