import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../api/client';
import type { CurrentUser } from '../types';

interface AuthContextValue {
  user: CurrentUser | null;
  loading: boolean;
  activeTenantId: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  switchTenant: (tenantId: string) => Promise<void>;
  refresh: () => Promise<void>;
  hasPermission: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

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

  const value = useMemo(
    () => ({ user, loading, activeTenantId, login, logout, switchTenant, refresh: fetchMe, hasPermission }),
    [user, loading, activeTenantId, login, logout, switchTenant, fetchMe, hasPermission],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
