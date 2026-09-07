import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import type { PartnerItem } from '../../../types';

export function PartnerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [partner, setPartner] = useState<PartnerItem | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get(`/app/partners/${id}`)
      .then((res) => setPartner(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, [id]);

  if (error && !partner) return <ErrorState message={error} />;
  if (!partner) return <LoadingState />;

  const perf = partner.performance;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {partner.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({partner.code})</span>
        </h1>
        <StatusBadge status={partner.status} />
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
        <p style={{ fontSize: 13 }}>
          <strong>Type:</strong> {partner.partner_type} &nbsp; <strong>Contact:</strong> {partner.contact_name ?? '—'} ({partner.contact_phone ?? '—'})
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Payment Terms:</strong> {partner.payment_terms ?? '—'} &nbsp; <strong>Tax ID:</strong> {partner.tax_id ?? '—'}
        </p>
      </div>

      {perf && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Performance</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
            {[
              { label: 'POs Issued', value: perf.purchase_orders_issued },
              { label: 'On-Time Rate', value: perf.on_time_rate !== null ? `${perf.on_time_rate}%` : '—' },
              { label: 'On-Time Deliveries', value: perf.deliveries_on_time },
              { label: 'Late Deliveries', value: perf.deliveries_late },
              { label: 'Qty Accepted', value: perf.quantity_accepted },
              { label: 'Qty Rejected', value: perf.quantity_rejected },
              { label: 'Total Purchase Value', value: perf.total_purchase_value },
              { label: 'Returns', value: perf.returns },
            ].map((s) => (
              <div key={s.label}>
                <div style={{ fontSize: 12, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 20, fontWeight: 700 }}>{s.value}</div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
