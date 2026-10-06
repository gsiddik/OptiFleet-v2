import { formatDate, formatDateTime } from '../../utils/date';
import { t } from '../../i18n/i18n';
export interface Freshness {
  data_as_of: string | null;
  last_successful_etl_at: string | null;
  is_stale: boolean;
}

// Section 17: never let a dashboard present a stale snapshot as if it
// were real-time without saying so.
export function FreshnessBanner({ freshness }: { freshness: Freshness }) {
  if (!freshness.last_successful_etl_at) {
    return (
      <div style={{ fontSize: 12, color: '#b91c1c', marginBottom: 12 }}>
        {t('common.empty.noAnalyticsDataBeenGeneratedYet')}
      </div>
    );
  }

  const asOf = formatDateTime(freshness.last_successful_etl_at);

  return (
    <div
      style={{
        fontSize: 12,
        color: freshness.is_stale ? '#b45309' : '#6b7280',
        marginBottom: 12,
        display: 'flex',
        alignItems: 'center',
        gap: 6,
      }}
    >
      <span>{t('common.help.dataDataLastEtlRunAs', { data_as_of: formatDate(freshness.data_as_of), asOf })}</span>
      {freshness.is_stale && <span style={{ fontWeight: 600 }}>{t('common.fields.stale')}</span>}
    </div>
  );
}
