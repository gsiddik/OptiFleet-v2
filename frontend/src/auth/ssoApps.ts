import type { SsoSession } from '../types';

/** The apps in an SSO session other than the one running at `currentOrigin`, and only those that can be opened. */
export function otherApps(sso: SsoSession | null, currentOrigin: string): SsoSession['apps'] {
  return (sso?.apps ?? []).filter((app) => {
    if (!app.launch_url) return false;
    try {
      return new URL(app.launch_url).origin !== currentOrigin;
    } catch {
      return false;
    }
  });
}
