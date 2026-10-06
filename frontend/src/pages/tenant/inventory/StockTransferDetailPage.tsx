import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useWorkflowTransitions, workflowButtons } from '../../../hooks/useWorkflowTransitions';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { StockTransferItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatQty } from '../../../utils/quantity';
import { formatDateTime } from '../../../utils/date';
import { t } from '../../../i18n/i18n';

const TH: CSSProperties = { textAlign: 'left', padding: '6px 8px', borderBottom: '1px solid #e5e7eb', color: '#6b7280', fontWeight: 600 };
const TD: CSSProperties = { padding: '6px 8px', borderBottom: '1px solid #f3f4f6' };

const LIFECYCLE: Record<string, { action: string; label: string; labelKey?: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', labelKey: 'common.actions.submit', permission: 'stock_transfer.create', primary: true }, { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'stock_transfer.create' }],
  REQUESTED: [{ action: 'approve', label: 'Approve', labelKey: 'common.actions.approve', permission: 'stock_transfer.approve', primary: true }, { action: 'reject', label: 'Reject', labelKey: 'common.fields.reject', permission: 'stock_transfer.approve' }],
  APPROVED: [{ action: 'prepare', label: 'Mark Prepared', labelKey: 'inventory.actions.markPrepared', permission: 'stock_transfer.approve', primary: true }, { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'stock_transfer.create' }],
  PREPARED: [{ action: 'dispatch', label: 'Dispatch', labelKey: 'workflow.actionVerb.inTransit', permission: 'stock_transfer.dispatch', primary: true }, { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'stock_transfer.create' }],
  DISPATCHED: [{ action: 'in-transit', label: 'Mark In-Transit', labelKey: 'inventory.actions.markInTransit', permission: 'stock_transfer.dispatch', primary: true }],
  RECEIVED: [{ action: 'complete', label: 'Complete', labelKey: 'common.fields.complete', permission: 'stock_transfer.receive', primary: true }],
};

/**
 * The module action that moves a transfer into each status (the workflow decides when it is
 * offered). Dispatch and Receive record goods movements and enter their status directly, so they
 * stay as their own actions.
 */
const ACTIONS_BY_TARGET: Record<string, { action: string; label: string; labelKey?: string; permission: string; primary?: boolean }> = {
  REQUESTED: { action: 'submit', label: 'Submit', labelKey: 'common.actions.submit', permission: 'stock_transfer.create', primary: true },
  APPROVED: { action: 'approve', label: 'Approve', labelKey: 'common.actions.approve', permission: 'stock_transfer.approve', primary: true },
  REJECTED: { action: 'reject', label: 'Reject', labelKey: 'common.fields.reject', permission: 'stock_transfer.approve' },
  PREPARED: { action: 'prepare', label: 'Mark Prepared', labelKey: 'inventory.actions.markPrepared', permission: 'stock_transfer.approve', primary: true },
  IN_TRANSIT: { action: 'in-transit', label: 'Mark In-Transit', labelKey: 'inventory.actions.markInTransit', permission: 'stock_transfer.dispatch', primary: true },
  COMPLETED: { action: 'complete', label: 'Complete', labelKey: 'common.fields.complete', permission: 'stock_transfer.receive', primary: true },
  CANCELLED: { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'stock_transfer.create' },
};

export function StockTransferDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [transfer, setTransfer] = useState<StockTransferItem | null>(null);
  const available = useWorkflowTransitions('stock_transfer', id, transfer?.status);
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

  const builtIn = LIFECYCLE[transfer.status] ?? [];
  const actions = [
    ...workflowButtons(available, ACTIONS_BY_TARGET, builtIn),
    ...(available ? builtIn.filter((a) => a.action === 'dispatch') : []),
  ].filter((a) => hasPermission(a.permission));
  const canReceive = transfer.status === 'IN_TRANSIT' && hasPermission('stock_transfer.receive');
  const showReceipt = transfer.status === 'RECEIVED' || transfer.status === 'COMPLETED';

  return (
    <div>
      <BackButton fallbackTo="/app/stock-transfers" label={t('inventory.actions.backToTransfer')} />
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
          <strong>{t('common.fields.from')}:</strong> {transfer.from_warehouse?.name ?? transfer.from_warehouse_id} &nbsp; <strong>{t('common.fields.to')}:</strong>{' '}
          {transfer.to_warehouse?.name ?? transfer.to_warehouse_id}
        </p>
        {transfer.dispatched_at && (
          <p style={{ fontSize: 13, color: '#6b7280' }}>
            <strong>{t('inventory.fields.dispatched')}:</strong> {formatDateTime(transfer.dispatched_at)} {t('workOrder.fields.by')} {transfer.dispatched_by_name ?? '—'}
          </p>
        )}
        {transfer.received_at && (
          <p style={{ fontSize: 13, color: '#6b7280' }}>
            <strong>{t('inventory.fields.received')}:</strong> {formatDateTime(transfer.received_at)} {t('workOrder.fields.by')} {transfer.received_by_name ?? '—'}
          </p>
        )}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('common.sections.items')}</h3>
        {showReceipt ? (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr>
                <th style={TH}>{t('common.fields.product')}</th>
                <th style={{ ...TH, textAlign: 'right' }}>{t('inventory.fields.sent')}</th>
                <th style={{ ...TH, textAlign: 'right' }}>{t('inventory.fields.received')}</th>
                <th style={{ ...TH, textAlign: 'right' }}>{t('inventory.fields.damaged')}</th>
                <th style={{ ...TH, textAlign: 'right' }}>{t('inventory.fields.lost')}</th>
                <th style={TH}>{t('inventory.fields.discrepancyReason')}</th>
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
                {t('inventory.help.productSentQuantity', { product: item.product?.name ?? item.product_id, quantity: formatQty(item.quantity_sent) })}
              </div>
              {canReceive && (
                <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                  <ReceiptField label={t('inventory.fields.receivedQty')} hint={t('inventory.help.goodUnitsIntoStock')}>
                    <NumericInput step="0.0001" value={receipts[item.id]?.received ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], received: e.target.value } }))}
                      style={{ ...inputStyle, width: 110 }}
                    />
                  </ReceiptField>
                  <ReceiptField label={t('inventory.fields.damagedQty')}>
                    <NumericInput step="0.0001" value={receipts[item.id]?.damaged ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], damaged: e.target.value } }))}
                      style={{ ...inputStyle, width: 110 }}
                    />
                  </ReceiptField>
                  <ReceiptField label={t('inventory.fields.lostQty')}>
                    <NumericInput step="0.0001" value={receipts[item.id]?.lost ?? ''}
                      onChange={(e) => setReceipts((r) => ({ ...r, [item.id]: { ...r[item.id], lost: e.target.value } }))}
                      style={{ ...inputStyle, width: 110 }}
                    />
                  </ReceiptField>
                  <ReceiptField label={t('inventory.fields.discrepancyReason')} hint={t('inventory.help.requiredIfReceivedLessThanSent')}>
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
            {t('inventory.actions.postReceipt')}
          </button>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.statusHistory')}</h3>
        {(transfer.status_history ?? []).length === 0 ? (
          <div style={{ fontSize: 13, color: '#6b7280' }}>{t('tenantComponents.empty.noStatusChangesRecorded')}</div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr>
                <th style={TH}>{t('common.fields.status')}</th>
                <th style={TH}>{t('inventory.fields.dateTime')}</th>
                <th style={TH}>{t('inventory.fields.by')}</th>
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

function ReceiptField({ label, hint, children }: { label: string; labelKey?: string; hint?: string; children: ReactNode }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 3, fontSize: 12, fontWeight: 600, color: '#374151' }}>
      {label}
      {children}
      {hint && <span style={{ fontWeight: 400, color: '#6b7280', fontSize: 11 }}>{hint}</span>}
    </label>
  );
}
