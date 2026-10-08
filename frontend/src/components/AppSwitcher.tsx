import { otherApps } from '../auth/ssoApps';
import { t } from '../i18n/i18n';
import type { SsoSession } from '../types';

/** This app's code in OptiNexus; the switcher never lists it. */
const CURRENT_APP_CODE: string = import.meta.env.VITE_OPTINEXUS_APP_CODE ?? 'optifleet';

/**
 * Apps the user may open from here without signing in again (the same OptiNexus session carries over).
 * Only shown for sessions opened with "Sign in with OptiNexus"; the current app is left out.
 */
export function AppSwitcher({ sso }: { sso: SsoSession | null }) {
  const apps = otherApps(sso, window.location.origin, CURRENT_APP_CODE);
  if (apps.length === 0) return null;

  return (
    <select
      aria-label={t('auth.apps.label')}
      value=""
      onChange={(e) => {
        if (e.target.value) window.location.assign(e.target.value);
      }}
      style={{ padding: '6px 10px', borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
    >
      <option value="">{t('auth.apps.label')}</option>
      {apps.map((app) => (
        <option key={app.code} value={app.launch_url ?? ''}>
          {app.name}
        </option>
      ))}
    </select>
  );
}
