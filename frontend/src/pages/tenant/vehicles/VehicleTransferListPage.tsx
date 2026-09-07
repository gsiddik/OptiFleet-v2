import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { VehicleTransferItem } from '../../../types';

const STATUSES = ['', 'DRAFT', 'REQUESTED', 'APPROVED', 'IN_TRANSIT', 'RECEIVED', 'COMPLETED', 'REJECTED', 'CANCELLED'];

export function VehicleTransferListPage() {
  const [status, setStatus] = useState('');
  const { data, loading, error } = useApiList<VehicleTransferItem>('/app/vehicle-transfers', { status: status || undefined });

  const columns: Column<VehicleTransferItem>[] = [
    { key: 'vehicle', header: 'Vehicle', render: (t) => <Link to={`/app/vehicles/${t.vehicle_id}`}>{t.vehicle?.registration_number ?? t.vehicle_id}</Link> },
    { key: 'from', header: 'From Branch', render: (t) => t.from_branch?.name ?? '—' },
    { key: 'to', header: 'To Branch', render: (t) => t.to_branch?.name ?? '—' },
    { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vehicle Transfers</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 14 }}>
        Fleet-wide view of vehicle transfers. Open a vehicle to create or act on its Transfer.
      </p>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vehicle transfers found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
