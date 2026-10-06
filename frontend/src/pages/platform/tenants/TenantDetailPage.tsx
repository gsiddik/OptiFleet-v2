import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { BackButton } from '../../../components/BackButton';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { Tenant } from '../../../types';
import { TenantUsersTab } from './tabs/TenantUsersTab';
import { TenantEntitlementsTab } from './tabs/TenantEntitlementsTab';
import { TenantCapacityTab } from './tabs/TenantCapacityTab';
import { TenantContractTab } from './tabs/TenantContractTab';
import { useAuth } from '../../../auth/AuthContext';
import { useTabParam } from '../../../hooks/useTabParam';
import type { TabDef } from '../../../utils/tabs';
import { formatDateTime } from '../../../utils/date';

type TenantTab = 'overview' | 'users' | 'module-entitlements' | 'capacity-limits' | 'contract';
// Stable ids drive state and ?tab=; labels are display only. Legacy ?tab=Contract links still resolve.
const TABS: readonly TabDef<TenantTab>[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'users', label: 'Users' },
  { id: 'module-entitlements', label: 'Module Entitlements' },
  { id: 'capacity-limits', label: 'Capacity Limits' },
  { id: 'contract', label: 'Contract' },
];

export function TenantDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, selectTab] = useTabParam(TABS, 'overview');

  useBreadcrumbLabel(tenant?.id, tenant ? `${tenant.name} (${tenant.code})` : undefined);

  function load() {
    apiClient
      .get(`/platform/tenants/${id}`)
      .then((res) => setTenant(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function toggleStatus() {
    if (!tenant) return;
    const action = tenant.status === 'ACTIVE' ? 'deactivate' : 'activate';
    if (!hasPermission(`tenant.${action}`)) return;
    await apiClient.post(`/platform/tenants/${id}/${action}`);
    load();
  }

  if (error) return <ErrorState message={error} />;
  if (!tenant) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/platform/tenants" label="← Back to Tenant Management" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {tenant.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({tenant.code})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={tenant.status} />
          {(hasPermission('tenant.activate') || hasPermission('tenant.deactivate')) && (
            <button className="btn-secondary" onClick={toggleStatus}>
              {tenant.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}
            </button>
          )}
        </div>
      </div>

      <div style={{ display: 'flex', gap: 4, borderBottom: '1px solid #e5e7eb', marginBottom: 20 }}>
        {TABS.map(({ id: t, label }) => (
          <button
            key={t}
            onClick={() => selectTab(t)}
            style={{
              background: 'none',
              border: 'none',
              padding: '10px 14px',
              fontSize: 14,
              cursor: 'pointer',
              borderBottom: tab === t ? '2px solid #1d4ed8' : '2px solid transparent',
              color: tab === t ? '#1d4ed8' : '#6b7280',
              fontWeight: tab === t ? 600 : 400,
            }}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <div className="card">
          <p>
            <strong>Legal Name:</strong> {tenant.legal_name ?? '—'}
          </p>
          <p>
            <strong>Industry:</strong> {tenant.industry ?? '—'}
          </p>
          <p>
            <strong>Created:</strong> {formatDateTime(tenant.created_at)}
          </p>
        </div>
      )}
      {tab === 'users' && <TenantUsersTab tenantId={tenant.id} />}
      {tab === 'module-entitlements' && <TenantEntitlementsTab tenantId={tenant.id} />}
      {tab === 'capacity-limits' && <TenantCapacityTab tenantId={tenant.id} />}
      {tab === 'contract' && <TenantContractTab tenantId={tenant.id} tenantName={tenant.name} tenantCode={tenant.code} />}
    </div>
  );
}
