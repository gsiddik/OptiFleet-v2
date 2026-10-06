import { formatDateTime } from '../../utils/date';
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
        No analytics data has been generated yet for this tenant.
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
      <span>Data as of {freshness.data_as_of} · last ETL run {asOf}</span>
      {freshness.is_stale && <span style={{ fontWeight: 600 }}>· STALE</span>}
    </div>
  );
}
