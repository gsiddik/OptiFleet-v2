import { useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
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

const TABS = ['Overview', 'Users', 'Module Entitlements', 'Capacity Limits', 'Contract'] as const;

export function TenantDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();
  const initialTab = (searchParams.get('tab') as (typeof TABS)[number] | null) ?? 'Overview';
  const [tab, setTab] = useState<(typeof TABS)[number]>(TABS.includes(initialTab) ? initialTab : 'Overview');

  useBreadcrumbLabel(tenant?.id, tenant ? `${tenant.name} (${tenant.code})` : undefined);

  function selectTab(t: (typeof TABS)[number]) {
    setTab(t);
    setSearchParams(t === 'Overview' ? {} : { tab: t }, { replace: true });
  }

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
        {TABS.map((t) => (
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
            {t}
          </button>
        ))}
      </div>

      {tab === 'Overview' && (
        <div className="card">
          <p>
            <strong>Legal Name:</strong> {tenant.legal_name ?? '—'}
          </p>
          <p>
            <strong>Industry:</strong> {tenant.industry ?? '—'}
          </p>
          <p>
            <strong>Created:</strong> {new Date(tenant.created_at).toLocaleString()}
          </p>
        </div>
      )}
      {tab === 'Users' && <TenantUsersTab tenantId={tenant.id} />}
      {tab === 'Module Entitlements' && <TenantEntitlementsTab tenantId={tenant.id} />}
      {tab === 'Capacity Limits' && <TenantCapacityTab tenantId={tenant.id} />}
      {tab === 'Contract' && <TenantContractTab tenantId={tenant.id} tenantName={tenant.name} tenantCode={tenant.code} />}
    </div>
  );
}
