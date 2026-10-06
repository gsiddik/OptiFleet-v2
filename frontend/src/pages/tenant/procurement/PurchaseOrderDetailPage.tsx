import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { allowedByWorkflow, useWorkflowTransitions } from '../../../hooks/useWorkflowTransitions';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { PurchaseOrderItem, PurchaseReturnItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { GoodsReceiptHistory } from './GoodsReceiptHistory';
import { RecordVendorInvoiceModal, type ReceiptLine } from './RecordVendorInvoiceModal';
import { ReturnToVendorModal } from './ReturnToVendorModal';
import { ReturnHistory } from './ReturnHistory';
import { DocumentViewer } from '../../../components/DocumentViewer';
import { DocumentVersionsButton } from '../../../components/DocumentVersions';
import { t } from '../../../i18n/i18n';

const LIFECYCLE: Record<string, { action: string; label: string; labelKey?: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', labelKey: 'common.actions.submit', permission: 'purchase_order.create', primary: true }, { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'purchase_order.create' }],
  SUBMITTED: [
    { action: 'approve', label: 'Approve', labelKey: 'common.actions.approve', permission: 'purchase_order.approve', primary: true },
    { action: 'reject', label: 'Reject', labelKey: 'common.actions.reject', permission: 'purchase_order.approve' },
  ],
  APPROVED: [{ action: 'issue', label: 'Issue', labelKey: 'procurement.actions.issue', permission: 'purchase_order.issue', primary: true }, { action: 'cancel', label: 'Cancel', labelKey: 'common.actions.cancelRecord', permission: 'purchase_order.create' }],
  RECEIVED: [{ action: 'close', label: 'Close', labelKey: 'common.fields.close', permission: 'purchase_order.approve', primary: true }],
};

/** The status each lifecycle action moves the order into (the workflow decides when it is offered). */
const ACTION_TARGET: Record<string, string> = { submit: 'SUBMITTED', approve: 'APPROVED', reject: 'REJECTED', issue: 'ISSUED', cancel: 'CANCELLED', close: 'CLOSED' };

export function PurchaseOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [po, setPo] = useState<PurchaseOrderItem | null>(null);
  const available = useWorkflowTransitions('purchase_order', id, po?.status);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [accepted, setAccepted] = useState<Record<string, string>>({});
  const [printing, setPrinting] = useState(false);
  const [receiptLines, setReceiptLines] = useState<ReceiptLine[] | null>(null);
  const [returning, setReturning] = useState(false);
  const [preview, setPreview] = useState<PurchaseReturnItem | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  function load() {
    apiClient.get(`/app/purchase-orders/${id}`).then((res) => {
      setPo(res.data.data);
      const initial: Record<string, string> = {};
      const summary = res.data.data.return_summary;
      (res.data.data.items ?? []).forEach((item: { id: string }) => {
        // Remaining Receivable Qty comes from the backend only (PurchaseOrderQuantityService).
        const remaining = Number(summary?.items?.[item.id]?.remaining_receivable_quantity ?? 0);
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

  /** Vendor decision / redelivery on the open Return Order. */
  async function returnAction(returnId: string, action: 'accept' | 'reject' | 'receive-redelivery') {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await apiClient.post(`/app/purchase-returns/${returnId}/${action}`);
      if (action === 'reject') setNotice(t('procurement.messages.refundRejectedVendorVendorRedeliverReturned'));
      if (action === 'accept') setNotice(t('procurement.messages.refundAcceptedVendorRefundedAmountRecorded'));
      if (action === 'receive-redelivery') setNotice(t('procurement.messages.redeliveryReceivedPostGoodsReceiptRedelivered'));
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
      setError(t('procurement.errors.enterQuantityReceivedLeastOneItem'));
      return;
    }
    setReceiptLines(lines);
  }

  if (error && !po) return <ErrorState message={error} />;
  if (!po) return <LoadingState />;

  const actions = (LIFECYCLE[po.status] ?? []).filter((a) => hasPermission(a.permission)).filter(allowedByWorkflow(available, ACTION_TARGET));
  const summary = po.return_summary;
  const openReturn = (po.returns ?? []).find((r) => r.id === summary?.open_return_id) ?? null;
  // Backend-calculated (no independent formula here); 0 when the summary is unavailable.
  const remainingOf = (item: { id: string }) => Number(summary?.items[item.id]?.remaining_receivable_quantity ?? 0);
  // Goods Receipt: hidden when nothing is left to receive; disabled while a redelivery is awaited.
  const anyRemaining = (po.items ?? []).some((item) => remainingOf(item) > 0);
  const receiptBlocked = Boolean(summary?.goods_receipt_blocked);
  // UI hint only — the backend rejects any quantity above the Remaining Receivable Qty.
  const anyOver = (po.items ?? []).some((item) => Number(accepted[item.id] || 0) > remainingOf(item));
  const canReceive = ['ISSUED', 'PARTIALLY_RECEIVED'].includes(po.status) && hasPermission('goods_receipt.post') && anyRemaining;
  const receipts = po.goods_receipts ?? [];
  // "Use the same invoice": the invoice of the most recent receipt that has one — offered only
  // while it is unpaid (the backend enforces the same rule).
  const latestInvoice = [...receipts].reverse().find((gr) => gr.vendor_invoice_reference)?.vendor_invoice_reference ?? null;
  const previousInvoice = latestInvoice && !latestInvoice.payment ? latestInvoice : null;
  const paidPreviousInvoice = latestInvoice?.payment ? latestInvoice : null;

  return (
    <div>
      <BackButton fallbackTo="/app/purchase-orders" label={t('procurement.actions.backToPurchaseOrder')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, gap: 10, flexWrap: 'wrap' }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{po.po_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusBadge status={po.status} domain="document" />
          {hasPermission('purchase_order.view') && (
            <button className="btn-secondary" disabled={printing} onClick={printPurchaseOrder}>
              {printing ? t('common.actions.loading') : t('common.actions.print')}
            </button>
          )}
          {hasPermission('purchase_order.view') && <DocumentVersionsButton printPath={`/app/purchase-orders/${po.id}/print`} />}
          {summary?.can_return && hasPermission('purchase_return.create') && (
            <button className="btn-secondary" disabled={busy} onClick={() => setReturning(true)}>
              {t('procurement.actions.returnToVendor')}
            </button>
          )}
          {openReturn?.status === 'REFUND_REQUESTED' && hasPermission('purchase_return.decide') && (
            <>
              <button className="btn-primary" disabled={busy} onClick={() => returnAction(openReturn.id, 'accept')}>
                {t('procurement.actions.acceptedByVendor')}
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => returnAction(openReturn.id, 'reject')}>
                {t('procurement.actions.rejectedByVendor')}
              </button>
            </>
          )}
          {openReturn && openReturn.status !== 'REFUND_REQUESTED' && hasPermission('purchase_return.receive_redelivery') && (
            <button
              className="btn-primary"
              disabled={busy || openReturn.status === 'REDELIVERY_REQUESTED'}
              title={openReturn.status === 'REDELIVERY_REQUESTED' ? t('procurement.tooltips.printReturnOrderFirst') : undefined}
              onClick={() => returnAction(openReturn.id, 'receive-redelivery')}
            >
              {t('procurement.actions.receiveRedelivery')}
            </button>
          )}
          {openReturn && (
            <button className="btn-secondary" disabled={busy} onClick={() => setPreview(openReturn)}>
              {t('procurement.actions.printReturnOrder')}
            </button>
          )}
          {openReturn && <DocumentVersionsButton printPath={`/app/purchase-returns/${openReturn.id}/print`} disabled={busy} />}
          {actions.map((a) => (
            <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy} onClick={() => act(a.action)}>
              {a.label}
            </button>
          ))}
        </div>
      </div>
      {error && <ErrorState message={error} />}
      {notice && (
        <div role="status" data-return-notice style={{ border: '1px solid #93c5fd', background: '#eff6ff', color: '#1e3a8a', borderRadius: 8, padding: '8px 12px', marginBottom: 12, fontSize: 13 }}>
          {notice}
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.vendor')}:</strong> {po.partner?.name ?? po.partner_id} &nbsp; <strong>{t('procurement.fields.delivery')}:</strong>{' '}
          {po.delivery_warehouse?.name ?? po.delivery_warehouse_id}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('procurement.fields.orderDate')}:</strong> {po.order_date ? po.order_date.slice(0, 10) : '—'} &nbsp; <strong>{t('procurement.fields.expectedReceiptDate')}:</strong>{' '}
          {po.expected_delivery_date ? po.expected_delivery_date.slice(0, 10) : '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('procurement.fields.subtotal')}:</strong> {formatMoney(po.subtotal)} &nbsp; <strong>{t('common.fields.tax')}:</strong> {formatMoney(po.tax_total)} &nbsp; <strong>{t('procurement.fields.freight')}:</strong> {formatMoney(po.freight_cost)} &nbsp;
          <strong>{t('common.fields.total')}:</strong> {formatMoney(po.total)}
        </p>
      </div>

      {po.status === 'PENDING_APPROVAL' && po.workflow_approval_request && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('procurement.sections.tieredApproval')}</h3>
          {(po.workflow_approval_request.steps ?? [])
            .sort((a, b) => a.step_number - b.step_number)
            .map((step) => (
              <div key={step.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', justifyContent: 'space-between' }}>
                <span>
                  {t('procurement.help.approvalStep', { step: step.step_number, approver: step.approver_identifier })}
                  {step.note && <span style={{ color: '#6b7280' }}> ({step.note})</span>}
                </span>
                <StatusBadge status={step.status} />
              </div>
            ))}
          {hasPermission('purchase_order.approve') && (
            <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
              <button className="btn-primary" disabled={busy} onClick={() => decideApproval('APPROVED')}>
                {t('procurement.actions.approveStep')}
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => decideApproval('REJECTED')}>
                {t('common.actions.reject')}
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('common.sections.items')}</h3>
        {(po.items ?? []).map((item) => {
          const remaining = remainingOf(item);
          const q = summary?.items[item.id];
          const over = Number(accepted[item.id] || 0) > remaining;
          return (
            <div key={item.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6' }} data-po-line={item.product?.name ?? item.id}>
              <div style={{ fontSize: 13, marginBottom: 6 }}>
                {t('procurement.help.orderedReceivedLine', { product: item.product?.name ?? item.product_id, ordered: formatQty(q?.ordered_quantity ?? item.quantity_ordered), price: formatMoney(item.unit_price), received: formatQty(q?.gross_received_quantity ?? item.quantity_received) })}
                {Number(q?.returned_quantity ?? 0) > 0 && <> {t('procurement.fields.returnedValue', { value: formatQty(q?.returned_quantity ?? 0) })}</>}
                {Number(q?.reopened_for_redelivery_quantity ?? 0) > 0 && <> {t('procurement.help.reOpenedRedeliveryValue', { value: formatQty(q?.reopened_for_redelivery_quantity ?? 0) })}</>}
                {Number(q?.refund_requested_quantity ?? 0) > 0 && <> {t('procurement.help.refundRequestedValue', { value: formatQty(q?.refund_requested_quantity ?? 0) })}</>}
                {Number(q?.accepted_refund_quantity ?? 0) > 0 && <> {t('procurement.fields.refundedValue', { value: formatQty(q?.accepted_refund_quantity ?? 0) })}</>}
                {' '}— <strong data-remaining={formatQty(remaining)}>{t('procurement.fields.remainingRemaining', { remaining: formatQty(remaining) })}</strong>
              </div>
              {canReceive && remaining > 0 && (
                <>
                  <NumericInput step="0.0001" min="0" max={String(remaining)} placeholder={t('procurement.placeholders.acceptQuantity')} value={accepted[item.id] ?? ''} disabled={receiptBlocked} aria-label={t('procurement.fields.acceptQuantityValue', { value: item.product?.name ?? item.id })}
                    onChange={(e) => setAccepted((a) => ({ ...a, [item.id]: e.target.value }))}
                    style={{ ...inputStyle, width: 140, ...(over ? { borderColor: '#b91c1c' } : {}) }}
                  />
                  {over && (
                    <span role="alert" style={{ fontSize: 12, color: '#b91c1c', marginLeft: 8 }}>
                      {t('procurement.help.maxRemainingRemainingReceivableQty', { remaining: formatQty(remaining) })}
                    </span>
                  )}
                </>
              )}
            </div>
          );
        })}
        {canReceive && (
          <>
            <button className="btn-primary" disabled={busy || receiptBlocked || anyOver} onClick={startReceipt} style={{ marginTop: 12 }}>
              {t('procurement.actions.postGoodsReceipt')}
            </button>
            {receiptBlocked && (
              <p style={{ fontSize: 12, color: '#b45309', margin: '6px 0 0' }}>
                {t('procurement.help.waitingForRedelivery', { number: openReturn?.return_number })}
              </p>
            )}
          </>
        )}
      </div>

      {receipts.length > 0 && <GoodsReceiptHistory receipts={receipts} receivedComplete={['RECEIVED', 'CLOSED'].includes(po.status)} canViewDocuments={hasPermission('vendor_invoice.view')} />}

      {(po.returns ?? []).length > 0 && <ReturnHistory returns={po.returns ?? []} onPrint={setPreview} />}

      {returning && (
        <ReturnToVendorModal
          po={po}
          onClose={() => setReturning(false)}
          onSaved={() => {
            setReturning(false);
            setNotice(null);
            load();
          }}
        />
      )}

      {preview && (
        <DocumentViewer
          path={`/app/purchase-returns/${preview.id}/print`}
          title={t('procurement.sections.returnOrderReturnNumber', { return_number: preview.return_number })}
          fileName={`${preview.return_number.replace(/\//g, '-')}.pdf`}
          mimeType="application/pdf"
          onClose={() => {
            setPreview(null);
            load();
          }}
        />
      )}

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
