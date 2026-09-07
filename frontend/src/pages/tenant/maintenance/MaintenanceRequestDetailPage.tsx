import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { MaintenanceRequestItem } from '../../../types';

const ACTIONS: Record<string, { action: string; label: string; permission: string; needsNote?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'maintenance_request.create' }, { action: 'cancel', label: 'Cancel', permission: 'maintenance_request.create' }],
  SUBMITTED: [{ action: 'review', label: 'Move to Review', permission: 'maintenance_request.review' }],
  UNDER_REVIEW: [
    { action: 'approve', label: 'Approve', permission: 'maintenance_request.approve' },
    { action: 'reject', label: 'Reject', permission: 'maintenance_request.reject', needsNote: true },
    { action: 'request-info', label: 'Request Info', permission: 'maintenance_request.review', needsNote: true },
  ],
  NEED_INFORMATION: [{ action: 'submit', label: 'Resubmit', permission: 'maintenance_request.create' }],
};

export function MaintenanceRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [request, setRequest] = useState<MaintenanceRequestItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');

  function load() {
    apiClient
      .get(`/app/maintenance-requests/${id}`)
      .then((res) => setRequest(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function act(action: string, needsNote?: boolean) {
    if (needsNote && !note.trim()) {
      setError('A note is required for this action.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/maintenance-requests/${id}/${action}`, needsNote ? { note } : {});
      setNote('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function convertToWorkOrder() {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post(`/app/maintenance-requests/${id}/work-order`);
      navigate(`/app/work-orders/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !request) return <ErrorState message={error} />;
  if (!request) return <LoadingState />;

  const actions = (ACTIONS[request.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {request.request_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({request.vehicle?.registration_number})</span>
        </h1>
        <StatusBadge status={request.status} />
      </div>

      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
        <p style={{ fontSize: 13 }}>
          <strong>Source:</strong> {request.source_type} &nbsp; <strong>Priority:</strong> {request.priority}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Complaint:</strong> {request.complaint}
        </p>
        {request.review_note && (
          <p style={{ fontSize: 13 }}>
            <strong>Review Note:</strong> {request.review_note}
          </p>
        )}
      </div>

      {actions.length > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Workflow Actions</h3>
          {actions.some((a) => a.needsNote) && (
            <textarea
              placeholder="Note (required for reject / request info)"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              style={{ width: '100%', minHeight: 60, marginBottom: 10, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
            />
          )}
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {actions.map((a) => (
              <button key={a.action} className="btn-secondary" disabled={busy} onClick={() => act(a.action, a.needsNote)}>
                {a.label}
              </button>
            ))}
          </div>
        </div>
      )}

      {request.status === 'APPROVED' && hasPermission('maintenance_request.convert_work_order') && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Work Order</h3>
          <p style={{ fontSize: 13, color: '#6b7280' }}>This request is approved and ready to be converted into a Work Order.</p>
          <button className="btn-primary" disabled={busy} onClick={convertToWorkOrder}>
            Create Work Order
          </button>
        </div>
      )}

      {request.status === 'WORK_ORDER_CREATED' && request.work_order_id && (
        <div className="card">
          <p style={{ fontSize: 13 }}>
            Work Order created:{' '}
            <button className="btn-link" onClick={() => navigate(`/app/work-orders/${request.work_order_id}`)}>
              View Work Order
            </button>
          </p>
        </div>
      )}
    </div>
  );
}
