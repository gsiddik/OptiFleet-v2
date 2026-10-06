import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../api/client';
import type { CurrentUser } from '../types';
import { changeLocale } from '../i18n/i18n';
import { cacheLocale, resolveUiLocale, type AppLocale } from '../i18n/locale';

interface AuthContextValue {
  user: CurrentUser | null;
  loading: boolean;
  activeTenantId: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  switchTenant: (tenantId: string) => Promise<void>;
  refresh: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
  /** Switches the UI language at once; signed in, it is also saved as the user's preference. null = follow the tenant default / browser. */
  setLanguage: (locale: AppLocale | null) => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

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
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchMe();
  }, [fetchMe]);

  const login = useCallback(async (email: string, password: string) => {
    try {
      const { data } = await apiClient.post<{ data: { token: string; user: CurrentUser } }>('/auth/login', {
        email,
        password,
      });
      localStorage.setItem('optifleet_token', data.data.token);
      if (data.data.user.scope === 'tenant' && data.data.user.memberships.length > 0) {
        const firstActive = data.data.user.memberships.find((m) => m.status === 'active') ?? data.data.user.memberships[0];
        localStorage.setItem('optifleet_active_tenant', firstActive.tenant_id);
        setActiveTenantId(firstActive.tenant_id);
      } else {
        localStorage.removeItem('optifleet_active_tenant');
        setActiveTenantId(null);
      }
      setUser(data.data.user);
      applyUserLocale(data.data.user);
    } catch (error) {
      throw extractApiError(error);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiClient.post('/auth/logout');
    } finally {
      localStorage.removeItem('optifleet_token');
      localStorage.removeItem('optifleet_active_tenant');
      setUser(null);
      setActiveTenantId(null);
    }
  }, []);

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
    () => ({ user, loading, activeTenantId, login, logout, switchTenant, refresh: fetchMe, hasPermission, setLanguage }),
    [user, loading, activeTenantId, login, logout, switchTenant, fetchMe, hasPermission, setLanguage],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
