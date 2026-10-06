import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../../api/client';
import { ErrorState, LoadingState } from '../../../../components/States';
import { inputStyle } from '../../../../components/FormField';
import { useAuth } from '../../../../auth/AuthContext';
import { NumericInput } from '../../../../components/NumericInput';
import { t } from '../../../../i18n/i18n';

interface CapacityRow {
  resource_type: string;
  max_count: number | null;
  current_count: number;
}

export function TenantCapacityTab({ tenantId }: { tenantId: string }) {
  const { hasPermission } = useAuth();
  const [rows, setRows] = useState<CapacityRow[] | null>(null);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [savingType, setSavingType] = useState<string | null>(null);

  function load() {
    apiClient
      .get(`/platform/tenants/${tenantId}/capacity-limits`)
      .then((res) => {
        setRows(res.data.data);
        const d: Record<string, string> = {};
        res.data.data.forEach((r: CapacityRow) => (d[r.resource_type] = r.max_count?.toString() ?? ''));
        setDrafts(d);
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [tenantId]);

  async function save(resourceType: string) {
    const value = drafts[resourceType];
    if (value === '') return;
    setSavingType(resourceType);
    try {
      await apiClient.put(`/platform/tenants/${tenantId}/capacity-limits`, {
        resource_type: resourceType,
        max_count: Number(value),
      });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSavingType(null);
    }
  }

  if (error) return <ErrorState message={error} />;
  if (!rows) return <LoadingState />;

  return (
    <div className="card">
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
        <thead>
          <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
            <th style={{ padding: '8px 4px' }}>{t('common.fields.resource')}</th>
            <th style={{ padding: '8px 4px' }}>{t('platform.tenants.fields.currentUsage')}</th>
            <th style={{ padding: '8px 4px' }}>{t('platform.tenants.fields.maxLimit')}</th>
            <th style={{ padding: '8px 4px' }} />
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.resource_type} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '8px 4px', textTransform: 'capitalize' }}>{r.resource_type}</td>
              <td style={{ padding: '8px 4px' }}>{r.current_count}</td>
              <td style={{ padding: '8px 4px' }}>
                <NumericInput
                  min={0}
                  value={drafts[r.resource_type] ?? ''}
                  onChange={(e) => setDrafts({ ...drafts, [r.resource_type]: e.target.value })}
                  style={{ ...inputStyle, width: 100 }}
                  disabled={!hasPermission('entitlement.manage')}
                  placeholder={t('platform.tenants.placeholders.unlimited')}
                />
              </td>
              <td style={{ padding: '8px 4px' }}>
                {hasPermission('entitlement.manage') && (
                  <button className="btn-secondary" disabled={savingType === r.resource_type} onClick={() => save(r.resource_type)}>
                    {t('common.actions.save')}
                  </button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
