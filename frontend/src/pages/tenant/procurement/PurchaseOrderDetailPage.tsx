import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { PurchaseOrderItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { GoodsReceiptHistory } from './GoodsReceiptHistory';
import { RecordVendorInvoiceModal, type ReceiptLine } from './RecordVendorInvoiceModal';

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
  const [printing, setPrinting] = useState(false);
  const [receiptLines, setReceiptLines] = useState<ReceiptLine[] | null>(null);

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

  useBreadcrumbLabel(po?.id, po?.po_number);

  /** Same pattern as G-04 (Work Order): a print PDF endpoint existed with no frontend caller anywhere. */
  async function printPurchaseOrder() {
    setPrinting(true);
    setError(null);
    try {
      const res = await apiClient.get(`/app/purchase-orders/${id}/print`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setPrinting(false);
    }
  }

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

  /** G-06: decides the next pending step of a tiered approval, when a tenant has configured one. */
  async function decideApproval(decision: 'APPROVED' | 'REJECTED') {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/purchase-orders/${id}/decide-approval`, { decision });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  /** Post Goods Receipt: the receipt is posted only after its vendor invoice is recorded. */
  function startReceipt() {
    if (!po) return;
    setError(null);
    const lines = (po.items ?? [])
      .filter((item) => accepted[item.id] && Number(accepted[item.id]) > 0)
      .map((item) => ({ purchase_order_item_id: item.id, quantity_accepted: accepted[item.id] }));
    if (lines.length === 0) {
      setError('Enter the quantity received for at least one item.');
      return;
    }
    setReceiptLines(lines);
  }

  if (error && !po) return <ErrorState message={error} />;
  if (!po) return <LoadingState />;

  const actions = (LIFECYCLE[po.status] ?? []).filter((a) => hasPermission(a.permission));
  const canReceive = ['ISSUED', 'PARTIALLY_RECEIVED'].includes(po.status) && hasPermission('goods_receipt.post');
  const receipts = po.goods_receipts ?? [];
  // "Use the same invoice": the invoice of the most recent receipt that has one — offered only
  // while it is unpaid (the backend enforces the same rule).
  const latestInvoice = [...receipts].reverse().find((gr) => gr.vendor_invoice_reference)?.vendor_invoice_reference ?? null;
  const previousInvoice = latestInvoice && !latestInvoice.payment ? latestInvoice : null;
  const paidPreviousInvoice = latestInvoice?.payment ? latestInvoice : null;

  return (
    <div>
      <BackButton fallbackTo="/app/purchase-orders" label="← Back to Purchase Order" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{po.po_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={po.status} />
          {hasPermission('purchase_order.view') && (
            <button className="btn-secondary" disabled={printing} onClick={printPurchaseOrder}>
              {printing ? 'Loading…' : 'Print'}
            </button>
          )}
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
          <strong>Order Date:</strong> {po.order_date ? po.order_date.slice(0, 10) : '—'} &nbsp; <strong>Expected Receipt Date:</strong>{' '}
          {po.expected_delivery_date ? po.expected_delivery_date.slice(0, 10) : '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Subtotal:</strong> {formatMoney(po.subtotal)} &nbsp; <strong>Tax:</strong> {formatMoney(po.tax_total)} &nbsp; <strong>Freight:</strong> {formatMoney(po.freight_cost)} &nbsp;
          <strong>Total:</strong> {formatMoney(po.total)}
        </p>
      </div>

      {po.status === 'PENDING_APPROVAL' && po.workflow_approval_request && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Tiered Approval</h3>
          {(po.workflow_approval_request.steps ?? [])
            .sort((a, b) => a.step_number - b.step_number)
            .map((step) => (
              <div key={step.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', justifyContent: 'space-between' }}>
                <span>
                  Step {step.step_number} — {step.approver_identifier}
                  {step.note && <span style={{ color: '#6b7280' }}> ({step.note})</span>}
                </span>
                <StatusBadge status={step.status} />
              </div>
            ))}
          {hasPermission('purchase_order.approve') && (
            <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
              <button className="btn-primary" disabled={busy} onClick={() => decideApproval('APPROVED')}>
                Approve Step
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => decideApproval('REJECTED')}>
                Reject
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {(po.items ?? []).map((item) => {
          const remaining = Number(item.quantity_ordered) - Number(item.quantity_received);
          return (
            <div key={item.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6' }}>
              <div style={{ fontSize: 13, marginBottom: 6 }}>
                {item.product?.name ?? item.product_id} — ordered {formatQty(item.quantity_ordered)} @ {formatMoney(item.unit_price)} — received {formatQty(item.quantity_received)} — remaining {remaining}
              </div>
              {canReceive && remaining > 0 && (
                <NumericInput step="0.0001" placeholder="Accept quantity" value={accepted[item.id] ?? ''}
                  onChange={(e) => setAccepted((a) => ({ ...a, [item.id]: e.target.value }))}
                  style={{ ...inputStyle, width: 140 }}
                />
              )}
            </div>
          );
        })}
        {canReceive && (
          <button className="btn-primary" disabled={busy} onClick={startReceipt} style={{ marginTop: 12 }}>
            Post Goods Receipt
          </button>
        )}
      </div>

      {receipts.length > 0 && <GoodsReceiptHistory receipts={receipts} receivedComplete={['RECEIVED', 'CLOSED'].includes(po.status)} canViewDocuments={hasPermission('vendor_invoice.view')} />}

      {receiptLines && (
        <RecordVendorInvoiceModal
          purchaseOrderId={po.id}
          vendorName={po.partner?.name ?? '—'}
          lines={receiptLines}
          previousInvoice={previousInvoice}
          paidPreviousInvoice={paidPreviousInvoice}
          canViewDocuments={hasPermission('vendor_invoice.view')}
          onClose={() => setReceiptLines(null)}
          onPosted={() => {
            setReceiptLines(null);
            load();
          }}
        />
      )}
    </div>
  );
}
