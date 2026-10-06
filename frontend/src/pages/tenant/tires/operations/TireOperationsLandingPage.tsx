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
import { formatHours, formatKm } from './tireOperationFormat';
import { OPERATION_TYPES, OPERATION_TYPE_LABEL, type TireOperationListItem } from './tireOperationTypes';
import { statusLabel } from '../../../../i18n/statusRegistry';
import { t as tt } from '../../../../i18n/i18n';
import { Trans } from 'react-i18next';

const TYPE_PERMISSION = { REPLACEMENT: 'tire.install', ROTATION: 'tire.rotate', INSPECTION: 'tire.inspect' } as const;
const STATUSES = ['NEW', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'] as const;

/**
 * Tire Operations: the Recent Tire Operations (Replacement / Rotation / Inspection) with their Work
 * Order and status, newest first. New Tire Operations opens the add page; Edit / Cancel act on one
 * operation — the backend decides whether that is still possible (can_edit / can_cancel).
 * (The earlier tabbed Installation / Rotation / Inspection page, TireOperationsPage, is ORPHANED — source kept, not routed.)
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
    { key: 'date', header: tt('common.fields.date'), render: (r) => <span style={{ whiteSpace: 'nowrap' }}>{formatDate(r.operated_date)} {r.operated_time}</span> },
    { key: 'wo', header: tt('tire.fields.woNumber'), render: (r) => (r.work_order ? <Link to={`/app/work-orders/${r.work_order.id}`}>{r.work_order.wo_number}</Link> : '—') },
    { key: 'vehicle', header: tt('tire.fields.registration'), render: (r) => <Link to={`/app/vehicles/${r.vehicle.id}`}>{r.vehicle.registration_number}</Link> },
    { key: 'event', header: tt('tire.fields.events'), render: (r) => OPERATION_TYPE_LABEL[r.operation_type] },
    {
      key: 'position',
      header: tt('inventory.placeholders.position'),
      render: (r) => lines(r, (i) => (
        <>
          {r.operation_type === 'ROTATION' && <span style={{ color: '#6b7280', fontSize: 12 }}>{tt('masterData.productReferenceData.pair')} {i.pair_number} · </span>}
          <PositionLabel code={i.position_code} />
        </>
      )),
    },
    { key: 'usage', header: tt('tire.fields.usageKm'), render: (r) => lines(r, (i) => formatKm(i.usage_km)) },
    { key: 'hours', header: tt('tire.fields.usageTimeHoursMeter'), render: (r) => lines(r, (i) => formatHours(i.usage_hours)) },
    { key: 'tread', header: tt('tire.fields.lastTreadDepth'), render: (r) => lines(r, (i) => (i.last_tread_depth_mm != null ? tt('tire.help.dPullMmMm', { d_pull_mm: i.last_tread_depth_mm }) : '—')) },
    { key: 'status', header: tt('tire.fields.tireOperationsStatus'), render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'action',
      header: tt('common.fields.action'),
      render: (r) => (
        <div style={{ display: 'flex', gap: 10 }}>
          {r.can_edit && hasPermission('work_order.update') && hasPermission(TYPE_PERMISSION[r.operation_type]) && (
            <button type="button" className="btn-link" onClick={() => navigate(`/app/tire-operations/${r.id}/edit`)}>
              {tt('common.actions.edit')}
            </button>
          )}
          {r.can_cancel && hasPermission('work_order.cancel') && (
            <button type="button" className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setCancelling(r)}>
              {tt('common.actions.cancelRecord')}
            </button>
          )}
          {!r.can_edit && !r.can_cancel && <span style={{ color: '#9ca3af' }}>—</span>}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 6 }}>{tt('tire.titles.tireOperations')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 14 }}>{tt('tire.help.replacementRotationInspectionJobsEachOne')}</p>
      {saved && (
        <div role="status" data-save-success style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, padding: '8px 12px', marginBottom: 14 }}>
          {saved.edited ? tt('tire.fields.tireOperationUpdated') : tt('tire.fields.tireOperationSaved')}
          {saved.wo_number ? tt('tire.help.workOrderWoNumber', { wo_number: saved.wo_number }) : ''}.
        </div>
      )}
      <section className="card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 10 }}>
          <h3 style={{ margin: 0, fontSize: 15 }}>
            {tt('tire.sections.recentTireOperations')} {meta && <span style={{ color: '#6b7280', fontWeight: 400 }}>({meta.total})</span>}
          </h3>
          {canCreate && (
            <button type="button" className="btn-primary" onClick={() => navigate('/app/tire-operations/new')}>
              {tt('tire.actions.newTireOperations')}
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
          <select aria-label={tt('tire.fields.eventsFilter')} value={type} onChange={(e) => (setType(e.target.value), setPage(1))} style={inputStyle}>
            <option value="">{tt('tire.filters.allEvents')}</option>
            {OPERATION_TYPES.map((t) => (
              <option key={t} value={t}>
                {OPERATION_TYPE_LABEL[t]}
              </option>
            ))}
          </select>
          <select aria-label={tt('common.fields.statusFilter')} value={status} onChange={(e) => (setStatus(e.target.value), setPage(1))} style={inputStyle}>
            <option value="">{tt('common.filters.allStatuses')}</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {statusLabel(s)}
              </option>
            ))}
          </select>
        </Toolbar>
        {error && <ErrorState message={error} />}
        {!error && loading && <LoadingState />}
        {!error && !loading && data.length === 0 && <EmptyState label={tt('tire.empty.noTireOperationsYet')} />}
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
    <Modal open title={tt('tire.actions.cancelTireOperation')} onClose={onClose}>
      <p style={{ fontSize: 13, marginTop: 0 }}>
        <Trans i18nKey="tire.help.cancelOperationNotice" values={{ operation: OPERATION_TYPE_LABEL[operation.operation_type].toLowerCase(), workOrder: operation.work_order?.wo_number ?? '' }} components={{ strong: <strong /> }} />
      </p>
      <FormField label={tt('common.fields.reason')}>
        <textarea aria-label={tt('common.fields.cancellationReason')} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      {error && <ErrorState message={error} />}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
        <button type="button" className="btn-secondary" onClick={onClose}>
          {tt('tire.actions.keep')}
        </button>
        <button type="button" className="btn-primary" style={{ background: '#b91c1c', borderColor: '#b91c1c' }} disabled={busy} onClick={confirm}>
          {busy ? tt('tire.actions.cancelling') : tt('tire.actions.cancelTireOperation')}
        </button>
      </div>
    </Modal>
  );
}
