import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { DocumentVersionsButton } from '../../../components/DocumentVersions';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { WorkshopInvoiceItem, WorkshopInvoiceReconciliation } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney, toMoneyInput } from '../../../utils/money';
import { t } from '../../../i18n/i18n';
import { formatDate } from '../../../utils/date';

/**
 * R1: full detail view for one recorded Workshop Invoice — reconciliation,
 * payment evidence, and the correction/cancellation maker-checker
 * workflow. Language throughout says "recorded"/"received", never
 * "issued" — OptiFleet only tracks a document the Workshop Partner
 * already issued externally.
 */
export function WorkshopInvoiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [invoice, setInvoice] = useState<WorkshopInvoiceItem | null>(null);
  const [reconciliation, setReconciliation] = useState<WorkshopInvoiceReconciliation | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [printing, setPrinting] = useState(false);
  const [showPayment, setShowPayment] = useState(false);
  const [showCorrection, setShowCorrection] = useState(false);
  const [showCancellation, setShowCancellation] = useState(false);
  const [reconciliationNote, setReconciliationNote] = useState('');
  const [savingNote, setSavingNote] = useState(false);

  function load() {
    apiClient
      .get(`/app/workshop-invoices/${id}`)
      .then((res) => {
        setInvoice(res.data.data);
        setReconciliationNote(res.data.data.reconciliation_note ?? '');
      })
      .catch((err) => setError(extractApiError(err).message));
    apiClient
      .get(`/app/workshop-invoices/${id}/reconciliation`)
      .then((res) => setReconciliation(res.data.data))
      .catch(() => setReconciliation(null));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(invoice?.id, invoice?.external_invoice_number);

  async function printInvoice() {
    setPrinting(true);
    try {
      const res = await apiClient.get(`/app/workshop-invoices/${id}/print`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setPrinting(false);
    }
  }

  async function saveReconciliationNote() {
    setSavingNote(true);
    try {
      await apiClient.put(`/app/workshop-invoices/${id}/reconciliation-note`, { reconciliation_note: reconciliationNote || null });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSavingNote(false);
    }
  }

  async function decideCorrection(correctionId: string, decision: 'APPROVE' | 'REJECT') {
    try {
      await apiClient.post(`/app/workshop-invoices/${id}/corrections/${correctionId}/decide`, { decision });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function decideCancellation(cancellationId: string, decision: 'APPROVE' | 'REJECT') {
    try {
      await apiClient.post(`/app/workshop-invoices/${id}/cancellations/${cancellationId}/decide`, { decision });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  if (error) return <ErrorState message={error} />;
  if (!invoice) return <LoadingState />;

  const pendingCorrection = invoice.corrections?.find((c) => c.status === 'PENDING');
  const pendingCancellation = invoice.cancellations?.find((c) => c.status === 'PENDING');
  const canPay = invoice.status === 'RECORDED' && invoice.memo?.status === 'BILLED' && !invoice.payment;

  return (
    <div>
      <BackButton fallbackTo={`/app/work-orders/${invoice.work_order_id}`} label={t('workOrder.actions.backToWorkOrder')} />
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{t('workshopInvoice.titles.serviceInvoiceExternalInvoiceNumber', { external_invoice_number: invoice.external_invoice_number })}</h1>
      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: 0 }}>
        {t('workshopInvoice.help.recordedExternallyIssuedDocumentOptiFleet')}
      </p>

      <div className="card" style={{ marginBottom: 16 }}>
        <div style={{ display: 'flex', gap: 16, alignItems: 'center', marginBottom: 10, flexWrap: 'wrap' }}>
          <StatusBadge status={invoice.status} />
          <span style={{ fontSize: 12, color: '#6b7280' }}>{t('workshopInvoice.fields.maintenanceMemo')}: <StatusBadge status={invoice.memo?.status ?? '—'} /></span>
          <button className="btn-secondary" disabled={printing} onClick={printInvoice}>
            {printing ? t('common.actions.loading') : t('common.actions.print')}
          </button>
          <DocumentVersionsButton printPath={`/app/workshop-invoices/${invoice.id}/print`} />
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, fontSize: 13 }}>
          <div><strong>{t('workshopInvoice.fields.serviceProvider')}:</strong> {invoice.partner?.name ?? invoice.partner_id}</div>
          <div><strong>{t('common.fields.workOrder')}:</strong> <Link to={`/app/work-orders/${invoice.work_order_id}`}>{invoice.work_order?.wo_number ?? t('workshopInvoice.actions.openWorkOrder')}</Link></div>
          <div><strong>{t('workshopInvoice.fields.partnerReference')}:</strong> {invoice.partner_reference ?? '—'}</div>
          <div><strong>{t('common.fields.invoiceDate')}:</strong> {invoice.invoice_date}</div>
          <div><strong>{t('common.fields.dueDate')}:</strong> {invoice.due_date ?? '—'}</div>
          <div><strong>{t('workOrder.fields.currency')}:</strong> {invoice.currency}</div>
          <div><strong>{t('documents.platformInvoice.subtotal')}:</strong> {formatMoney(invoice.subtotal)}</div>
          <div><strong>{t('common.fields.tax')}:</strong> {formatMoney(invoice.tax_total)}</div>
          <div><strong>{t('common.fields.discount')}:</strong> {formatMoney(invoice.discount_total)}</div>
          <div style={{ gridColumn: '1 / -1', fontSize: 15 }}><strong>{t('workshopInvoice.fields.totalAmountTotalAmount', { total_amount: formatMoney(invoice.total_amount, invoice.currency) })}</strong></div>
        </div>
        {invoice.notes && <p style={{ fontSize: 13, marginTop: 10 }}><strong>{t('common.fields.notes')}:</strong> {invoice.notes}</p>}
        <div style={{ display: 'flex', gap: 16, marginTop: 10, fontSize: 12 }}>
          {invoice.returned_memo_attachment_url && (
            <a href={invoice.returned_memo_attachment_url} target="_blank" rel="noreferrer">{t('workshopInvoice.actions.returnedMemoAttachment')}</a>
          )}
          {invoice.invoice_attachment_url && (
            <a href={invoice.invoice_attachment_url} target="_blank" rel="noreferrer">{t('workshopInvoice.actions.invoiceAttachment')}</a>
          )}
        </div>
      </div>

      {reconciliation && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('workshopInvoice.sections.reconciliation')}</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, fontSize: 13, marginBottom: 10 }}>
            <div><strong>{t('workshopInvoice.fields.expected')}:</strong> {formatMoney(reconciliation.expected_amount)}</div>
            <div><strong>{t('workshopInvoice.fields.invoiced')}:</strong> {formatMoney(reconciliation.invoiced_amount)}</div>
            <div><strong>{t('workshopInvoice.fields.variance')}:</strong> {formatMoney(reconciliation.variance_amount)} {reconciliation.variance_percent ? `(${reconciliation.variance_percent}%)` : ''}</div>
            <div><strong>{t('workshopInvoice.fields.workOrderEstimate')}:</strong> {formatMoney(reconciliation.work_order_estimated_total_cost)}</div>
            <div><StatusBadge status={reconciliation.reconciliation_status} /></div>
          </div>
          {reconciliation.missing_source_records.length > 0 && (
            <p style={{ fontSize: 12, color: '#b45309' }}>{t('workshopInvoice.fields.missingSourceRecords')}: {reconciliation.missing_source_records.join(', ')}</p>
          )}
          {reconciliation.unmatched_line_items.length > 0 && (
            <p style={{ fontSize: 12, color: '#b45309' }}>{t('workshopInvoice.fields.unmatchedLineItems')}: {reconciliation.unmatched_line_items.join(', ')}</p>
          )}
          {hasPermission('workshop_invoice.view_settlement_history') && (
            <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
              <input
                placeholder={t('workshopInvoice.placeholders.reconciliationNoteExceptionReasonOptional')}
                value={reconciliationNote}
                onChange={(e) => setReconciliationNote(e.target.value)}
                style={{ ...inputStyle, flex: 1 }}
              />
              <button className="btn-secondary" disabled={savingNote} onClick={saveReconciliationNote}>
                {t('workshopInvoice.actions.saveNote')}
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('workshopInvoice.sections.payment')}</h3>
        {invoice.payment ? (
          <div style={{ fontSize: 13 }}>
            <div><strong>{t('workshopInvoice.fields.paid')}:</strong> {t('workshopInvoice.help.amountOnDate', { amount: formatMoney(invoice.payment.paid_amount), date: formatDate(invoice.payment.payment_date) })}</div>
            <div><strong>{t('common.fields.method')}:</strong> {invoice.payment.payment_method ?? '—'} | <strong>{t('workshopInvoice.fields.reference')}:</strong> {invoice.payment.reference_number ?? '—'}</div>
            <div>
              <a href={invoice.payment.evidence_url} target="_blank" rel="noreferrer">{t('workshopInvoice.actions.paymentEvidence')}</a>
            </div>
            {invoice.payment.notes && <div>{t('workshopInvoice.fields.notesNotes', { notes: invoice.payment.notes })}</div>}
          </div>
        ) : (
          <p style={{ fontSize: 13, color: '#6b7280' }}>{t('workshopInvoice.empty.noPaymentRecordedYet')}</p>
        )}
        {canPay && hasPermission('workshop_invoice.upload_payment') && (
          <button className="btn-primary" style={{ marginTop: 8 }} onClick={() => setShowPayment(true)}>
            {t('workshopInvoice.actions.uploadPaymentEvidence')}
          </button>
        )}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('workshopInvoice.sections.correctionCancellation')}</h3>
        {invoice.status === 'RECORDED' && (
          <div style={{ display: 'flex', gap: 8 }}>
            {hasPermission('workshop_invoice.request_correction') && (
              <button className="btn-secondary" onClick={() => setShowCorrection(true)}>
                {t('workshopInvoice.actions.requestCorrection')}
              </button>
            )}
            {hasPermission('workshop_invoice.request_cancellation') && (
              <button className="btn-secondary" style={{ color: '#b91c1c' }} onClick={() => setShowCancellation(true)}>
                {t('workshopInvoice.actions.requestCancellation')}
              </button>
            )}
          </div>
        )}
        {pendingCorrection && (
          <div style={{ marginTop: 12, padding: 10, background: '#fffbeb', borderRadius: 6, fontSize: 13 }}>
            <div><strong>{t('workshopInvoice.fields.pendingCorrection')}</strong> — {pendingCorrection.reason}</div>
            <div style={{ fontSize: 12, color: '#6b7280' }}>{t('workshopInvoice.fields.requestedValuesValue', { value: JSON.stringify(pendingCorrection.requested_values) })}</div>
            {hasPermission('workshop_invoice.verify_correction') && (
              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                <button className="btn-primary" onClick={() => decideCorrection(pendingCorrection.id, 'APPROVE')}>{t('common.actions.approve')}</button>
                <button className="btn-secondary" onClick={() => decideCorrection(pendingCorrection.id, 'REJECT')}>{t('common.actions.reject')}</button>
              </div>
            )}
          </div>
        )}
        {pendingCancellation && (
          <div style={{ marginTop: 12, padding: 10, background: '#fef2f2', borderRadius: 6, fontSize: 13 }}>
            <div><strong>{t('workshopInvoice.fields.pendingCancellation')}</strong> — {pendingCancellation.reason}</div>
            {hasPermission('workshop_invoice.verify_cancellation') && (
              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                <button className="btn-primary" onClick={() => decideCancellation(pendingCancellation.id, 'APPROVE')}>{t('common.actions.approve')}</button>
                <button className="btn-secondary" onClick={() => decideCancellation(pendingCancellation.id, 'REJECT')}>{t('common.actions.reject')}</button>
              </div>
            )}
          </div>
        )}
        {(invoice.corrections?.length ?? 0) > 0 && (
          <div style={{ marginTop: 12 }}>
            <h4 style={{ fontSize: 13 }}>{t('workshopInvoice.sections.correctionHistory')}</h4>
            {invoice.corrections!.map((c) => (
              <div key={c.id} style={{ fontSize: 12, color: '#6b7280', padding: '4px 0', borderBottom: '1px solid #f3f4f6' }}>
                <StatusBadge status={c.status} /> {c.reason} {c.decision_note && `— ${c.decision_note}`}
              </div>
            ))}
          </div>
        )}
        {(invoice.cancellations?.length ?? 0) > 0 && (
          <div style={{ marginTop: 12 }}>
            <h4 style={{ fontSize: 13 }}>{t('workshopInvoice.sections.cancellationHistory')}</h4>
            {invoice.cancellations!.map((c) => (
              <div key={c.id} style={{ fontSize: 12, color: '#6b7280', padding: '4px 0', borderBottom: '1px solid #f3f4f6' }}>
                <StatusBadge status={c.status} /> {c.reason} {c.decision_note && `— ${c.decision_note}`}
              </div>
            ))}
          </div>
        )}
      </div>

      {showPayment && (
        <PaymentModal invoiceId={invoice.id} payableAmount={invoice.total_amount} onClose={() => setShowPayment(false)} onSaved={() => { setShowPayment(false); load(); }} />
      )}
      {showCorrection && (
        <CorrectionModal invoice={invoice} onClose={() => setShowCorrection(false)} onSaved={() => { setShowCorrection(false); load(); }} />
      )}
      {showCancellation && (
        <CancellationModal invoiceId={invoice.id} onClose={() => setShowCancellation(false)} onSaved={() => { setShowCancellation(false); load(); }} />
      )}
    </div>
  );
}

function PaymentModal({ invoiceId, payableAmount, onClose, onSaved }: { invoiceId: string; payableAmount: string; onClose: () => void; onSaved: () => void }) {
  const [paymentDate, setPaymentDate] = useState('');
  const [paidAmount, setPaidAmount] = useState(toMoneyInput(payableAmount));
  const [paymentMethod, setPaymentMethod] = useState('');
  const [referenceNumber, setReferenceNumber] = useState('');
  const [evidenceUrl, setEvidenceUrl] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/app/workshop-invoices/${invoiceId}/payments`, {
        payment_date: paymentDate, paid_amount: paidAmount, payment_method: paymentMethod || undefined,
        reference_number: referenceNumber || undefined, evidence_url: evidenceUrl, notes: notes || undefined,
      });
      onSaved();
    } catch (err) {
      setErrors(extractApiError(err).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('workshopInvoice.actions.uploadPaymentEvidence')} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('workshopInvoice.help.payableAmountPayableAmountPartialPayment', { payableAmount: formatMoney(payableAmount) })}</p>
      <FormField label={t('common.fields.paymentDate')} errors={errors.payment_date} required>
        <input type="date" value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('externalWorkOrderInvoice.fields.paidAmount')} errors={errors.paid_amount} required>
        <NumericInput step="0.01" value={paidAmount} onChange={(e) => setPaidAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('workshopInvoice.fields.paymentMethodOptional')} errors={errors.payment_method}>
        <input value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} placeholder={t('workshopInvoice.placeholders.eGBankTransfer')} style={inputStyle} />
      </FormField>
      <FormField label={t('workshopInvoice.fields.bankTransactionReferenceOptional')} errors={errors.reference_number}>
        <input value={referenceNumber} onChange={(e) => setReferenceNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('workshopInvoice.fields.paymentEvidenceUrlRequired')} errors={errors.evidence_url} required>
        <input value={evidenceUrl} onChange={(e) => setEvidenceUrl(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('inventory.placeholders.notesOptional')} errors={errors.notes}>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>{t('common.actions.cancel')}</button>
        <button className="btn-primary" disabled={submitting || !paymentDate || !paidAmount || !evidenceUrl} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('workshopInvoice.actions.confirmPayment')}
        </button>
      </div>
    </Modal>
  );
}

function CorrectionModal({ invoice, onClose, onSaved }: { invoice: WorkshopInvoiceItem; onClose: () => void; onSaved: () => void }) {
  const [totalAmount, setTotalAmount] = useState(toMoneyInput(invoice.total_amount));
  const [invoiceDate, setInvoiceDate] = useState(invoice.invoice_date);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const requestedValues: Record<string, unknown> = {};
      if (totalAmount !== toMoneyInput(invoice.total_amount)) requestedValues.total_amount = totalAmount;
      if (invoiceDate !== invoice.invoice_date) requestedValues.invoice_date = invoiceDate;
      await apiClient.post(`/app/workshop-invoices/${invoice.id}/request-correction`, { requested_values: requestedValues, reason });
      onSaved();
    } catch (err) {
      setErrors(extractApiError(err).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('workshopInvoice.modals.requestInvoiceCorrection')} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('workshopInvoice.help.requiresApprovalDifferentUserOriginalValues')}</p>
      <FormField label={t('workshopInvoice.fields.correctedTotalAmount')}>
        <NumericInput step="0.01" value={totalAmount} onChange={(e) => setTotalAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('workshopInvoice.fields.correctedInvoiceDate')}>
        <input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.reason')} errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>{t('common.actions.cancel')}</button>
        <button className="btn-primary" disabled={submitting || !reason} onClick={submit}>
          {submitting ? t('common.actions.submitting') : t('workshopInvoice.actions.submitCorrectionRequest')}
        </button>
      </div>
    </Modal>
  );
}

function CancellationModal({ invoiceId, onClose, onSaved }: { invoiceId: string; onClose: () => void; onSaved: () => void }) {
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/app/workshop-invoices/${invoiceId}/request-cancellation`, { reason });
      onSaved();
    } catch (err) {
      setErrors(extractApiError(err).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('workshopInvoice.modals.requestInvoiceCancellation')} onClose={onClose}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>{t('workshopInvoice.help.requiresApprovalDifferentUserPreservesPayment')}</p>
      <FormField label={t('common.fields.reason')} errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>{t('common.actions.cancel')}</button>
        <button className="btn-primary" style={{ background: '#b91c1c' }} disabled={submitting || !reason} onClick={submit}>
          {submitting ? t('common.actions.submitting') : t('workshopInvoice.actions.submitCancellationRequest')}
        </button>
      </div>
    </Modal>
  );
}
