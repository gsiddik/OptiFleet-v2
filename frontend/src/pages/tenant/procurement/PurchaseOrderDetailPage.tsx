import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { PurchaseOrderItem } from '../../../types';

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'purchase_order.create', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'purchase_order.create' }],
  SUBMITTED: [
    { action: 'approve', label: 'Approve', permission: 'purchase_order.approve', primary: true },
    { action: 'reject', label: 'Reject', permission: 'purchase_order.approve' },
  ],
  APPROVED: [{ action: 'issue', label: 'Issue', permission: 'purchase_order.issue', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'purchase_order.create' }],
  RECEIVED: [{ action: 'close', label: 'Close', permission: 'purchase_order.approve', primary: true }],
};

export function PurchaseOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [po, setPo] = useState<PurchaseOrderItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [accepted, setAccepted] = useState<Record<string, string>>({});

  function load() {
    apiClient.get(`/app/purchase-orders/${id}`).then((res) => {
      setPo(res.data.data);
      const initial: Record<string, string> = {};
      (res.data.data.items ?? []).forEach((item: { id: string; quantity_ordered: string; quantity_received: string }) => {
        const remaining = Number(item.quantity_ordered) - Number(item.quantity_received);
        initial[item.id] = remaining > 0 ? String(remaining) : '';
      });
      setAccepted(initial);
    }).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function act(action: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/purchase-orders/${id}/${action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function postReceipt() {
    if (!po) return;
    setBusy(true);
    setError(null);
    try {
      const lines = (po.items ?? [])
        .filter((item) => accepted[item.id] && Number(accepted[item.id]) > 0)
        .map((item) => ({ purchase_order_item_id: item.id, quantity_accepted: accepted[item.id] }));
      await apiClient.post(`/app/purchase-orders/${id}/goods-receipts`, { lines });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !po) return <ErrorState message={error} />;
  if (!po) return <LoadingState />;

  const actions = (LIFECYCLE[po.status] ?? []).filter((a) => hasPermission(a.permission));
  const canReceive = ['ISSUED', 'PARTIALLY_RECEIVED'].includes(po.status) && hasPermission('goods_receipt.post');

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{po.po_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={po.status} />
          {actions.map((a) => (
            <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy} onClick={() => act(a.action)}>
              {a.label}
            </button>
          ))}
        </div>
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Vendor:</strong> {po.partner?.name ?? po.partner_id} &nbsp; <strong>Delivery:</strong>{' '}
          {po.delivery_warehouse?.name ?? po.delivery_warehouse_id}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Subtotal:</strong> {po.subtotal} &nbsp; <strong>Tax:</strong> {po.tax_total} &nbsp; <strong>Freight:</strong> {po.freight_cost} &nbsp;
          <strong>Total:</strong> {po.total}
        </p>
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {(po.items ?? []).map((item) => {
          const remaining = Number(item.quantity_ordered) - Number(item.quantity_received);
          return (
            <div key={item.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6' }}>
              <div style={{ fontSize: 13, marginBottom: 6 }}>
                {item.product?.name ?? item.product_id} — ordered {item.quantity_ordered} @ {item.unit_price} — received {item.quantity_received} — remaining {remaining}
              </div>
              {canReceive && remaining > 0 && (
                <input
                  type="number" step="0.0001" placeholder="Accept quantity" value={accepted[item.id] ?? ''}
                  onChange={(e) => setAccepted((a) => ({ ...a, [item.id]: e.target.value }))}
                  style={{ ...inputStyle, width: 140 }}
                />
              )}
            </div>
          );
        })}
        {canReceive && (
          <button className="btn-primary" disabled={busy} onClick={postReceipt} style={{ marginTop: 12 }}>
            Post Goods Receipt
          </button>
        )}
      </div>
    </div>
  );
}
