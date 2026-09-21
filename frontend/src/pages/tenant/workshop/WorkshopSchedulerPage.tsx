import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { StatusBadge } from '../../../components/StatusBadge';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import type { WorkspaceItem, WorkspaceReservationItem } from '../../../types';

function toDateInput(d: Date): string {
  return d.toISOString().slice(0, 10);
}

function todayDateInput(): string {
  return toDateInput(new Date());
}

function addDays(dateInput: string, days: number): string {
  const d = new Date(`${dateInput}T00:00:00`);
  d.setDate(d.getDate() + days);
  return toDateInput(d);
}

/**
 * "Next Improvement Tenant Portal - Products" (Scheduler): status color
 * per the source document — Scheduled=Yellow, In Progress=Red, On Hold/
 * Waiting Part/QC Pending=Orange, Completed=Green. Closed Work Orders
 * never appear here at all (excluded server-side), so no color is
 * defined for it. Anything earlier in the lifecycle (Draft/Submitted/
 * Approved/Assigned) or terminal-but-not-closed (Rework/Rejected/
 * Cancelled) falls back to a neutral color — the document only specifies
 * these five.
 */
const STATUS_COLORS: Record<string, string> = {
  SCHEDULED: '#fef9c3',
  IN_PROGRESS: '#fee2e2',
  ON_HOLD: '#ffedd5',
  WAITING_PART: '#ffedd5',
  QC_PENDING: '#ffedd5',
  COMPLETED: '#dcfce7',
};

function cardColor(status: string | undefined): string {
  return (status && STATUS_COLORS[status]) || '#eff6ff';
}

export function WorkshopSchedulerPage() {
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [workshopId, setWorkshopId] = useState('');
  const [from, setFrom] = useState(todayDateInput());
  const [workspaces, setWorkspaces] = useState<(WorkspaceItem & { reservations?: WorkspaceReservationItem[] })[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const to = useMemo(() => addDays(from, 6), [from]);

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

  // Leftmost column is always today by default; Prev/Next Week shift the
  // whole seven-day window a week at a time in either direction.
  const days = useMemo(() => {
    const result: string[] = [];
    for (let i = 0; i < 7; i++) result.push(addDays(from, i));
    return result;
  }, [from]);

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
        <button className="btn-secondary" onClick={() => setFrom((d) => addDays(d, -7))}>
          ← Previous Week
        </button>
        <span style={{ fontSize: 13, color: '#374151' }}>
          {from} – {to}
        </span>
        <button className="btn-secondary" onClick={() => setFrom((d) => addDays(d, 7))}>
          Next Week →
        </button>
        <button className="btn-secondary" onClick={() => setFrom(todayDateInput())}>
          Today
        </button>
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
                    {d === todayDateInput() ? `${d} (Today)` : d}
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
                    const dayReservations = (ws.reservations ?? [])
                      .filter((r) => r.start_at.slice(0, 10) <= d && r.end_at.slice(0, 10) >= d)
                      .sort((a, b) => a.start_at.localeCompare(b.start_at));
                    return (
                      <td key={d} style={{ padding: 6, borderBottom: '1px solid #f3f4f6', verticalAlign: 'top' }}>
                        {dayReservations.map((r) => (
                          <Link
                            key={r.id}
                            to={r.work_order_id ? `/app/work-orders/${r.work_order_id}` : '#'}
                            style={{
                              display: 'block', background: cardColor(r.work_order?.status), borderRadius: 6,
                              padding: '4px 6px', marginBottom: 4, textDecoration: 'none', color: 'inherit',
                            }}
                          >
                            <div style={{ fontWeight: 600 }}>{r.work_order?.wo_number ?? 'Reserved'}</div>
                            <div style={{ color: '#6b7280' }}>{r.work_order?.vehicle?.registration_number ?? ''}</div>
                            <div style={{ color: '#6b7280' }}>{r.start_at.slice(11, 16)}</div>
                          </Link>
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
