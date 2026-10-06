import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import { formatQty } from '../../../utils/quantity';
import type { PartRequestItem } from '../../../types';
import { lineName } from '../../../utils/stockCondition';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDateTime } from '../../../utils/date';
import { message } from '../../../i18n/messages';
import { t } from '../../../i18n/i18n';

const STATUSES = ['', 'REQUESTED', 'APPROVED', 'ISSUED', 'REJECTED', 'CANCELLED'];

/**
 * Part Requests: the single place where Work Order parts are decided and issued.
 * Work Order "Reserve" -> REQUESTED -> Approve / Reject / Cancel; APPROVED -> Issue (posts the
 * stock movement). Every action is authorized and state-checked again by the backend; the
 * buttons here only mirror what the current status and the user's permissions allow.
 */
export function PartRequestListPage() {
  const { hasPermission } = useAuth();
  const [searchParams] = useSearchParams();
  const workOrderId = searchParams.get('work_order_id') ?? '';
  const [status, setStatus] = useState(workOrderId ? '' : 'REQUESTED');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<{ request: PartRequestItem; action: 'approve' | 'cancel' } | null>(null);
  const [rejecting, setRejecting] = useState<PartRequestItem | null>(null);
  const [issuing, setIssuing] = useState<PartRequestItem | null>(null);
  const { data, meta, loading, error } = useApiList<PartRequestItem>(
    '/app/part-requests',
    { status: status || undefined, work_order_id: workOrderId || undefined, page },
    reloadKey,
  );

  async function run(id: string, action: 'approve' | 'cancel' | 'reject' | 'issue', body: Record<string, unknown> = {}) {
    setBusyId(id);
    setActionError(null);
    try {
      await apiClient.post(`/app/part-requests/${id}/${action}`, body);
      setReloadKey((k) => k + 1);
      return true;
    } catch (err) {
      setActionError(extractApiError(err).message);
      return false;
    } finally {
      setBusyId(null);
    }
  }

  const lines = (r: PartRequestItem) =>
    (r.items ?? []).map((i) => ({
      name: lineName(i.product?.name ?? i.description, i.stock_condition),
      qty: r.status === 'REQUESTED' || i.quantity_approved === null ? i.quantity_requested : i.quantity_approved,
    }));

  const columns: Column<PartRequestItem>[] = [
    {
      key: 'work_order',
      header: t('common.fields.workOrder'),
      render: (r) => <Link to={`/app/work-orders/${r.work_order_id}`}>{r.work_order?.wo_number ?? r.work_order_id}</Link>,
    },
    { key: 'vehicle', header: t('common.fields.vehicle'), render: (r) => r.work_order?.vehicle?.registration_number ?? '—' },
    { key: 'product', header: t('common.fields.product'), render: (r) => lines(r).map((l) => <div key={l.name}>{l.name}</div>) },
    { key: 'qty', header: t('common.fields.qty'), render: (r) => lines(r).map((l) => <div key={l.name}>{formatQty(l.qty)}</div>) },
    { key: 'requested_at', header: t('workOrder.fields.requested'), render: (r) => (r.requested_at ? formatDateTime(r.requested_at) : '—') },
    {
      key: 'status',
      header: t('common.fields.status'),
      render: (r) => (
        <div>
          <StatusBadge status={r.status} domain="stock" />
          {r.status === 'ISSUED' && r.warehouse && <div style={{ fontSize: 11, color: '#6b7280', marginTop: 2 }}>{t('workOrder.fields.fromName', { name: r.warehouse.name })}</div>}
          {r.status === 'REJECTED' && r.decision_note && <div style={{ fontSize: 11, color: '#6b7280', marginTop: 2 }}>{r.decision_note}</div>}
        </div>
      ),
    },
    {
      key: 'actions',
      header: '',
      render: (r) => (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {r.status === 'REQUESTED' && hasPermission('part_request.approve') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setConfirming({ request: r, action: 'approve' })}>
              {t('workOrder.confirm.approve')}
            </button>
          )}
          {r.status === 'REQUESTED' && hasPermission('part_request.reject') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setRejecting(r)}>
              {t('common.actions.reject')}
            </button>
          )}
          {r.status === 'REQUESTED' && hasPermission('part_request.cancel') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setConfirming({ request: r, action: 'cancel' })}>
              {t('common.actions.cancelRecord')}
            </button>
          )}
          {r.status === 'APPROVED' && hasPermission('part_request.issue') && (
            <button className="btn-primary" disabled={busyId === r.id} onClick={() => setIssuing(r)}>
              {t('workOrder.actions.issue')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{t('workOrder.titles.partRequests')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 14 }}>
        {t('workOrder.help.partsReservedWorkOrderIssuanceReturn')}
        {workOrderId && (
          <>
            {' '}{t('workOrder.help.showingOneWorkOrder')} <Link to="/app/part-requests">{t('workOrder.actions.showAll')}</Link>.
          </>
        )}
      </p>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button
            key={s}
            onClick={() => {
              setStatus(s);
              setPage(1);
            }}
            className={status === s ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '6px 12px', fontSize: 13 }}
          >
            {s ? statusLabel(s, 'stock') : t('common.actions.all')}
          </button>
        ))}
      </div>
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('workOrder.empty.noPartRequestsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}

      <ConfirmDialog
        open={confirming !== null}
        title={confirming?.action === 'approve' ? t('workOrder.confirm.approvePartRequest') : t('workOrder.confirm.cancelPartRequest')}
        message={
          confirming
            ? partRequestDecisionMessage(
                confirming.action,
                lines(confirming.request)
                  .map((l) => `${l.name} × ${formatQty(l.qty)}`)
                  .join(', '),
                confirming.request.work_order?.wo_number,
              )
            : ''
        }
        confirmLabel={confirming?.action === 'approve' ? t('workOrder.confirm.approve') : t('workOrder.confirm.cancelRequest')}
        onCancel={() => setConfirming(null)}
        onConfirm={async () => {
          if (!confirming) return;
          const { request, action } = confirming;
          setConfirming(null);
          await run(request.id, action);
        }}
      />
      {rejecting && (
        <RejectModal
          request={rejecting}
          onClose={() => setRejecting(null)}
          onSubmit={async (reason) => {
            if (await run(rejecting.id, 'reject', { reason })) setRejecting(null);
          }}
        />
      )}
      {issuing && (
        <IssueModal
          request={issuing}
          lines={lines(issuing)}
          busy={busyId === issuing.id}
          error={actionError}
          onClose={() => {
            setIssuing(null);
            setActionError(null);
          }}
          onSubmit={async (warehouseId) => {
            if (await run(issuing.id, 'issue', { warehouse_id: warehouseId })) setIssuing(null);
          }}
        />
      )}
    </div>
  );
}

function RejectModal({ request, onClose, onSubmit }: { request: PartRequestItem; onClose: () => void; onSubmit: (reason: string) => void }) {
  const [reason, setReason] = useState('');
  return (
    <Modal open title={t('workOrder.modals.rejectPartRequestValue', { value: request.work_order?.wo_number ?? '' })} onClose={onClose}>
      <FormField label={t('common.fields.reason')} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.back')}
        </button>
        <button className="btn-primary" disabled={!reason.trim()} onClick={() => onSubmit(reason)}>
          {t('common.actions.reject')}
        </button>
      </div>
    </Modal>
  );
}

function IssueModal({
  request,
  lines,
  busy,
  error,
  onClose,
  onSubmit,
}: {
  request: PartRequestItem;
  lines: { name: string; qty: string | null }[];
  busy: boolean;
  error: string | null;
  onClose: () => void;
  onSubmit: (warehouseId: string) => void;
}) {
  const [warehouses, setWarehouses] = useState<{ id: string; name: string }[] | null>(null);
  const [warehouseId, setWarehouseId] = useState('');

  useEffect(() => {
    apiClient
      .get('/app/warehouses', { params: { status: 'ACTIVE', per_page: 100 } })
      .then((res) => setWarehouses(res.data.data))
      .catch(() => setWarehouses([]));
  }, []);

  return (
    <Modal open title={t('workOrder.modals.issuePartsValue', { value: request.work_order?.wo_number ?? '' })} onClose={onClose}>
      {error && <ErrorState message={error} />}
      <div style={{ fontSize: 13, marginBottom: 12 }}>
        {lines.map((l) => (
          <div key={l.name}>
            {l.name} × <strong>{formatQty(l.qty)}</strong>
          </div>
        ))}
      </div>
      <FormField label={t('workOrder.fields.issueFromWarehouse')} required>
        <select aria-label={t('common.fields.warehouse')} value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
          <option value="">{warehouses === null ? t('common.actions.loading') : t('procurement.fields.selectWarehouse')}</option>
          {(warehouses ?? []).map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <p style={{ fontSize: 12, color: '#6b7280' }}>{t('workOrder.help.stockDeductedOnceEveryLineIf')}</p>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
        <button className="btn-secondary" onClick={onClose} disabled={busy}>
          {t('common.actions.back')}
        </button>
        <button className="btn-primary" disabled={busy || !warehouseId} onClick={() => onSubmit(warehouseId)}>
          {busy ? t('workOrder.actions.issuing') : t('workOrder.actions.confirmIssue')}
        </button>
      </div>
    </Modal>
  );
}

/** Approve / cancel confirmation as one whole sentence per case (no verb or fallback passed as a fragment). */
function partRequestDecisionMessage(action: 'approve' | 'cancel', lineSummary: string, woNumber: string | null | undefined): string {
  if (action === 'approve') {
    return woNumber
      ? message('workOrder.confirm.partRequestApprove', { lines: lineSummary, woNumber })
      : message('workOrder.confirm.partRequestApproveNoWorkOrder', { lines: lineSummary });
  }
  return woNumber
    ? message('workOrder.confirm.partRequestCancel', { lines: lineSummary, woNumber })
    : message('workOrder.confirm.partRequestCancelNoWorkOrder', { lines: lineSummary });
}
