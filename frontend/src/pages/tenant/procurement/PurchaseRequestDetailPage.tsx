import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { PurchaseRequestItem } from '../../../types';

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'purchase_request.submit', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'purchase_request.create' }],
  SUBMITTED: [{ action: 'review', label: 'Move to Review', permission: 'purchase_request.approve', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'purchase_request.create' }],
  UNDER_REVIEW: [
    { action: 'approve', label: 'Approve', permission: 'purchase_request.approve', primary: true },
    { action: 'reject', label: 'Reject', permission: 'purchase_request.approve' },
  ],
};

export function PurchaseRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [pr, setPr] = useState<PurchaseRequestItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function load() {
    apiClient
      .get(`/app/purchase-requests/${id}`)
      .then((res) => setPr(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

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

  const actions = (LIFECYCLE[pr.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
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
          <strong>Warehouse:</strong> {pr.warehouse?.name ?? pr.warehouse_id} &nbsp; <strong>Source:</strong> {pr.source_type} &nbsp;
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
          <div key={item.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {item.product?.name ?? item.product_id} — qty {item.requested_quantity}
            {item.estimated_unit_price && ` @ est. ${item.estimated_unit_price}`}
          </div>
        ))}
      </div>
    </div>
  );
}
