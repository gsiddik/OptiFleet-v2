import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { StockTransferItem } from '../../../types';

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'stock_transfer.create', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'stock_transfer.create' }],
  REQUESTED: [{ action: 'approve', label: 'Approve', permission: 'stock_transfer.approve', primary: true }, { action: 'reject', label: 'Reject', permission: 'stock_transfer.approve' }],
  APPROVED: [{ action: 'prepare', label: 'Mark Prepared', permission: 'stock_transfer.approve', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'stock_transfer.create' }],
  PREPARED: [{ action: 'dispatch', label: 'Dispatch', permission: 'stock_transfer.dispatch', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'stock_transfer.create' }],
  DISPATCHED: [{ action: 'in-transit', label: 'Mark In-Transit', permission: 'stock_transfer.dispatch', primary: true }],
  RECEIVED: [{ action: 'complete', label: 'Complete', permission: 'stock_transfer.receive', primary: true }],
};

export function StockTransferDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [transfer, setTransfer] = useState<StockTransferItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [receipts, setReceipts] = useState<Record<string, { received: string; damaged: string; lost: string; reason: string }>>({});

  function load() {
    apiClient
      .get(`/app/stock-transfers/${id}`)
      .then((res) => {
        setTransfer(res.data.data);
        const initial: typeof receipts = {};
        (res.data.data.items ?? []).forEach((item: { id: string; quantity_sent: string }) => {
          initial[item.id] = { received: item.quantity_sent, damaged: '0', lost: '0', reason: '' };
        });
        setReceipts(initial);
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(transfer?.id, transfer?.transfer_number);

  async function act(action: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/stock-transfers/${id}/${action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function receive() {
    if (!transfer) return;
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/stock-transfers/${id}/receive`, {
        receipts: Object.entries(receipts).map(([itemId, r]) => ({
          item_id: itemId, quantity_received: r.received, quantity_damaged: r.damaged, quantity_lost: r.lost,
          discrepancy_reason: r.reason || undefined,
        })),
      });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !transfer) return <ErrorState message={error} />;
  if (!transfer) return <LoadingState />;

  const actions = (LIFECYCLE[transfer.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <BackButton fallbackTo="/app/stock-transfers" label="← Back to Transfer" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{transfer.transfer_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={transfer.status} />
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
          <strong>From:</strong> {transfer.from_warehouse?.name ?? transfer.from_warehouse_id} &nbsp; <strong>To:</strong>{' '}
          {transfer.to_warehouse?.name ?? transfer.to_warehouse_id}
        </p>
        {transfer.dispatched_at && (
          <p style={{ fontSize: 13, color: '#6b7280' }}>
            <strong>Dispatched:</strong> {new Date(transfer.dispatched_at).toLocaleString()} by {transfer.dispatched_by ?? '—'}
          </p>
        )}
        {transfer.received_at && (
          <p style={{ fontSize: 13, color: '#6b7280' }}>
            <strong>Received:</strong> {new Date(transfer.received_at).toLocaleString()} by {transfer.received_by ?? '—'}
          </p>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {(transfer.items ?? []).map((item) => (
          <div key={item.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6' }}>
            <div style={{ fontSize: 13, marginBottom: 6 }}>
              {item.product?.name ?? item.product_id} — sent {item.quantity_sent}
              {item.quantity_received !== null && ` / received ${item.quantity_received}`}
            </div>
            {transfer.status === 'IN_TRANSIT' && hasPermission('stock_transfer.receive') && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <input
                  type="number" step="0.0001" placeholder="Received" value={receipts[item.id]?.received ?? ''}
                  onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], received: e.target.value } }))}
                  style={{ ...inputStyle, width: 100 }}
                />
                <input
                  type="number" step="0.0001" placeholder="Damaged" value={receipts[item.id]?.damaged ?? ''}
                  onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], damaged: e.target.value } }))}
                  style={{ ...inputStyle, width: 100 }}
                />
                <input
                  type="number" step="0.0001" placeholder="Lost" value={receipts[item.id]?.lost ?? ''}
                  onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], lost: e.target.value } }))}
                  style={{ ...inputStyle, width: 100 }}
                />
                <input
                  placeholder="Discrepancy reason (if short)" value={receipts[item.id]?.reason ?? ''}
                  onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], reason: e.target.value } }))}
                  style={{ ...inputStyle, width: 220 }}
                />
              </div>
            )}
          </div>
        ))}
        {transfer.status === 'IN_TRANSIT' && hasPermission('stock_transfer.receive') && (
          <button className="btn-primary" disabled={busy} onClick={receive} style={{ marginTop: 12 }}>
            Post Receipt
          </button>
        )}
      </div>
    </div>
  );
}
