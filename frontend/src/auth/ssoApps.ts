import type { SsoSession } from '../types';

/** The apps in an SSO session other than this one (by application code, or by origin), and only those that can be opened. */
export function otherApps(sso: SsoSession | null, currentOrigin: string, currentCode: string): SsoSession['apps'] {
  return (sso?.apps ?? []).filter((app) => {
    if (app.code === currentCode || !app.launch_url) return false;
    try {
      return new URL(app.launch_url).origin !== currentOrigin;
    } catch {
      return false;
    }
  });
}
