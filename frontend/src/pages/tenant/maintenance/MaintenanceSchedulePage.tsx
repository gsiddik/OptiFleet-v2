import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { MaintenanceScheduleItem } from '../../../types';

const STATUSES = ['', 'UPCOMING', 'DUE_SOON', 'DUE', 'OVERDUE', 'SCHEDULED', 'COMPLETED'];

export function MaintenanceSchedulePage() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data, loading, error: listError } = useApiList<MaintenanceScheduleItem>('/app/maintenance-schedules', { status: status || undefined }, reloadKey);

  async function refresh(schedule: MaintenanceScheduleItem) {
    setBusyId(schedule.id);
    setError(null);
    try {
      await apiClient.post(`/app/maintenance-schedules/${schedule.id}/refresh`);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  /** G-01: previously a due schedule had no path to a Work Order at all. */
  async function convertToWorkOrder(schedule: MaintenanceScheduleItem) {
    setBusyId(schedule.id);
    setError(null);
    try {
      const res = await apiClient.post(`/app/maintenance-schedules/${schedule.id}/work-order`);
      navigate(`/app/work-orders/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<MaintenanceScheduleItem>[] = [
    { key: 'vehicle', header: 'Vehicle', render: (s) => <Link to={`/app/vehicles/${s.vehicle_id}`}>{s.vehicle?.registration_number ?? s.vehicle_id}</Link> },
    { key: 'package', header: 'Package', render: (s) => s.package?.name ?? '—' },
    { key: 'due_date', header: 'Next Due Date', render: (s) => s.next_due_date ?? '—' },
    { key: 'due_odometer', header: 'Next Due Odometer', render: (s) => (s.next_due_odometer != null ? Number(s.next_due_odometer).toLocaleString() : '—') },
    { key: 'status', header: 'Status', render: (s) => <StatusBadge status={s.status} /> },
    {
      key: 'actions',
      header: '',
      render: (s) => (
        <div style={{ display: 'flex', gap: 6 }}>
          {hasPermission('maintenance_schedule.manage') && (
            <button className="btn-secondary" disabled={busyId === s.id} onClick={() => refresh(s)}>
              Recalculate
            </button>
          )}
          {['DUE_SOON', 'DUE', 'OVERDUE'].includes(s.status) && hasPermission('maintenance_schedule.convert_work_order') && (
            <button className="btn-primary" disabled={busyId === s.id} onClick={() => convertToWorkOrder(s)}>
              Convert to WO
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Maintenance Planning &amp; Schedule</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && data.length === 0 && <EmptyState label="No maintenance schedules found." />}
      {!listError && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
