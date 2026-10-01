import { useEffect, useRef, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FileUploadField } from '../../../components/FileUploadField';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { NumericInput } from '../../../components/NumericInput';
import type { VendorInvoiceSummary } from '../../../types';
import { formatDate } from '../../../utils/date';
import { PAYMENT_PROOF_RULE } from '../../../utils/fileRules';
import { formatMoney } from '../../../utils/money';
import { downloadProtectedFile, fetchBlobUrl, openProtectedFile } from '../../../utils/protectedFile';

function todayLocal(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Payment of a vendor invoice (full settlement). The payment belongs to the invoice: opened from
 * any of its Goods Receipt rows it settles the invoice once, and every row then shows PAID.
 * Payment date, amount and proof are sent in one multipart request; the backend re-validates
 * everything and rejects a second payment.
 */
export function VendorInvoicePaymentModal({ invoice, onClose, onPaid }: { invoice: VendorInvoiceSummary; onClose: () => void; onPaid: () => void }) {
  const [paymentDate, setPaymentDate] = useState(todayLocal());
  // Full settlement: pre-filled with the invoice amount (still editable, checked on the server).
  // (string trim of trailing zeros — never a float round-trip for money).
  const [amount, setAmount] = useState(() => (invoice.amount?.includes('.') ? invoice.amount.replace(/0+$/, '').replace(/\.$/, '') : (invoice.amount ?? '')));
  const [proof, setProof] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const inFlight = useRef(false);

  async function submit() {
    if (inFlight.current || !proof) return;
    inFlight.current = true;
    setSubmitting(true);
    setErrors({});
    setMessage(null);
    const form = new FormData();
    form.append('payment_date', paymentDate);
    form.append('amount', amount);
    form.append('payment_proof', proof);
    try {
      await apiClient.post(`/app/vendor-invoice-references/${invoice.id}/payments`, form);
      onPaid();
    } catch (err) {
      const apiError = extractApiError(err);
      setErrors(apiError.errors ?? {});
      setMessage(apiError.errors ? null : apiError.message);
    } finally {
      inFlight.current = false;
      setSubmitting(false);
    }
  }

  const readOnly = { ...inputStyle, background: '#f3f4f6', color: '#374151' };

  return (
    <Modal open title="Payment" onClose={onClose} width={520}>
      <FormField label="Vendor Name">
        <input value={invoice.partner?.name ?? '—'} readOnly aria-label="Vendor Name" style={readOnly} />
      </FormField>
      <FormField label="Invoice Number">
        <input value={invoice.vendor_invoice_number} readOnly aria-label="Invoice Number" style={readOnly} />
      </FormField>
      <p style={{ fontSize: 12, color: '#6b7280', margin: '-4px 0 10px' }}>
        Invoice amount {formatMoney(invoice.amount)} · due {formatDate(invoice.due_date)} · full settlement
      </p>
      <FormField label="Payment Date" errors={errors.payment_date} required>
        <input type="date" value={paymentDate} max={todayLocal()} onChange={(e) => setPaymentDate(e.target.value)} aria-label="Payment Date" style={inputStyle} />
      </FormField>
      <FormField label="Amount" errors={errors.amount} required>
        <NumericInput value={amount} onChange={(e) => setAmount(e.target.value)} aria-label="Payment Amount" style={inputStyle} />
      </FormField>
      <FormField label="Payment Proof (JPG, JPEG, PNG or PDF, max 10 MB)" errors={errors.payment_proof} required>
        <FileUploadField file={proof} onChange={setProof} rule={PAYMENT_PROOF_RULE} ariaLabel="Payment Proof" />
      </FormField>
      {message && <div style={{ color: '#b91c1c', fontSize: 13, marginTop: 8 }}>{message}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose} disabled={submitting}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !paymentDate || !(Number(amount) > 0) || !proof} onClick={submit}>
          {submitting ? 'Saving…' : 'Submit Payment'}
        </button>
      </div>
    </Modal>
  );
}

/** Payment proof: images are previewed in place; PDFs open in the browser viewer. Both downloadable. */
export function PaymentProofModal({ invoice, onClose }: { invoice: VendorInvoiceSummary; onClose: () => void }) {
  const path = `/app/vendor-invoice-references/${invoice.id}/payment-proof`;
  const isImage = (invoice.payment?.proof_mime_type ?? '').startsWith('image/');
  const name = invoice.payment?.proof_original_name ?? 'payment-proof';
  const [preview, setPreview] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isImage) return;
    let url: string | null = null;
    fetchBlobUrl(path)
      .then((u) => {
        url = u;
        setPreview(u);
      })
      .catch((err) => setError(extractApiError(err).message));
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [isImage, path]);

  return (
    <Modal open title={`Payment Proof — ${invoice.vendor_invoice_number}`} onClose={onClose} width={640}>
      <p style={{ fontSize: 13, marginTop: 0 }}>
        Paid {formatDate(invoice.payment?.payment_date)} · {formatMoney(invoice.payment?.amount)}
        {invoice.payment?.paid_by && <span style={{ color: '#6b7280' }}> · recorded by {invoice.payment.paid_by}</span>}
      </p>
      {error && <div style={{ color: '#b91c1c', fontSize: 13 }}>{error}</div>}
      {isImage ? (
        preview ? <img src={preview} alt={`Payment proof ${name}`} style={{ maxWidth: '100%', maxHeight: '60vh', display: 'block', margin: '0 auto', border: '1px solid #e5e7eb' }} /> : !error && <div style={{ fontSize: 13 }}>Loading…</div>
      ) : (
        <div style={{ fontSize: 13 }}>
          📎 {name}{' '}
          <button type="button" className="btn-link" onClick={() => openProtectedFile(path)}>
            Open PDF
          </button>
        </div>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={() => downloadProtectedFile(path, name)}>
          Download
        </button>
        <button className="btn-primary" onClick={onClose}>
          Close
        </button>
      </div>
    </Modal>
  );
}
