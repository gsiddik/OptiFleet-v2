import { useState, type ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { FormField, inputStyle } from '../../../../components/FormField';
import { Modal } from '../../../../components/Modal';
import { Pagination } from '../../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../../components/States';
import { StatusBadge } from '../../../../components/StatusBadge';
import { Table, type Column } from '../../../../components/Table';
import { Toolbar } from '../../../../components/Toolbar';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { useApiList } from '../../../../hooks/useApiList';
import { useAuth } from '../../../../auth/AuthContext';
import { formatDate } from '../../../../utils/date';
import { formatKm } from './tireOperationFormat';
import { OPERATION_TYPES, OPERATION_TYPE_LABEL, type TireOperationListItem } from './tireOperationTypes';

const TYPE_PERMISSION = { REPLACEMENT: 'tire.install', ROTATION: 'tire.rotate', INSPECTION: 'tire.inspect' } as const;
const STATUSES = ['NEW', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'] as const;

/**
 * Tire Operations: the Recent Tire Operations (Replacement / Rotation / Inspection) with their Work
 * Order and status, newest first. New Tire Operations opens the add page; Edit / Cancel act on one
 * operation — the backend decides whether that is still possible (can_edit / can_cancel).
 * (The earlier tabbed Installation / Rotation / Inspection page is kept at /app/tire-operations/legacy.)
 */
export function TireOperationsLandingPage() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const saved = (location.state as { saved?: { wo_number: string | null; edited: boolean } } | null)?.saved ?? null;
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [cancelling, setCancelling] = useState<TireOperationListItem | null>(null);
  const { data, meta, loading, error } = useApiList<TireOperationListItem>(
    '/app/tire-operations',
    { search: search || undefined, operation_type: type || undefined, status: status || undefined, page, per_page: 15 },
    reloadKey,
  );

  const canCreate = hasPermission('work_order.create') && Object.values(TYPE_PERMISSION).some(hasPermission);
  const lines = (row: TireOperationListItem, render: (item: TireOperationListItem['items'][number]) => ReactNode) => (
    <div style={{ display: 'grid', gap: 4 }}>
      {row.items.map((i) => (
        <div key={i.position_code} style={{ whiteSpace: 'nowrap' }}>
          {render(i)}
        </div>
      ))}
    </div>
  );

  const columns: Column<TireOperationListItem>[] = [
    { key: 'date', header: 'Date', render: (r) => <span style={{ whiteSpace: 'nowrap' }}>{formatDate(r.operated_date)} {r.operated_time}</span> },
    { key: 'wo', header: 'WO#', render: (r) => (r.work_order ? <Link to={`/app/work-orders/${r.work_order.id}`}>{r.work_order.wo_number}</Link> : '—') },
    { key: 'vehicle', header: 'Registration', render: (r) => <Link to={`/app/vehicles/${r.vehicle.id}`}>{r.vehicle.registration_number}</Link> },
    { key: 'event', header: 'Events', render: (r) => OPERATION_TYPE_LABEL[r.operation_type] },
    {
      key: 'position',
      header: 'Position',
      render: (r) => lines(r, (i) => (
        <>
          {r.operation_type === 'ROTATION' && <span style={{ color: '#6b7280', fontSize: 12 }}>Pair {i.pair_number} · </span>}
          <PositionLabel code={i.position_code} />
        </>
      )),
    },
    { key: 'usage', header: 'Usage KM', render: (r) => lines(r, (i) => formatKm(i.usage_km)) },
    { key: 'hours', header: 'Usage Time / Hours Meter', render: (r) => lines(r, (i) => i.usage_hours ?? '—') },
    { key: 'tread', header: 'Last Tread Depth', render: (r) => lines(r, (i) => (i.last_tread_depth_mm != null ? `${i.last_tread_depth_mm} mm` : '—')) },
    { key: 'status', header: 'Tire Operations Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'action',
      header: 'Action',
      render: (r) => (
        <div style={{ display: 'flex', gap: 10 }}>
          {r.can_edit && hasPermission('work_order.update') && hasPermission(TYPE_PERMISSION[r.operation_type]) && (
            <button type="button" className="btn-link" onClick={() => navigate(`/app/tire-operations/${r.id}/edit`)}>
              Edit
            </button>
          )}
          {r.can_cancel && hasPermission('work_order.cancel') && (
            <button type="button" className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setCancelling(r)}>
              Cancel
            </button>
          )}
          {!r.can_edit && !r.can_cancel && <span style={{ color: '#9ca3af' }}>—</span>}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 6 }}>Tire Operations</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 14 }}>Replacement, rotation and inspection jobs. Each one is carried out through the Work Order created with it.</p>
      {saved && (
        <div role="status" data-save-success style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, padding: '8px 12px', marginBottom: 14 }}>
          {saved.edited ? 'Tire Operation updated' : 'Tire Operation saved'}
          {saved.wo_number ? ` — Work Order ${saved.wo_number}` : ''}.
        </div>
      )}
      <section className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 10 }}>
          <h3 style={{ margin: 0, fontSize: 15 }}>
            Recent Tire Operations {meta && <span style={{ color: '#6b7280', fontWeight: 400 }}>({meta.total})</span>}
          </h3>
          {canCreate && (
            <button type="button" className="btn-primary" onClick={() => navigate('/app/tire-operations/new')}>
              New Tire Operations
            </button>
          )}
        </div>
        <Toolbar
          search={search}
          onSearchChange={(v) => {
            setSearch(v);
            setPage(1);
          }}
        >
          <select aria-label="Events filter" value={type} onChange={(e) => (setType(e.target.value), setPage(1))} style={inputStyle}>
            <option value="">All events</option>
            {OPERATION_TYPES.map((t) => (
              <option key={t} value={t}>
                {OPERATION_TYPE_LABEL[t]}
              </option>
            ))}
          </select>
          <select aria-label="Status filter" value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))} style={inputStyle}>
            <option value="">All statuses</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {s.replace('_', ' ')}
              </option>
            ))}
          </select>
        </Toolbar>
        {error && <ErrorState message={error} />}
        {!error && loading && <LoadingState />}
        {!error && !loading && data.length === 0 && <EmptyState label="No tire operations yet." />}
        {!error && !loading && data.length > 0 && (
          <div data-recent-operations style={{ overflowX: 'auto' }}>
            <Table columns={columns} rows={data} />
          </div>
        )}
        {meta && meta.last_page > 1 && <Pagination meta={meta} onPageChange={setPage} />}
      </section>
      {cancelling && (
        <CancelOperationDialog
          operation={cancelling}
          onClose={() => setCancelling(null)}
          onCancelled={() => {
            setCancelling(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

/** Cancels the operation and its Work Order (one backend transaction). */
export function CancelOperationDialog({ operation, onClose, onCancelled }: { operation: { id: string; work_order: { wo_number: string } | null; operation_type: keyof typeof TYPE_PERMISSION }; onClose: () => void; onCancelled: () => void }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tire-operations/${operation.id}/cancel`, { reason: reason || undefined });
      onCancelled();
    } catch (e) {
      setError(extractApiError(e).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open title="Cancel Tire Operation" onClose={onClose}>
      <p style={{ fontSize: 13, marginTop: 0 }}>
        This cancels the {OPERATION_TYPE_LABEL[operation.operation_type].toLowerCase()} and its Work Order <strong>{operation.work_order?.wo_number ?? ''}</strong>. Requested replacement tires are released.
      </p>
      <FormField label="Reason">
        <textarea aria-label="Cancellation reason" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      {error && <ErrorState message={error} />}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
        <button type="button" className="btn-secondary" onClick={onClose}>
          Keep
        </button>
        <button type="button" className="btn-primary" style={{ background: '#b91c1c', borderColor: '#b91c1c' }} disabled={busy} onClick={confirm}>
          {busy ? 'Cancelling…' : 'Cancel Tire Operation'}
        </button>
      </div>
    </Modal>
  );
}
