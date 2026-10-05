import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useWorkflowTransitions, workflowButtons } from '../../../hooks/useWorkflowTransitions';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { PurchaseRequestItem, PurchaseRequestItemLine } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';

const LINE_STATUSES: PurchaseRequestItemLine['line_status'][] = ['PENDING', 'APPROVED', 'ON_HOLD', 'REJECTED'];

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'purchase_request.submit', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'purchase_request.create' }],
  SUBMITTED: [{ action: 'review', label: 'Move to Review', permission: 'purchase_request.approve', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'purchase_request.create' }],
  UNDER_REVIEW: [
    { action: 'approve', label: 'Approve', permission: 'purchase_request.approve', primary: true },
    { action: 'reject', label: 'Reject', permission: 'purchase_request.approve' },
  ],
};

/** The module action that moves a purchase request into each status (the workflow decides when it is offered). */
const ACTIONS_BY_TARGET: Record<string, { action: string; label: string; permission: string; primary?: boolean }> = {
  SUBMITTED: { action: 'submit', label: 'Submit', permission: 'purchase_request.submit', primary: true },
  UNDER_REVIEW: { action: 'review', label: 'Move to Review', permission: 'purchase_request.approve', primary: true },
  APPROVED: { action: 'approve', label: 'Approve', permission: 'purchase_request.approve', primary: true },
  REJECTED: { action: 'reject', label: 'Reject', permission: 'purchase_request.approve' },
  CANCELLED: { action: 'cancel', label: 'Cancel', permission: 'purchase_request.create' },
};

export function PurchaseRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [pr, setPr] = useState<PurchaseRequestItem | null>(null);
  const available = useWorkflowTransitions('purchase_request', id, pr?.status);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    apiClient
      .get(`/app/purchase-requests/${id}`)
      .then((res) => setPr(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(pr?.id, pr?.pr_number);

  async function act(action: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/purchase-requests/${id}/${action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  function createRfq() {
    navigate('/app/rfqs', { state: { purchaseRequestId: id } });
  }

  if (error && !pr) return <ErrorState message={error} />;
  if (!pr) return <LoadingState />;

  const actions = workflowButtons(available, ACTIONS_BY_TARGET, LIFECYCLE[pr.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <BackButton fallbackTo="/app/purchase-requests" label="← Back to Purchase Request" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{pr.pr_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={pr.status} />
          {actions.map((a) => (
            <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy} onClick={() => act(a.action)}>
              {a.label}
            </button>
          ))}
          {pr.status === 'APPROVED' && hasPermission('rfq.manage') && (
            <button className="btn-primary" onClick={createRfq}>
              Create RFQ
            </button>
          )}
        </div>
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Warehouse:</strong> {pr.warehouse?.name ?? pr.warehouse_id} &nbsp; <strong>Source:</strong> {pr.source_type}
          {pr.work_order && <span> ({pr.work_order.wo_number})</span>} &nbsp;
          <strong>Priority:</strong> {pr.priority}
        </p>
        {pr.notes && (
          <p style={{ fontSize: 13 }}>
            <strong>Notes:</strong> {pr.notes}
          </p>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {(pr.items ?? []).map((item) => (
          <ItemRow key={item.id} prId={pr.id} item={item} canApprove={hasPermission('purchase_request.approve')} onChanged={load} />
        ))}
      </div>
    </div>
  );
}

function ItemRow({
  prId,
  item,
  canApprove,
  onChanged,
}: {
  prId: string;
  item: PurchaseRequestItemLine;
  canApprove: boolean;
  onChanged: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [lineStatus, setLineStatus] = useState(item.line_status);
  const [lineReason, setLineReason] = useState(item.line_reason ?? '');
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    try {
      await apiClient.put(`/app/purchase-requests/${prId}/items/${item.id}/line-status`, {
        line_status: lineStatus,
        line_reason: lineReason || null,
      });
      setEditing(false);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <div style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <span>
          {item.product?.name ?? item.product_id} — qty {formatQty(item.requested_quantity)}
          {item.estimated_unit_price && ` @ est. ${formatMoney(item.estimated_unit_price)}`}
        </span>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={item.line_status} />
          {canApprove && !editing && (
            <button className="btn-link" onClick={() => setEditing(true)}>
              Change
            </button>
          )}
        </div>
      </div>
      {item.line_reason && !editing && <div style={{ color: '#6b7280', fontSize: 12, marginTop: 2 }}>Reason: {item.line_reason}</div>}
      {editing && (
        <div style={{ display: 'flex', gap: 8, marginTop: 6, alignItems: 'center', flexWrap: 'wrap' }}>
          <select value={lineStatus} onChange={(e) => setLineStatus(e.target.value as PurchaseRequestItemLine['line_status'])} style={{ ...inputStyle, width: 130 }}>
            {LINE_STATUSES.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
          <input
            placeholder="Reason (optional)"
            value={lineReason}
            onChange={(e) => setLineReason(e.target.value)}
            style={{ ...inputStyle, width: 220 }}
          />
          <button className="btn-primary" disabled={busy} onClick={save}>
            Save
          </button>
          <button className="btn-secondary" disabled={busy} onClick={() => setEditing(false)}>
            Cancel
          </button>
        </div>
      )}
    </div>
  );
}
