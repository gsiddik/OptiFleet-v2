import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../api/client';
import { inputStyle } from './FormField';
import { Modal } from './Modal';
import { ErrorState, LoadingState } from './States';
import type { Branch, Warehouse, Workshop } from '../types';
import { t } from '../i18n/i18n';

interface DataScope {
  id: string;
  scope_type: string;
  scope_resource_id: string | null;
}

/**
 * The local data scope (which branches, workshops, warehouses a person sees) of one tenant membership. In a centrally governed
 * organization permissions come from OptiNexus, while the data scope stays local and can only narrow what a person sees.
 */
export function DataScopeModal({ memberId, name, onClose }: { memberId: string; name: string; onClose: () => void }) {
  const [scopes, setScopes] = useState<DataScope[]>([]);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [workshops, setWorkshops] = useState<Workshop[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [scopeType, setScopeType] = useState('TENANT');
  const [scopeResourceId, setScopeResourceId] = useState('');

  function load() {
    Promise.all([
      apiClient.get(`/app/users/${memberId}/data-scopes`),
      apiClient.get('/app/branches', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } })),
      apiClient.get('/app/workshops', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } })),
      apiClient.get('/app/warehouses', { params: { per_page: 100 } }).catch(() => ({ data: { data: [] } })),
    ])
      .then(([scopeRes, branchRes, workshopRes, warehouseRes]) => {
        setScopes(scopeRes.data.data);
        setBranches(branchRes.data.data);
        setWorkshops(workshopRes.data.data);
        setWarehouses(warehouseRes.data.data);
        setError(null);
      })
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }

  useEffect(load, [memberId]);

  async function run(action: () => Promise<unknown>) {
    try {
      await action();
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  const options = scopeType === 'BRANCH' ? branches : scopeType === 'WORKSHOP' ? workshops : scopeType === 'WAREHOUSE' ? warehouses : [];

  return (
    <Modal open title={t('delegation.users.dataScopeOf', { name })} onClose={onClose} width={520}>
      {loading ? (
        <LoadingState label={t('common.actions.loading')} />
      ) : (
        <>
          {error && <ErrorState message={error} />}
          <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('delegation.users.dataScopeHint')}</p>
          <div style={{ marginBottom: 8 }}>
            {scopes.length === 0 && <div style={{ color: '#9ca3af', fontSize: 13 }}>{t('access.empty.noScopeAssignedNoAccess')}</div>}
            {scopes.map((s) => (
              <div key={s.id} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, padding: '3px 0' }}>
                <span>
                  {s.scope_type}
                  {s.scope_resource_id ? ` — ${s.scope_resource_id}` : ''}
                </span>
                <button className="btn-link" onClick={() => run(() => apiClient.delete(`/app/users/${memberId}/data-scopes/${s.id}`))}>
                  {t('platform.modules.actions.remove')}
                </button>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <select value={scopeType} onChange={(e) => setScopeType(e.target.value)} style={{ ...inputStyle, width: 130 }}>
              <option value="TENANT">{t('access.fields.tenant')}</option>
              <option value="BRANCH">{t('access.fields.branch')}</option>
              <option value="WORKSHOP">{t('access.fields.workshop')}</option>
              <option value="WAREHOUSE">{t('access.fields.warehouse')}</option>
              <option value="OWN">{t('access.fields.own')}</option>
            </select>
            {!['TENANT', 'OWN'].includes(scopeType) && (
              <select value={scopeResourceId} onChange={(e) => setScopeResourceId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
                <option value="">{t('access.fields.selectResource')}</option>
                {options.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name}
                  </option>
                ))}
              </select>
            )}
            <button
              className="btn-secondary"
              onClick={() =>
                run(() =>
                  apiClient.post(`/app/users/${memberId}/data-scopes`, {
                    scope_type: scopeType,
                    scope_resource_id: ['TENANT', 'OWN'].includes(scopeType) ? null : scopeResourceId,
                  }),
                )
              }
            >
              {t('common.actions.add2')}
            </button>
          </div>
        </>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
        <button className="btn-primary" onClick={onClose}>
          {t('access.actions.done')}
        </button>
      </div>
    </Modal>
  );
}
