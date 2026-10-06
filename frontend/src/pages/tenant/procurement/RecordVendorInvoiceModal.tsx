import { useRef, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FileUploadField } from '../../../components/FileUploadField';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { NumericInput } from '../../../components/NumericInput';
import type { VendorInvoiceReferenceItem } from '../../../types';
import { formatDate } from '../../../utils/date';
import { INVOICE_PDF_RULE } from '../../../utils/fileRules';
import { formatMoney } from '../../../utils/money';
import { openProtectedFile } from '../../../utils/protectedFile';
import { t } from '../../../i18n/i18n';

export interface ReceiptLine {
  purchase_order_item_id: string;
  quantity_accepted: string;
}

/**
 * Post Goods Receipt → Record Vendor Invoice Reference. The receipt lines and the invoice are
 * sent in ONE multipart request, so the Goods Receipt is posted only together with its invoice
 * (and the invoice PDF only together with the receipt). On a partial receipt the invoice of the
 * previous Goods Receipt can be reused — no new invoice, no new upload.
 */
export function RecordVendorInvoiceModal({
  purchaseOrderId,
  vendorName,
  lines,
  previousInvoice,
  paidPreviousInvoice,
  canViewDocuments,
  onClose,
  onPosted,
}: {
  purchaseOrderId: string;
  vendorName: string;
  lines: ReceiptLine[];
  previousInvoice: VendorInvoiceReferenceItem | null;
  /** The previous receipt's invoice when it is already paid — shown as a note, never reusable. */
  paidPreviousInvoice: VendorInvoiceReferenceItem | null;
  canViewDocuments: boolean;
  onClose: () => void;
  onPosted: () => void;
}) {
  const [useSame, setUseSame] = useState(false);
  const [number, setNumber] = useState('');
  const [invoiceDate, setInvoiceDate] = useState('');
  const [amount, setAmount] = useState('');
  const [terms, setTerms] = useState('');
  const [document, setDocument] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Blocks a second submit (double click) before the disabled button has re-rendered.
  const inFlight = useRef(false);

  const reuse = useSame && previousInvoice !== null;
  const complete = reuse || (number.trim() !== '' && invoiceDate !== '' && Number(amount) > 0 && terms !== '');

  async function submit() {
    if (inFlight.current) return;
    inFlight.current = true;
    setSubmitting(true);
    setErrors({});
    setMessage(null);
    const form = new FormData();
    lines.forEach((line, i) => {
      form.append(`lines[${i}][purchase_order_item_id]`, line.purchase_order_item_id);
      form.append(`lines[${i}][quantity_accepted]`, line.quantity_accepted);
    });
    if (reuse && previousInvoice) {
      form.append('invoice_mode', 'EXISTING');
      form.append('vendor_invoice_reference_id', previousInvoice.id);
    } else {
      form.append('invoice_mode', 'NEW');
      form.append('vendor_invoice_number', number.trim());
      form.append('vendor_invoice_date', invoiceDate);
      form.append('amount', amount);
      form.append('terms_of_payment_days', terms);
      if (document) form.append('invoice_document', document);
    }
    try {
      await apiClient.post(`/app/purchase-orders/${purchaseOrderId}/goods-receipts`, form);
      onPosted();
    } catch (err) {
      const apiError = extractApiError(err);
      setErrors(apiError.errors ?? {});
      setMessage(apiError.message);
    } finally {
      inFlight.current = false;
      setSubmitting(false);
    }
  }

  const readOnly = { ...inputStyle, background: '#f3f4f6', color: '#374151' };

  return (
    <Modal open title={t('procurement.modals.recordVendorInvoiceReference')} onClose={onClose} width={560}>
      <FormField label={t('procurement.fields.vendorName')}>
        <input value={vendorName} readOnly aria-label={t('procurement.fields.vendorName')} style={readOnly} />
      </FormField>

      {paidPreviousInvoice && (
        <p style={{ fontSize: 12, color: '#92400e', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 6, padding: '6px 10px', margin: '4px 0 12px' }}>
          {t('procurement.help.previousInvoicePaid', { number: paidPreviousInvoice.vendor_invoice_number })}
        </p>
      )}
      {previousInvoice && (
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, margin: '4px 0 12px' }}>
          <input type="checkbox" checked={useSame} onChange={(e) => setUseSame(e.target.checked)} />
          {t('procurement.fields.useSameInvoicePreviousGoodsReceipt')}
        </label>
      )}

      {reuse && previousInvoice ? (
        <div style={{ border: '1px solid #e5e7eb', borderRadius: 6, padding: 12, fontSize: 13, display: 'grid', gridTemplateColumns: '150px 1fr', rowGap: 6, marginBottom: 8 }}>
          <span style={{ color: '#6b7280' }}>{t('procurement.fields.invoiceNumber')}</span>
          <strong>{previousInvoice.vendor_invoice_number}</strong>
          <span style={{ color: '#6b7280' }}>{t('common.fields.invoiceDate')}</span>
          <span>{formatDate(previousInvoice.vendor_invoice_date)}</span>
          <span style={{ color: '#6b7280' }}>{t('common.fields.amount')}</span>
          <span>{formatMoney(previousInvoice.amount)}</span>
          <span style={{ color: '#6b7280' }}>{t('procurement.fields.termsOfPayment')}</span>
          <span>{previousInvoice.terms_of_payment_days ?? '—'} {t('procurement.fields.workingDays')}</span>
          <span style={{ color: '#6b7280' }}>{t('procurement.tooltips.invoiceDocument')}</span>
          <span>
            {previousInvoice.has_document ? (
              <>
                📎 {previousInvoice.attachment_original_name ?? t('account.fields.invoice')} {t('procurement.fields.previousInvoice')}{' '}
                {canViewDocuments && (
                <button type="button" className="btn-link" onClick={() => openProtectedFile(`/app/vendor-invoice-references/${previousInvoice.id}/download`)}>
                  {t('common.actions.view')}
                </button>
                )}
              </>
            ) : (
              'No document uploaded'
            )}
          </span>
        </div>
      ) : (
        <>
          <FormField label={t('procurement.fields.invoiceNumber')} errors={errors.vendor_invoice_number} required>
            <input value={number} onChange={(e) => setNumber(e.target.value)} maxLength={100} aria-label={t('procurement.fields.invoiceNumber')} style={inputStyle} />
          </FormField>
          <FormField label={t('common.fields.invoiceDate')} errors={errors.vendor_invoice_date} required>
            <input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} aria-label={t('common.fields.invoiceDate')} style={inputStyle} />
          </FormField>
          <FormField label={t('common.fields.amount')} errors={errors.amount} required>
            <NumericInput value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="e.g. 12500000" aria-label={t('common.fields.amount')} style={inputStyle} />
          </FormField>
          <FormField label={t('procurement.fields.termsPaymentWorkingDays')} errors={errors.terms_of_payment_days} required>
            <NumericInput integer value={terms} onChange={(e) => setTerms(e.target.value)} placeholder="e.g. 30" aria-label={t('procurement.fields.termsOfPayment')} style={inputStyle} />
          </FormField>
          <FormField label={t('procurement.fields.invoiceDocumentPdfMax10Mb')} errors={errors.invoice_document}>
            <FileUploadField file={document} onChange={setDocument} rule={INVOICE_PDF_RULE} ariaLabel={t('procurement.tooltips.invoiceDocument')} />
          </FormField>
        </>
      )}

      {message && !Object.keys(errors).some((k) => ['vendor_invoice_number', 'vendor_invoice_date', 'amount', 'terms_of_payment_days', 'invoice_document'].includes(k)) && (
        <div style={{ color: '#b91c1c', fontSize: 13, marginTop: 8 }}>{message}</div>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose} disabled={submitting}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !complete} onClick={submit}>
          {submitting ? t('procurement.actions.posting') : t('procurement.actions.submitPostGoodsReceipt')}
        </button>
      </div>
    </Modal>
  );
}
