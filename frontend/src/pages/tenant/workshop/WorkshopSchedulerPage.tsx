import { useEffect, useMemo, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { StatusBadge } from '../../../components/StatusBadge';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import type { WorkspaceItem, WorkspaceReservationItem } from '../../../types';

function startOfWeek(date: Date): Date {
  const d = new Date(date);
  const day = d.getDay();
  const diff = (day === 0 ? -6 : 1) - day;
  d.setDate(d.getDate() + diff);
  d.setHours(0, 0, 0, 0);
  return d;
}

function toDateInput(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function WorkshopSchedulerPage() {
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [workshopId, setWorkshopId] = useState('');
  const [from, setFrom] = useState(toDateInput(startOfWeek(new Date())));
  const [to, setTo] = useState(() => {
    const d = startOfWeek(new Date());
    d.setDate(d.getDate() + 6);
    return toDateInput(d);
  });
  const [workspaces, setWorkspaces] = useState<(WorkspaceItem & { reservations?: WorkspaceReservationItem[] })[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => {
      setWorkshops(res.data.data);
      if (res.data.data.length > 0) setWorkshopId((prev) => prev || res.data.data[0].id);
    });
  }, []);

  useEffect(() => {
    if (!workshopId) return;
    setLoading(true);
    setError(null);
    apiClient
      .get('/app/workshop-scheduler', { params: { workshop_id: workshopId, from, to: `${to} 23:59:59` } })
      .then((res) => setWorkspaces(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, [workshopId, from, to]);

  const days = useMemo(() => {
    const result: string[] = [];
    const start = new Date(from);
    const end = new Date(to);
    for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
      result.push(toDateInput(d));
    }
    return result;
  }, [from, to]);

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workshop Scheduler</h1>
      <div style={{ display: 'flex', gap: 10, marginBottom: 16, alignItems: 'center', flexWrap: 'wrap' }}>
        <select value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
          {workshops.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
        <label style={{ fontSize: 13 }}>
          From <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} style={{ ...inputStyle, width: 150 }} />
        </label>
        <label style={{ fontSize: 13 }}>
          To <input type="date" value={to} onChange={(e) => setTo(e.target.value)} style={{ ...inputStyle, width: 150 }} />
        </label>
      </div>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && workspaces.length === 0 && <EmptyState label="No workspaces found for this workshop." />}
      {!error && !loading && workspaces.length > 0 && (
        <div className="card" style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
            <thead>
              <tr>
                <th style={{ textAlign: 'left', padding: 8, borderBottom: '1px solid #e5e7eb', minWidth: 160 }}>Workspace</th>
                {days.map((d) => (
                  <th key={d} style={{ textAlign: 'left', padding: 8, borderBottom: '1px solid #e5e7eb', minWidth: 160 }}>
                    {d}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {workspaces.map((ws) => (
                <tr key={ws.id}>
                  <td style={{ padding: 8, borderBottom: '1px solid #f3f4f6', verticalAlign: 'top' }}>
                    <div style={{ fontWeight: 600 }}>{ws.name}</div>
                    <div style={{ color: '#9ca3af' }}>{ws.code}</div>
                    <StatusBadge status={ws.status} />
                  </td>
                  {days.map((d) => {
                    const dayReservations = (ws.reservations ?? []).filter((r) => r.start_at.slice(0, 10) <= d && r.end_at.slice(0, 10) >= d);
                    return (
                      <td key={d} style={{ padding: 6, borderBottom: '1px solid #f3f4f6', verticalAlign: 'top' }}>
                        {dayReservations.map((r) => (
                          <div key={r.id} style={{ background: '#eff6ff', borderRadius: 6, padding: '4px 6px', marginBottom: 4 }}>
                            <div style={{ fontWeight: 600 }}>{r.work_order?.wo_number ?? 'Reserved'}</div>
                            <div style={{ color: '#6b7280' }}>{r.work_order?.vehicle?.registration_number ?? ''}</div>
                            <StatusBadge status={r.status} />
                          </div>
                        ))}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
