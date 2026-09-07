import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { StatusBadge } from '../../../components/StatusBadge';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import type { HistoryEventItem, VehicleItem } from '../../../types';

export function VehicleHistoryPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [vehicleId, setVehicleId] = useState(searchParams.get('vehicle_id') ?? '');
  const [events, setEvents] = useState<HistoryEventItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/vehicles', { params: { per_page: 200 } }).then((res) => {
      setVehicles(res.data.data);
      if (!vehicleId && res.data.data.length > 0) setVehicleId(res.data.data[0].id);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!vehicleId) return;
    setSearchParams({ vehicle_id: vehicleId });
    setLoading(true);
    setError(null);
    apiClient
      .get(`/app/vehicles/${vehicleId}/history`)
      .then((res) => setEvents(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vehicleId]);

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Maintenance History</h1>
      <div style={{ marginBottom: 16 }}>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={{ ...inputStyle, width: 280 }}>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number} — {v.brand} {v.model}
            </option>
          ))}
        </select>
      </div>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && events.length === 0 && <EmptyState label="No history events for this vehicle." />}
      {!error && !loading && events.length > 0 && (
        <div className="card">
          {events.map((e) => (
            <div key={`${e.type}-${e.id}`} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 10, alignItems: 'center' }}>
              <StatusBadge status={e.type} />
              <span style={{ color: '#9ca3af', minWidth: 160 }}>{new Date(e.at).toLocaleString()}</span>
              <span>{e.summary}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
