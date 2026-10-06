import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { inputStyle } from '../../../components/FormField';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { ExternalWorkOrderInvoiceItem, InspectionLogEntry, PartnerItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney, toMoneyInput } from '../../../utils/money';
import { formatDate, formatDateTime } from '../../../utils/date';
import { DocumentViewer } from '../../../components/DocumentViewer';
import { DocumentVersionsButton } from '../../../components/DocumentVersions';
import { statusLabel } from '../../../i18n/statusRegistry';

const STATUSES = ['', 'NEW_EXTERNAL_WO', 'DELIVERED', 'IN_PROGRESS', 'CANCELLED', 'BILLED', 'PAID'];


/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice": the tenant-facing "Workshop Invoice" list — Work Orders being
 * carried out by an External Workshop, and the primary surface for the
 * full Section 5 action matrix (Generate/View Work Authorization,
 * Deliver, Acknowledge, Complete, View Bill, Settlement, View
 * Settlement). Deliberately its own page/route, separate from the
 * pre-existing, unrelated R1 third-party Service Invoice (domain: WorkshopInvoice),
 * reached from its Work Order.
 * Cancel reuses the existing Work Order detail page's Cancel action
 * rather than duplicating that logic here.
 */
export function ExternalWorkOrderInvoiceListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const { data, loading, error, reload } = useApiList<ExternalWorkOrderInvoiceItem>('/app/external-work-order-invoices', { status: status || undefined });
  const [actionError, setActionError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  // Every attached/generated file opens by its real type (image preview / PDF viewer / download).
  const [viewing, setViewing] = useState<{ path: string; title: string } | null>(null);

  const [generatingFor, setGeneratingFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [selectedPartnerId, setSelectedPartnerId] = useState('');
  const [deliveringFor, setDeliveringFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [cancellingFor, setCancellingFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [cancelReason, setCancelReason] = useState('');
  // Cancelling here cancels the External Work Order too, so the backend requires both permissions.
  const canCancel = hasPermission('external_work_order_invoice.cancel') && hasPermission('work_order.cancel_external');
  const [acknowledgingFor, setAcknowledgingFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [ackFile, setAckFile] = useState<File | null>(null);
  const [completingFor, setCompletingFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [completedWoFile, setCompletedWoFile] = useState<File | null>(null);
  const [vendorInvoiceFile, setVendorInvoiceFile] = useState<File | null>(null);
  const [vendorInvoiceDate, setVendorInvoiceDate] = useState('');
  const [vendorInvoiceAmount, setVendorInvoiceAmount] = useState('');
  const [paymentTerm, setPaymentTerm] = useState('');
  const [settlingFor, setSettlingFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [paymentProofFile, setPaymentProofFile] = useState<File | null>(null);
  const [paymentDate, setPaymentDate] = useState('');
  const [paidAmount, setPaidAmount] = useState('');
  const [billFor, setBillFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [settlementFor, setSettlementFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [historyFor, setHistoryFor] = useState<ExternalWorkOrderInvoiceItem | null>(null);
  const [historyEntries, setHistoryEntries] = useState<InspectionLogEntry[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  useEffect(() => {
    if (!historyFor) return;
    setHistoryLoading(true);
    apiClient
      .get(`/app/external-work-order-invoices/${historyFor.id}/history`)
      .then((res) => setHistoryEntries(res.data.data))
      .catch((err) => setActionError(extractApiError(err).message))
      .finally(() => setHistoryLoading(false));
  }, [historyFor]);

  useEffect(() => {
    if (!generatingFor) return;
    apiClient
      .get('/app/partners', { params: { partner_type: 'EXTERNAL_WORKSHOP', status: 'ACTIVE', per_page: 100 } })
      .then((res) => setPartners(res.data.data))
      .catch(() => setPartners([]));
  }, [generatingFor]);

  function openGenerate(row: ExternalWorkOrderInvoiceItem) {
    setActionError(null);
    setSelectedPartnerId('');
    setGeneratingFor(row);
  }

  async function submitGenerate() {
    if (!generatingFor || !selectedPartnerId) return;
    setBusyId(generatingFor.id);
    setActionError(null);
    try {
      await apiClient.post(`/app/external-work-order-invoices/${generatingFor.id}/generate-authorization`, { partner_id: selectedPartnerId });
      setGeneratingFor(null);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  async function submitDeliver() {
    if (!deliveringFor) return;
    setBusyId(deliveringFor.id);
    setActionError(null);
    try {
      await apiClient.post(`/app/external-work-order-invoices/${deliveringFor.id}/deliver`, {});
      setDeliveringFor(null);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  async function submitCancel() {
    if (!cancellingFor || !cancelReason.trim()) return;
    setBusyId(cancellingFor.id);
    setActionError(null);
    try {
      await apiClient.post(`/app/external-work-order-invoices/${cancellingFor.id}/cancel`, { reason: cancelReason });
      setCancellingFor(null);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  async function submitAcknowledge() {
    if (!acknowledgingFor || !ackFile) return;
    setBusyId(acknowledgingFor.id);
    setActionError(null);
    try {
      const form = new FormData();
      form.append('file', ackFile);
      await apiClient.post(`/app/external-work-order-invoices/${acknowledgingFor.id}/acknowledge`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setAcknowledgingFor(null);
      setAckFile(null);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  function openComplete(row: ExternalWorkOrderInvoiceItem) {
    setActionError(null);
    setCompletedWoFile(null);
    setVendorInvoiceFile(null);
    setVendorInvoiceDate('');
    setVendorInvoiceAmount('');
    setPaymentTerm('');
    setCompletingFor(row);
  }

  async function submitComplete() {
    if (!completingFor || !completedWoFile || !vendorInvoiceFile || !vendorInvoiceDate || !vendorInvoiceAmount || !paymentTerm) return;
    setBusyId(completingFor.id);
    setActionError(null);
    try {
      const form = new FormData();
      form.append('completed_work_order_file', completedWoFile);
      form.append('vendor_invoice_file', vendorInvoiceFile);
      form.append('vendor_invoice_date', vendorInvoiceDate);
      form.append('vendor_invoice_amount', vendorInvoiceAmount);
      form.append('payment_term', paymentTerm);
      await apiClient.post(`/app/external-work-order-invoices/${completingFor.id}/complete`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setCompletingFor(null);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  function openSettle(row: ExternalWorkOrderInvoiceItem) {
    setActionError(null);
    setPaymentProofFile(null);
    setPaymentDate('');
    setPaidAmount(toMoneyInput(row.vendor_invoice_amount));
    setSettlingFor(row);
  }

  async function submitSettle() {
    if (!settlingFor || !paymentProofFile || !paymentDate || !paidAmount) return;
    setBusyId(settlingFor.id);
    setActionError(null);
    try {
      const form = new FormData();
      form.append('payment_proof_file', paymentProofFile);
      form.append('payment_date', paymentDate);
      form.append('paid_amount', paidAmount);
      await apiClient.post(`/app/external-work-order-invoices/${settlingFor.id}/settle`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setSettlingFor(null);
      reload();
    } catch (err) {
      setActionError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<ExternalWorkOrderInvoiceItem>[] = [
    {
      key: 'wo_number',
      header: 'WO Number',
      render: (r) => <Link to={`/app/work-orders/${r.work_order_id}`}>{r.work_order?.wo_number ?? r.work_order_id}</Link>,
    },
    { key: 'vehicle', header: 'Vehicle', render: (r) => r.work_order?.vehicle?.registration_number ?? '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'work_authorization', header: 'Work Authorization', render: (r) => statusLabel(r.work_authorization_status) },
    { key: 'workshop', header: 'Workshop', render: (r) => r.wal_workshop_name ?? r.work_order?.workshop?.name ?? '—' },
    {
      key: 'action',
      header: 'Action',
      render: (r) => (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {r.allowed_actions.includes('generate_authorization') && hasPermission('external_work_order_invoice.generate_authorization') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => openGenerate(r)}>
              Generate Work Authorization
            </button>
          )}
          {r.allowed_actions.includes('view_authorization') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setViewing({ path: `/app/external-work-order-invoices/${r.id}/authorization`, title: 'Work Authorization Letter' })}>
              View Work Authorization
            </button>
          )}
          {r.allowed_actions.includes('view_authorization') && <DocumentVersionsButton printPath={`/app/external-work-order-invoices/${r.id}/authorization`} disabled={busyId === r.id} />}
          {r.allowed_actions.includes('deliver') && hasPermission('external_work_order_invoice.deliver') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setDeliveringFor(r)}>
              Deliver
            </button>
          )}
          {r.allowed_actions.includes('acknowledge') && hasPermission('external_work_order_invoice.acknowledge') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setAcknowledgingFor(r)}>
              Acknowledge
            </button>
          )}
          {r.allowed_actions.includes('view_acknowledgement') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setViewing({ path: `/app/external-work-order-invoices/${r.id}/acknowledgement`, title: 'Acknowledged Work Authorization' })}>
              View Acknowledgement
            </button>
          )}
          {r.allowed_actions.includes('complete') && hasPermission('external_work_order_invoice.complete') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => openComplete(r)}>
              Complete
            </button>
          )}
          {r.allowed_actions.includes('view_bill') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setBillFor(r)}>
              View Bill
            </button>
          )}
          {r.allowed_actions.includes('settle') && hasPermission('external_work_order_invoice.settle') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => openSettle(r)}>
              Settlement
            </button>
          )}
          {r.allowed_actions.includes('view_settlement') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setSettlementFor(r)}>
              View Settlement
            </button>
          )}
          {r.allowed_actions.includes('cancel') && canCancel && (
            <button
              className="btn-secondary"
              disabled={busyId === r.id}
              onClick={() => {
                setActionError(null);
                setCancelReason('');
                setCancellingFor(r);
              }}
            >
              Cancel
            </button>
          )}
          {r.allowed_actions.includes('view_history') && (
            <button className="btn-secondary" disabled={busyId === r.id} onClick={() => setHistoryFor(r)}>
              View History
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>External Work Order Invoices</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: -8, marginBottom: 16 }}>
        Work Orders being carried out by an External Workshop.
      </p>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : 'All'}
          </button>
        ))}
      </div>
      {(error || actionError) && <ErrorState message={error ?? actionError ?? ''} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No External Work Order Invoices found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <Modal open={generatingFor !== null} title="Generate Work Authorization" onClose={() => setGeneratingFor(null)}>
        <p style={{ fontSize: 13 }}>Select the External Workshop that will perform this maintenance.</p>
        <select value={selectedPartnerId} onChange={(e) => setSelectedPartnerId(e.target.value)} style={{ ...inputStyle, width: '100%' }}>
          <option value="">Select workshop…</option>
          {partners.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setGeneratingFor(null)} disabled={busyId !== null}>
            Cancel
          </button>
          <button className="btn-primary" onClick={submitGenerate} disabled={busyId !== null || !selectedPartnerId}>
            Generate
          </button>
        </div>
      </Modal>

      <Modal open={cancellingFor !== null} title="Cancel External Work Order" onClose={() => setCancellingFor(null)}>
        <p style={{ fontSize: 13 }}>This cancels the External Work Order and its External Workshop Invoice. It cannot be undone.</p>
        <textarea
          aria-label="Cancellation reason"
          placeholder="Reason (required)"
          value={cancelReason}
          maxLength={2000}
          onChange={(e) => setCancelReason(e.target.value)}
          style={{ ...inputStyle, minHeight: 70 }}
        />
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setCancellingFor(null)} disabled={busyId !== null}>
            Back
          </button>
          <button className="btn-primary" onClick={submitCancel} disabled={busyId !== null || !cancelReason.trim()}>
            Confirm Cancel
          </button>
        </div>
      </Modal>

      <Modal open={deliveringFor !== null} title="Deliver" onClose={() => setDeliveringFor(null)}>
        <p style={{ fontSize: 13 }}>
          Confirm that the Work Order and Work Authorization Letter have been printed and handed over to the External Workshop.
        </p>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setDeliveringFor(null)} disabled={busyId !== null}>
            Cancel
          </button>
          <button className="btn-primary" onClick={submitDeliver} disabled={busyId !== null}>
            Confirm Deliver
          </button>
        </div>
      </Modal>

      <Modal
        open={acknowledgingFor !== null}
        title="Acknowledge Work Authorization"
        onClose={() => {
          setAcknowledgingFor(null);
          setAckFile(null);
        }}
      >
        <p style={{ fontSize: 13 }}>Upload the signed Work Authorization Letter received back from the workshop.</p>
        <input type="file" accept=".doc,.docx,.pdf" onChange={(e) => setAckFile(e.target.files?.[0] ?? null)} />
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button
            className="btn-secondary"
            onClick={() => {
              setAcknowledgingFor(null);
              setAckFile(null);
            }}
            disabled={busyId !== null}
          >
            Cancel
          </button>
          <button className="btn-primary" onClick={submitAcknowledge} disabled={busyId !== null || !ackFile}>
            Upload
          </button>
        </div>
      </Modal>

      <Modal open={completingFor !== null} title="Complete" onClose={() => setCompletingFor(null)} width={520}>
        <p style={{ fontSize: 13 }}>Confirm that the External Workshop has finished the maintenance work.</p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <label style={{ fontSize: 12 }}>
            Completed Work Order (JPG, PNG, or PDF)
            <input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => setCompletedWoFile(e.target.files?.[0] ?? null)} />
          </label>
          <label style={{ fontSize: 12 }}>
            Vendor Invoice (JPG, PNG, or PDF)
            <input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => setVendorInvoiceFile(e.target.files?.[0] ?? null)} />
          </label>
          <label style={{ fontSize: 12 }}>
            Invoice Date
            <input type="date" value={vendorInvoiceDate} onChange={(e) => setVendorInvoiceDate(e.target.value)} style={{ ...inputStyle, width: '100%' }} />
          </label>
          <label style={{ fontSize: 12 }}>
            Invoice Amount
            <NumericInput step="0.01" min="0.01" value={vendorInvoiceAmount} onChange={(e) => setVendorInvoiceAmount(e.target.value)} style={{ ...inputStyle, width: '100%' }} />
          </label>
          <label style={{ fontSize: 12 }}>
            Payment Term
            <input placeholder="e.g. NET 30" value={paymentTerm} onChange={(e) => setPaymentTerm(e.target.value)} style={{ ...inputStyle, width: '100%' }} />
          </label>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setCompletingFor(null)} disabled={busyId !== null}>
            Cancel
          </button>
          <button
            className="btn-primary"
            onClick={submitComplete}
            disabled={busyId !== null || !completedWoFile || !vendorInvoiceFile || !vendorInvoiceDate || !vendorInvoiceAmount || !paymentTerm}
          >
            Confirm Complete
          </button>
        </div>
      </Modal>

      <Modal open={billFor !== null} title="Bill" onClose={() => setBillFor(null)}>
        {billFor && (
          <div style={{ fontSize: 13, display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div>Invoice Date: {formatDate(billFor.vendor_invoice_date)}</div>
            <div>Invoice Amount: {formatMoney(billFor.vendor_invoice_amount)}</div>
            <div>Payment Term: {billFor.payment_term ?? '—'}</div>
            <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
              <button className="btn-secondary" onClick={() => setViewing({ path: `/app/external-work-order-invoices/${billFor.id}/completed-work-order`, title: 'Completed Work Order' })}>
                Open Completed Work Order
              </button>
              <button className="btn-secondary" onClick={() => setViewing({ path: `/app/external-work-order-invoices/${billFor.id}/vendor-invoice`, title: 'Vendor Invoice' })}>
                Open Vendor Invoice
              </button>
            </div>
          </div>
        )}
      </Modal>

      <Modal open={settlingFor !== null} title="Settlement" onClose={() => setSettlingFor(null)}>
        <p style={{ fontSize: 13 }}>
          Paid amount must exactly match the vendor invoice amount ({formatMoney(settlingFor?.vendor_invoice_amount)}) — partial settlement is not supported.
        </p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <label style={{ fontSize: 12 }}>
            Payment Date
            <input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} style={{ ...inputStyle, width: '100%' }} />
          </label>
          <label style={{ fontSize: 12 }}>
            Paid Amount
            <NumericInput step="0.01" min="0.01" value={paidAmount} onChange={(e) => setPaidAmount(e.target.value)} style={{ ...inputStyle, width: '100%' }} />
          </label>
          <label style={{ fontSize: 12 }}>
            Payment Proof (JPG, PNG, or PDF)
            <input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => setPaymentProofFile(e.target.files?.[0] ?? null)} />
          </label>
        </div>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setSettlingFor(null)} disabled={busyId !== null}>
            Cancel
          </button>
          <button className="btn-primary" onClick={submitSettle} disabled={busyId !== null || !paymentProofFile || !paymentDate || !paidAmount}>
            Confirm Settlement
          </button>
        </div>
      </Modal>

      <Modal open={settlementFor !== null} title="Settlement Details" onClose={() => setSettlementFor(null)}>
        {settlementFor && (
          <div style={{ fontSize: 13, display: 'flex', flexDirection: 'column', gap: 6 }}>
            <div>Payment Date: {formatDate(settlementFor.payment_date)}</div>
            <div>Paid Amount: {formatMoney(settlementFor.paid_amount)}</div>
            <div style={{ marginTop: 8 }}>
              <button className="btn-secondary" onClick={() => setViewing({ path: `/app/external-work-order-invoices/${settlementFor.id}/payment-proof`, title: 'Payment Proof' })}>
                Open Payment Proof
              </button>
            </div>
          </div>
        )}
      </Modal>

      <Modal open={historyFor !== null} title="History" onClose={() => setHistoryFor(null)} width={560}>
        {historyLoading && <LoadingState />}
        {!historyLoading && historyEntries.length === 0 && <EmptyState label="No history recorded yet." />}
        {!historyLoading && historyEntries.length > 0 && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {historyEntries.map((entry) => (
              <div key={entry.id} style={{ fontSize: 13, padding: '6px 0', borderBottom: '1px solid #f3f4f6' }}>
                <div style={{ color: '#6b7280', fontSize: 12 }}>{formatDateTime(entry.created_at)}</div>
                <div>
                  <strong>{entry.actor_name ?? 'System'}</strong> — {entry.action}
                  {entry.new_values?.status ? ` (status: ${statusLabel(String(entry.new_values.status))})` : ''}
                </div>
              </div>
            ))}
          </div>
        )}
      </Modal>
      {viewing && <DocumentViewer path={viewing.path} title={viewing.title} onClose={() => setViewing(null)} />}
    </div>
  );
}
