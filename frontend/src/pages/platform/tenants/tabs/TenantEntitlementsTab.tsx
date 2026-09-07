import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../../api/client';
import { ErrorState, LoadingState } from '../../../../components/States';
import type { ModuleCatalogItem, TenantModuleEntitlement } from '../../../../types';
import { useAuth } from '../../../../auth/AuthContext';

export function TenantEntitlementsTab({ tenantId }: { tenantId: string }) {
  const { hasPermission } = useAuth();
  const [entitlements, setEntitlements] = useState<TenantModuleEntitlement[] | null>(null);
  const [modules, setModules] = useState<ModuleCatalogItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busyModuleId, setBusyModuleId] = useState<string | null>(null);

  function load() {
    Promise.all([apiClient.get(`/platform/tenants/${tenantId}/entitlements`), apiClient.get('/platform/modules')])
      .then(([entRes, modRes]) => {
        setEntitlements(entRes.data.data);
        setModules(modRes.data.data);
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [tenantId]);

  async function toggle(module: ModuleCatalogItem, active: boolean) {
    setError(null);
    setBusyModuleId(module.id);
    try {
      await apiClient.post(`/platform/tenants/${tenantId}/entitlements`, { module_id: module.id, active });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyModuleId(null);
    }
  }

  if (error) return <ErrorState message={error} />;
  if (!entitlements) return <LoadingState />;

  const entitledMap = new Map(entitlements.map((e) => [e.module_code, e]));

  return (
    <div className="card">
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
        <thead>
          <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
            <th style={{ padding: '8px 4px' }}>Module</th>
            <th style={{ padding: '8px 4px' }}>Category</th>
            <th style={{ padding: '8px 4px' }}>Status</th>
            <th style={{ padding: '8px 4px' }} />
          </tr>
        </thead>
        <tbody>
          {modules.map((m) => {
            const entitlement = entitledMap.get(m.code);
            const isActive = entitlement?.active ?? false;
            return (
              <tr key={m.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '8px 4px' }}>
                  {m.name} <span style={{ color: '#9ca3af' }}>({m.code})</span>
                </td>
                <td style={{ padding: '8px 4px' }}>{m.category}</td>
                <td style={{ padding: '8px 4px' }}>{isActive ? '✅ Active' : '⬜ Not entitled'}</td>
                <td style={{ padding: '8px 4px', textAlign: 'right' }}>
                  {hasPermission('entitlement.manage') && (
                    <button
                      className="btn-secondary"
                      disabled={busyModuleId === m.id}
                      onClick={() => toggle(m, !isActive)}
                    >
                      {isActive ? 'Revoke' : 'Grant'}
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
