import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { StockTransferItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatQty } from '../../../utils/quantity';
import { formatDateTime } from '../../../utils/date';

const TH: CSSProperties = { textAlign: 'left', padding: '6px 8px', borderBottom: '1px solid #e5e7eb', color: '#6b7280', fontWeight: 600 };
const TD: CSSProperties = { padding: '6px 8px', borderBottom: '1px solid #f3f4f6' };

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
          initial[item.id] = { received: item.quantity_sent.includes('.') ? item.quantity_sent.replace(/\.?0+$/, '') : item.quantity_sent, damaged: '0', lost: '0', reason: '' };
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
  const canReceive = transfer.status === 'IN_TRANSIT' && hasPermission('stock_transfer.receive');
  const showReceipt = transfer.status === 'RECEIVED' || transfer.status === 'COMPLETED';

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
            <strong>Dispatched:</strong> {formatDateTime(transfer.dispatched_at)} by {transfer.dispatched_by_name ?? '—'}
          </p>
        )}
        {transfer.received_at && (
          <p style={{ fontSize: 13, color: '#6b7280' }}>
            <strong>Received:</strong> {formatDateTime(transfer.received_at)} by {transfer.received_by_name ?? '—'}
          </p>
        )}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {showReceipt ? (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr>
                <th style={TH}>Product</th>
                <th style={{ ...TH, textAlign: 'right' }}>Sent</th>
                <th style={{ ...TH, textAlign: 'right' }}>Received</th>
                <th style={{ ...TH, textAlign: 'right' }}>Damaged</th>
                <th style={{ ...TH, textAlign: 'right' }}>Lost</th>
                <th style={TH}>Discrepancy Reason</th>
              </tr>
            </thead>
            <tbody>
              {(transfer.items ?? []).map((item) => (
                <tr key={item.id}>
                  <td style={TD}>{item.product?.name ?? item.product_id}</td>
                  <td style={{ ...TD, textAlign: 'right' }}>{formatQty(item.quantity_sent)}</td>
                  <td style={{ ...TD, textAlign: 'right' }}>{item.quantity_received !== null ? formatQty(item.quantity_received) : '—'}</td>
                  <td style={{ ...TD, textAlign: 'right' }}>{formatQty(item.quantity_damaged ?? '0')}</td>
                  <td style={{ ...TD, textAlign: 'right' }}>{formatQty(item.quantity_lost ?? '0')}</td>
                  <td style={TD}>{item.discrepancy_reason || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          (transfer.items ?? []).map((item) => (
            <div key={item.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6' }}>
              <div style={{ fontSize: 13, marginBottom: 6 }}>
                {item.product?.name ?? item.product_id} — sent {formatQty(item.quantity_sent)}
              </div>
              {canReceive && (
                <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                  <ReceiptField label="Received Qty" hint="Good units into stock">
                    <NumericInput step="0.0001" value={receipts[item.id]?.received ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], received: e.target.value } }))}
                      style={{ ...inputStyle, width: 110 }}
                    />
                  </ReceiptField>
                  <ReceiptField label="Damaged Qty">
                    <NumericInput step="0.0001" value={receipts[item.id]?.damaged ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], damaged: e.target.value } }))}
                      style={{ ...inputStyle, width: 110 }}
                    />
                  </ReceiptField>
                  <ReceiptField label="Lost Qty">
                    <NumericInput step="0.0001" value={receipts[item.id]?.lost ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], lost: e.target.value } }))}
                      style={{ ...inputStyle, width: 110 }}
                    />
                  </ReceiptField>
                  <ReceiptField label="Discrepancy Reason" hint="Required if received is less than sent">
                    <input value={receipts[item.id]?.reason ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], reason: e.target.value } }))}
                      style={{ ...inputStyle, width: 240 }}
                    />
                  </ReceiptField>
                </div>
              )}
            </div>
          ))
        )}
        {canReceive && (
          <button className="btn-primary" disabled={busy} onClick={receive} style={{ marginTop: 12 }}>
            Post Receipt
          </button>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Status History</h3>
        {(transfer.status_history ?? []).length === 0 ? (
          <div style={{ fontSize: 13, color: '#6b7280' }}>No status changes recorded.</div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr>
                <th style={TH}>Status</th>
                <th style={TH}>Date / Time</th>
                <th style={TH}>By</th>
              </tr>
            </thead>
            <tbody>
              {(transfer.status_history ?? []).map((entry, i) => (
                <tr key={`${entry.status}-${i}`}>
                  <td style={TD}><StatusBadge status={entry.status} /></td>
                  <td style={TD}>{formatDateTime(entry.at)}</td>
                  <td style={TD}>{entry.by ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function ReceiptField({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 3, fontSize: 12, fontWeight: 600, color: '#374151' }}>
      {label}
      {children}
      {hint && <span style={{ fontWeight: 400, color: '#6b7280', fontSize: 11 }}>{hint}</span>}
    </label>
  );
}
