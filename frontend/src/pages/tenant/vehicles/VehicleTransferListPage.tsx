import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { VehicleTransferItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';
import { t as tt } from '../../../i18n/i18n';

const STATUSES = ['', 'DRAFT', 'REQUESTED', 'APPROVED', 'IN_TRANSIT', 'RECEIVED', 'COMPLETED', 'REJECTED', 'CANCELLED'];

export function VehicleTransferListPage() {
  const [status, setStatus] = useState('');
  const { data, loading, error } = useApiList<VehicleTransferItem>('/app/vehicle-transfers', { status: status || undefined });

  const columns: Column<VehicleTransferItem>[] = [
    { key: 'vehicle', header: tt('common.fields.vehicle'), render: (t) => <Link to={`/app/vehicles/${t.vehicle_id}`}>{t.vehicle?.registration_number ?? t.vehicle_id}</Link> },
    { key: 'from', header: tt('vehicle.fields.fromBranch'), render: (t) => t.from_branch?.name ?? '—' },
    { key: 'to', header: tt('configuration.labels.toBranch'), render: (t) => t.to_branch?.name ?? '—' },
    { key: 'status', header: tt('common.fields.status'), render: (t) => <StatusBadge status={t.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('vehicle.titles.vehicleTransfers')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 14 }}>
        {tt('vehicle.help.fleetWideViewVehicleTransfersOpen')}
      </p>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : tt('common.actions.all')}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('vehicle.empty.noVehicleTransfersFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
