import { useState } from 'react';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { ConfigurationHistoryRow, ConfigurationType } from '../../../types';
import { formatDateTime } from '../../../utils/date';
import { labelText, t as tt, withLabels } from '../../../i18n/i18n';

const TYPES: Array<{ value: ConfigurationType | ''; label: string }> = withLabels([
  { value: '', label: 'All types', labelKey: 'configuration.fields.allTypes' },
  { value: 'NUMBERING', label: 'Numbering', labelKey: 'configuration.fields.numbering' },
  { value: 'TEMPLATE', label: 'Document Template', labelKey: 'configuration.fields.documentTemplate' },
  { value: 'WORKFLOW', label: 'Workflow', labelKey: 'configuration.fields.workflow' },
  { value: 'NOTIFICATION', label: 'Notification Template', labelKey: 'configuration.fields.notificationTemplate' },
]);

/** Section 44: one centralized view across every configuration subsystem. */
export function ConfigurationHistoryPage() {
  const [type, setType] = useState<ConfigurationType | ''>('');
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<ConfigurationHistoryRow>('/app/configuration/history', { type: type || undefined, page }, 0);

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('configuration.titles.configurationHistory')}</h1>
      <Toolbar>
        <select
          value={type}
          onChange={(e) => {
            setType(e.target.value as ConfigurationType | '');
            setPage(1);
          }}
          style={{ padding: '8px 10px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 14 }}
        >
          {TYPES.map((t) => (
            <option key={t.value} value={t.value}>
              {labelText(t)}
            </option>
          ))}
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('configuration.empty.noConfigurationHistoryYet')} />}
      {!error && !loading && data.length > 0 && (
        <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse', border: '1px solid #e5e7eb', borderRadius: 8 }}>
          <thead>
            <tr style={{ background: '#f9fafb', textAlign: 'left' }}>
              <th style={{ padding: 10 }}>{tt('common.fields.type')}</th>
              <th style={{ padding: 10 }}>{tt('common.fields.code')}</th>
              <th style={{ padding: 10 }}>{tt('common.fields.name')}</th>
              <th style={{ padding: 10 }}>{tt('configuration.fields.version')}</th>
              <th style={{ padding: 10 }}>{tt('common.fields.status')}</th>
              <th style={{ padding: 10 }}>{tt('configuration.fields.published')}</th>
              <th style={{ padding: 10 }}>{tt('configuration.fields.archived')}</th>
              <th style={{ padding: 10 }}>{tt('configuration.fields.changeSummary')}</th>
            </tr>
          </thead>
          <tbody>
            {data.map((row) => (
              <tr key={row.id} style={{ borderTop: '1px solid #f1f5f9' }}>
                <td style={{ padding: 10 }}>{row.type}</td>
                <td style={{ padding: 10 }}>{row.code}</td>
                <td style={{ padding: 10 }}>{row.name}</td>
                <td style={{ padding: 10 }}>v{row.version_number}</td>
                <td style={{ padding: 10 }}>
                  <StatusBadge status={row.status} />
                </td>
                <td style={{ padding: 10 }}>{row.published_at ? formatDateTime(row.published_at) : '—'}</td>
                <td style={{ padding: 10 }}>{row.archived_at ? formatDateTime(row.archived_at) : '—'}</td>
                <td style={{ padding: 10, color: '#6b7280' }}>{row.change_summary ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
