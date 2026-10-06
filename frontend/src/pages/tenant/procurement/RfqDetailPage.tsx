import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { DocumentVersionsButton } from '../../../components/DocumentVersions';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { PartnerItem, RfqItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';

/** Only these partner types supply purchased goods and can be invited (backend enforces the same). */
const RFQ_VENDOR_TYPES = ['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER'];

interface ComparisonRow {
  quotation_id: string;
  partner: { id: string; name: string; code: string };
  total: number;
  lead_time_days: number | null;
  payment_terms: string | null;
  status: string;
  submitted_at?: string | null;
  has_attachment?: boolean;
  attachment_original_filename?: string | null;
  purchase_order?: { id: string; po_number: string; status: string } | null;
  /** Server-computed: SELECTED and no PO yet. */
  can_create_purchase_order?: boolean;
}

/** Quotation document rules (the backend re-checks the file content). */
const QUOTATION_DOC_EXTENSIONS = ['pdf', 'doc', 'docx'];
const QUOTATION_DOC_MAX_BYTES = 10 * 1024 * 1024;

function quotationDocError(file: File): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!QUOTATION_DOC_EXTENSIONS.includes(ext)) return 'The quotation document must be a PDF or Word (DOC/DOCX) file.';
  if (file.size > QUOTATION_DOC_MAX_BYTES) return 'The quotation document may not be larger than 10 MB.';
  return null;
}

export function RfqDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [rfq, setRfq] = useState<RfqItem | null>(null);
  const [comparison, setComparison] = useState<ComparisonRow[]>([]);
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [invitePartnerId, setInvitePartnerId] = useState('');
  const [quotePartnerId, setQuotePartnerId] = useState('');
  const [quotePrices, setQuotePrices] = useState<Record<string, string>>({});
  const [quoteLeadTime, setQuoteLeadTime] = useState('');
  const [quoteNumber, setQuoteNumber] = useState('');
  const [quoteFile, setQuoteFile] = useState<File | null>(null);
  const [quoteFileError, setQuoteFileError] = useState<string | null>(null);
  const [quoteErrors, setQuoteErrors] = useState<Record<string, string[]>>({});
  const quoteFileInput = useRef<HTMLInputElement>(null);
  const [printingVendorId, setPrintingVendorId] = useState<string | null>(null);

  function load() {
    apiClient.get(`/app/rfqs/${id}`).then((res) => setRfq(res.data.data)).catch((err) => setError(extractApiError(err).message));
    apiClient.get(`/app/rfqs/${id}/compare`).then((res) => setComparison(res.data.data)).catch(() => setComparison([]));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient
      .get('/app/partners', { params: { partner_type: RFQ_VENDOR_TYPES, status: 'ACTIVE', per_page: 100 } })
      .then((res) => setPartners(res.data.data))
      .catch(() => setPartners([]));
  }, []);

  useBreadcrumbLabel(rfq?.id, rfq?.rfq_number);

  async function inviteVendor() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/rfqs/${id}/vendors`, { partner_ids: [invitePartnerId] });
      setInvitePartnerId('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  /** Vendor-specific RFQ document (PDF), addressed to the selected invited vendor. */
  async function printForVendor(vendorId: string) {
    setPrintingVendorId(vendorId);
    setError(null);
    try {
      const res = await apiClient.get(`/app/rfqs/${id}/vendors/${vendorId}/print`, { responseType: 'blob' });
      window.open(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })), '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setPrintingVendorId(null);
    }
  }

  function pickQuoteFile(file: File | null) {
    setQuoteFileError(file ? quotationDocError(file) : null);
    setQuoteFile(file && !quotationDocError(file) ? file : null);
    if (quoteFileInput.current) quoteFileInput.current.value = '';
  }

  /** Record Quotation: multipart request; the vendor quotation document is optional. */
  async function submitQuotation() {
    if (!rfq) return;
    setBusy(true);
    setError(null);
    setQuoteErrors({});
    const form = new FormData();
    form.append('partner_id', quotePartnerId);
    if (quoteLeadTime) form.append('lead_time_days', quoteLeadTime);
    if (quoteNumber) form.append('quotation_number', quoteNumber);
    if (quoteFile) form.append('attachment', quoteFile);
    (rfq.items ?? []).forEach((item, i) => {
      form.append(`items[${i}][rfq_item_id]`, item.id);
      form.append(`items[${i}][product_id]`, item.product_id);
      form.append(`items[${i}][quantity]`, item.quantity);
      form.append(`items[${i}][unit_price]`, quotePrices[item.id] ?? '');
    });
    try {
      await apiClient.post(`/app/rfqs/${id}/quotations`, form);
      setQuotePartnerId('');
      setQuotePrices({});
      setQuoteLeadTime('');
      setQuoteNumber('');
      setQuoteFile(null);
      load();
    } catch (err) {
      const apiError = extractApiError(err);
      setError(apiError.message);
      setQuoteErrors(apiError.errors ?? {});
    } finally {
      setBusy(false);
    }
  }

  async function openQuotationDocument(row: ComparisonRow, download: boolean) {
    setError(null);
    try {
      const res = await apiClient.get(`/app/quotations/${row.quotation_id}/attachment`, { params: download ? { download: 1 } : undefined, responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      if (download) {
        const a = document.createElement('a');
        a.href = url;
        a.download = row.attachment_original_filename ?? 'quotation';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } else {
        window.open(url, '_blank');
      }
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function selectVendor(quotationId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/quotations/${quotationId}/select`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  function createPo(quotationId: string) {
    navigate(`/app/quotations/${quotationId}/create-po`);
  }

  if (error && !rfq) return <ErrorState message={error} />;
  if (!rfq) return <LoadingState />;

  const selected = comparison.find((c) => c.status === 'SELECTED');
  // A vendor already in Quotation Comparison can never be recorded again (backend rejects it too).
  const quotableVendors = (rfq.vendors ?? []).filter((v) => !comparison.some((c) => c.partner.id === v.id));
  const pricesComplete = (rfq.items ?? []).every((item) => (quotePrices[item.id] ?? '') !== '' && Number(quotePrices[item.id]) >= 0);

  return (
    <div>
      <BackButton fallbackTo="/app/rfqs" label="← Back to RFQ" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{rfq.rfq_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={rfq.status} domain="document" />
          {rfq.status === 'ISSUED' && hasPermission('rfq.manage') && (
            <button className="btn-secondary" disabled={busy} onClick={async () => { setBusy(true); try { await apiClient.post(`/app/rfqs/${id}/close`); load(); } finally { setBusy(false); } }}>
              Close RFQ
            </button>
          )}
        </div>
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {(rfq.items ?? []).map((item) => (
          <div key={item.id} style={{ fontSize: 13, padding: '4px 0' }}>
            {item.product?.name ?? item.product_id} — qty {formatQty(item.quantity)}
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Invited Vendors</h3>
        {(rfq.vendors ?? []).length === 0 && <EmptyState label="No vendors invited yet." />}
        {(rfq.vendors ?? []).map((v) => (
          <div key={v.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, fontSize: 13, padding: '6px 0', borderBottom: '1px solid #f3f4f6' }}>
            <span>
              {v.name} ({v.code})
            </span>
            <span style={{ display: 'flex', gap: 6 }}>
              <button className="btn-secondary" style={{ padding: '4px 10px', fontSize: 12 }} disabled={printingVendorId === v.id} onClick={() => printForVendor(v.id)}>
                {printingVendorId === v.id ? 'Loading…' : 'Print'}
              </button>
              <DocumentVersionsButton printPath={`/app/rfqs/${rfq.id}/vendors/${v.id}/print`} />
            </span>
          </div>
        ))}
        {hasPermission('rfq.manage') && rfq.status !== 'CLOSED' && rfq.status !== 'CANCELLED' && (
          <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
            <select value={invitePartnerId} onChange={(e) => setInvitePartnerId(e.target.value)} style={{ ...inputStyle, width: 240 }}>
              <option value="">Select vendor…</option>
              {partners
                .filter((p) => !(rfq.vendors ?? []).some((v) => v.id === p.id))
                .map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
            </select>
            <button className="btn-secondary" disabled={busy || !invitePartnerId} onClick={inviteVendor}>
              Invite
            </button>
          </div>
        )}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Quotation Comparison</h3>
        {comparison.length === 0 && <EmptyState label="No quotations submitted yet." />}
        {comparison.map((c) => (
          <div key={c.quotation_id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            <span>
              {c.partner.name} — total {formatMoney(c.total)} — lead time {c.lead_time_days ?? '—'}d — <StatusBadge status={c.status} />
              {c.has_attachment ? (
                <span style={{ marginLeft: 8, fontSize: 12 }}>
                  📎 Document available: {c.attachment_original_filename}{' '}
                  <button className="btn-link" onClick={() => openQuotationDocument(c, false)}>
                    View
                  </button>{' '}
                  <button className="btn-link" onClick={() => openQuotationDocument(c, true)}>
                    Download
                  </button>
                </span>
              ) : (
                <span style={{ marginLeft: 8, fontSize: 12, color: '#9ca3af' }}>No document uploaded</span>
              )}
            </span>
            <div style={{ display: 'flex', gap: 6 }}>
              {c.status === 'SUBMITTED' && rfq.status === 'ISSUED' && hasPermission('quotation.select') && (
                <button className="btn-link" disabled={busy} onClick={() => selectVendor(c.quotation_id)}>
                  Select
                </button>
              )}
              {c.can_create_purchase_order && hasPermission('purchase_order.create') && (
                <button className="btn-link" onClick={() => createPo(c.quotation_id)}>
                  Create PO
                </button>
              )}
              {c.purchase_order && (
                <Link to={`/app/purchase-orders/${c.purchase_order.id}`} style={{ fontSize: 13 }}>
                  PO {c.purchase_order.po_number}
                </Link>
              )}
            </div>
          </div>
        ))}
        {!selected && hasPermission('quotation.manage') && rfq.status === 'ISSUED' && (rfq.vendors ?? []).length > 0 && (
          <div style={{ marginTop: 14, paddingTop: 12, borderTop: '1px solid #e5e7eb' }}>
            <h4 style={{ margin: '0 0 8px', fontSize: 13 }}>Record Quotation</h4>
            {quotableVendors.length === 0 ? (
              <p style={{ fontSize: 13, color: '#6b7280', margin: 0 }}>Every invited vendor has already submitted a quotation.</p>
            ) : (
              <>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                  <FormField label="Vendor" errors={quoteErrors.partner_id} required>
                    <select aria-label="Quotation vendor" value={quotePartnerId} onChange={(e) => setQuotePartnerId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                      <option value="">Select…</option>
                      {quotableVendors.map((v) => (
                        <option key={v.id} value={v.id}>
                          {v.name}
                        </option>
                      ))}
                    </select>
                  </FormField>
                  <FormField label="Quotation No." errors={quoteErrors.quotation_number}>
                    <input value={quoteNumber} onChange={(e) => setQuoteNumber(e.target.value)} maxLength={100} style={{ ...inputStyle, width: 150 }} />
                  </FormField>
                  <FormField label="Lead Time (days after PO)" errors={quoteErrors.lead_time_days}>
                    <NumericInput integer value={quoteLeadTime} onChange={(e) => setQuoteLeadTime(e.target.value)} style={{ ...inputStyle, width: 120 }} />
                  </FormField>
                </div>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginBottom: 10 }}>
                  <thead>
                    <tr style={{ textAlign: 'left', color: '#6b7280' }}>
                      <th style={{ padding: 4 }}>Item</th>
                      <th style={{ padding: 4 }}>Qty</th>
                      <th style={{ padding: 4, width: 160 }}>Unit Price</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(rfq.items ?? []).map((item, i) => (
                      <tr key={item.id} style={{ borderTop: '1px solid #f3f4f6' }}>
                        <td style={{ padding: 4 }}>{item.product?.name ?? item.product_id}</td>
                        <td style={{ padding: 4 }}>{formatQty(item.quantity)}</td>
                        <td style={{ padding: 4 }}>
                          <NumericInput
                            aria-label={`Unit price for ${item.product?.name ?? 'item'}`}
                            value={quotePrices[item.id] ?? ''}
                            onChange={(e) => setQuotePrices((prev) => ({ ...prev, [item.id]: e.target.value }))}
                            style={{ ...inputStyle, width: 140 }}
                          />
                          {quoteErrors[`items.${i}.unit_price`] && <div style={{ color: '#b91c1c', fontSize: 11 }}>{quoteErrors[`items.${i}.unit_price`][0]}</div>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <FormField label="Quotation Document (optional — PDF, DOC, DOCX, max 10 MB)" errors={quoteErrors.attachment ?? (quoteFileError ? [quoteFileError] : undefined)}>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                    <button type="button" className="btn-secondary" onClick={() => quoteFileInput.current?.click()} disabled={busy}>
                      {quoteFile ? 'Replace file' : 'Choose file'}
                    </button>
                    {quoteFile ? (
                      <>
                        <span style={{ fontSize: 13 }}>📎 {quoteFile.name}</span>
                        <button type="button" className="btn-link" onClick={() => pickQuoteFile(null)} disabled={busy}>
                          Remove
                        </button>
                      </>
                    ) : (
                      <span style={{ fontSize: 12, color: '#6b7280' }}>No file selected — the quotation can be recorded without a document.</span>
                    )}
                    <input
                      ref={quoteFileInput}
                      type="file"
                      aria-label="Quotation document"
                      accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                      style={{ display: 'none' }}
                      onChange={(e) => pickQuoteFile(e.target.files?.[0] ?? null)}
                    />
                  </div>
                </FormField>
                <button className="btn-primary" disabled={busy || !quotePartnerId || !pricesComplete || Boolean(quoteFileError)} onClick={submitQuotation}>
                  {busy ? (quoteFile ? 'Uploading…' : 'Saving…') : 'Record Quotation'}
                </button>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
