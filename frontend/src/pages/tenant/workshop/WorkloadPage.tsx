import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import type { WorkerItem } from '../../../types';

export function WorkloadPage() {
  const [workers, setWorkers] = useState<WorkerItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/workers/workload')
      .then((res) => setWorkers(res.data.data))
      .catch((err) => setError(extractApiError(err).message))
      .finally(() => setLoading(false));
  }, []);

  const columns: Column<WorkerItem>[] = [
    { key: 'name', header: 'Worker', render: (w) => `${w.name} (${w.employee_code})` },
    { key: 'worker_type', header: 'Type', render: (w) => w.worker_type },
    { key: 'workshop', header: 'Workshop', render: (w) => w.workshop?.name ?? '—' },
    {
      key: 'active_job_count',
      header: 'Active Jobs',
      render: (w) => (
        <span style={{ fontWeight: 600, color: (w.active_job_count ?? 0) > 2 ? '#b91c1c' : '#111827' }}>{w.active_job_count ?? 0}</span>
      ),
    },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Mechanic Workload</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>
        Count of active (non-completed) Work Order jobs currently assigned to each active mechanic, sorted busiest first.
      </p>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && workers.length === 0 && <EmptyState label="No active workers found." />}
      {!error && !loading && workers.length > 0 && <Table columns={columns} rows={workers} />}
    </div>
  );
}
