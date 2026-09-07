import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { LoadingState } from './States';

export function RequirePlatform({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) return <LoadingState />;
  if (!user) return <Navigate to="/login" replace />;
  if (user.scope !== 'platform') return <Navigate to="/app/dashboard" replace />;
  return <>{children}</>;
}

export function RequireTenant({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) return <LoadingState />;
  if (!user) return <Navigate to="/login" replace />;
  if (user.scope !== 'tenant') return <Navigate to="/platform/dashboard" replace />;
  return <>{children}</>;
}

export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { hasPermission } = useAuth();
  if (!hasPermission(permission)) {
    return (
      <div style={{ padding: 32, color: '#b91c1c' }}>
        You do not have permission ({permission}) to view this page.
      </div>
    );
  }
  return <>{children}</>;
}
