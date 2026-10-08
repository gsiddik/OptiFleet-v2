import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../api/client';
import type { CurrentUser, SsoSession } from '../types';
import { changeLocale } from '../i18n/i18n';
import { cacheLocale, resolveUiLocale, type AppLocale } from '../i18n/locale';

interface AuthContextValue {
  user: CurrentUser | null;
  loading: boolean;
  activeTenantId: string | null;
  /** Present when the session was opened with "Sign in with OptiNexus": the apps the user may open from here. */
  sso: SsoSession | null;
  login: (email: string, password: string) => Promise<void>;
  /** Trades the one-time ticket from the OptiNexus redirect for a session. */
  completeSsoLogin: (ticket: string) => Promise<void>;
  logout: () => Promise<void>;
  switchTenant: (tenantId: string) => Promise<void>;
  refresh: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
  /** Switches the UI language at once; signed in, it is also saved as the user's preference. null = follow the tenant default / browser. */
  setLanguage: (locale: AppLocale | null) => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

const SSO_STORAGE_KEY = 'optifleet_sso';

function readStoredSso(): SsoSession | null {
  try {
    const raw = localStorage.getItem(SSO_STORAGE_KEY);
    return raw ? (JSON.parse(raw) as SsoSession) : null;
  } catch {
    return null;
  }
}

/** Applies the language resolved for this user (preference → tenant default → browser → English). */
function applyUserLocale(user: CurrentUser): void {
  const locale = resolveUiLocale({ preferred: user.preferred_locale, tenantDefault: user.tenant_default_locale });
  cacheLocale(locale);
  void changeLocale(locale);
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [activeTenantId, setActiveTenantId] = useState<string | null>(
    localStorage.getItem('optifleet_active_tenant'),
  );
  const [sso, setSso] = useState<SsoSession | null>(readStoredSso);

  const rememberSso = useCallback((session: SsoSession | null) => {
    try {
      if (session) localStorage.setItem(SSO_STORAGE_KEY, JSON.stringify(session));
      else localStorage.removeItem(SSO_STORAGE_KEY);
    } catch {
      // Storage unavailable: the session still works, only the app switcher is lost on reload.
    }
    setSso(session);
  }, []);

  const fetchMe = useCallback(async () => {
    const token = localStorage.getItem('optifleet_token');
    if (!token) {
      setUser(null);
      setLoading(false);
      return;
    }
    try {
      const { data } = await apiClient.get<{ data: CurrentUser }>('/auth/me');
      setUser(data.data);
      applyUserLocale(data.data);
    } catch {
      localStorage.removeItem('optifleet_token');
      rememberSso(null);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, [rememberSso]);

  useEffect(() => {
    fetchMe();
  }, [fetchMe]);

  const openSession = useCallback((token: string, nextUser: CurrentUser, tenantId?: string) => {
    localStorage.setItem('optifleet_token', token);
    if (nextUser.scope === 'tenant' && nextUser.memberships.length > 0) {
      const preferred = tenantId ? nextUser.memberships.find((m) => m.tenant_id === tenantId) : undefined;
      const active = preferred ?? nextUser.memberships.find((m) => m.status === 'active') ?? nextUser.memberships[0];
      localStorage.setItem('optifleet_active_tenant', active.tenant_id);
      setActiveTenantId(active.tenant_id);
    } else {
      localStorage.removeItem('optifleet_active_tenant');
      setActiveTenantId(null);
    }
    setUser(nextUser);
    applyUserLocale(nextUser);
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    try {
      const { data } = await apiClient.post<{ data: { token: string; user: CurrentUser } }>('/auth/login', {
        email,
        password,
      });
      rememberSso(null);
      openSession(data.data.token, data.data.user);
    } catch (error) {
      throw extractApiError(error);
    }
  }, [openSession, rememberSso]);

  const completeSsoLogin = useCallback(async (ticket: string) => {
    try {
      const { data } = await apiClient.post<{ data: { token: string; user: CurrentUser; sso: SsoSession } }>('/auth/sso/exchange', { ticket });
      rememberSso(data.data.sso);
      // The token is bound to the tenant OptiNexus signed the user in to, so that tenant must be the active one.
      openSession(data.data.token, data.data.user, data.data.sso.tenant_id);
    } catch (error) {
      throw extractApiError(error);
    }
  }, [openSession, rememberSso]);

  const logout = useCallback(async () => {
    const logoutUrl = sso?.logout_url ?? null;
    try {
      await apiClient.post('/auth/logout');
    } finally {
      localStorage.removeItem('optifleet_token');
      localStorage.removeItem('optifleet_active_tenant');
      rememberSso(null);
      setUser(null);
      setActiveTenantId(null);
      // Ends the OptiNexus session too, so signing out here does not leave the other apps signed in silently.
      if (logoutUrl) {
        const back = encodeURIComponent(`${window.location.origin}/login`);
        window.location.assign(`${logoutUrl}${logoutUrl.includes('?') ? '&' : '?'}post_logout_redirect_uri=${back}`);
      }
    }
  }, [sso, rememberSso]);

  const switchTenant = useCallback(async (tenantId: string) => {
    const { data } = await apiClient.post<{ data: { token: string } }>('/auth/switch-tenant', {
      tenant_id: tenantId,
    });
    localStorage.setItem('optifleet_token', data.data.token);
    localStorage.setItem('optifleet_active_tenant', tenantId);
    setActiveTenantId(tenantId);
    await fetchMe();
  }, [fetchMe]);

  const hasPermission = useCallback(
    (permission: string) => user?.permissions.includes(permission) ?? false,
    [user],
  );

  const setLanguage = useCallback(
    async (locale: AppLocale | null) => {
      const shown = locale ?? resolveUiLocale({ tenantDefault: user?.tenant_default_locale });
      cacheLocale(shown);
      await changeLocale(shown);
      if (!user) return;
      const { data } = await apiClient.patch<{ data: CurrentUser }>('/auth/me/preferences', { preferred_locale: locale });
      setUser(data.data);
    },
    [user],
  );

  const value = useMemo(
    () => ({ user, loading, activeTenantId, sso, login, completeSsoLogin, logout, switchTenant, refresh: fetchMe, hasPermission, setLanguage }),
    [user, loading, activeTenantId, sso, login, completeSsoLogin, logout, switchTenant, fetchMe, hasPermission, setLanguage],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
