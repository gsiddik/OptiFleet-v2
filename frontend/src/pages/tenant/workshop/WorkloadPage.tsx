import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import type { WorkerItem } from '../../../types';
import { t } from '../../../i18n/i18n';

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
    { key: 'name', header: t('workshop.fields.worker'), render: (w) => `${w.name} (${w.employee_code})` },
    { key: 'worker_type', header: t('common.fields.type'), render: (w) => w.worker_type },
    { key: 'workshop', header: t('common.fields.workshop'), render: (w) => w.workshop?.name ?? '—' },
    {
      key: 'active_job_count',
      header: t('workshop.fields.activeJobs'),
      render: (w) => (
        <span style={{ fontWeight: 600, color: (w.active_job_count ?? 0) > 2 ? '#b91c1c' : '#111827' }}>{w.active_job_count ?? 0}</span>
      ),
    },
    { key: 'status', header: t('common.fields.status'), render: (w) => <StatusBadge status={w.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('workshop.titles.mechanicWorkload')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>
        {t('workshop.help.countActiveNonCompletedWorkOrder')}
      </p>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && workers.length === 0 && <EmptyState label={t('workshop.empty.noActiveWorkersFound')} />}
      {!error && !loading && workers.length > 0 && <Table columns={columns} rows={workers} />}
    </div>
  );
}
