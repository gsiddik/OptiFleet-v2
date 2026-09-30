import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { PartnerItem, RfqItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';

interface ComparisonRow {
  quotation_id: string;
  partner: { id: string; name: string; code: string };
  total: number;
  lead_time_days: number | null;
  payment_terms: string | null;
  status: string;
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
  const [quoteUnitPrice, setQuoteUnitPrice] = useState('');
  const [quoteLeadTime, setQuoteLeadTime] = useState('');

  function load() {
    apiClient.get(`/app/rfqs/${id}`).then((res) => setRfq(res.data.data)).catch((err) => setError(extractApiError(err).message));
    apiClient.get(`/app/rfqs/${id}/compare`).then((res) => setComparison(res.data.data)).catch(() => setComparison([]));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/partners', { params: { per_page: 100 } }).then((res) => setPartners(res.data.data)).catch(() => setPartners([]));
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

  async function submitQuotation() {
    if (!rfq) return;
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/rfqs/${id}/quotations`, {
        partner_id: quotePartnerId,
        lead_time_days: quoteLeadTime || undefined,
        items: (rfq.items ?? []).map((item) => ({ rfq_item_id: item.id, product_id: item.product_id, quantity: item.quantity, unit_price: quoteUnitPrice })),
      });
      setQuoteUnitPrice('');
      setQuoteLeadTime('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
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

  return (
    <div>
      <BackButton fallbackTo="/app/rfqs" label="← Back to RFQ" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{rfq.rfq_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={rfq.status} />
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
          <div key={v.id} style={{ fontSize: 13, padding: '4px 0' }}>
            {v.name} ({v.code})
          </div>
        ))}
        {hasPermission('rfq.manage') && rfq.status !== 'CLOSED' && rfq.status !== 'CANCELLED' && (
          <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
            <select value={invitePartnerId} onChange={(e) => setInvitePartnerId(e.target.value)} style={{ ...inputStyle, width: 240 }}>
              <option value="">Select vendor…</option>
              {partners.map((p) => (
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
            </span>
            <div style={{ display: 'flex', gap: 6 }}>
              {c.status === 'SUBMITTED' && hasPermission('quotation.select') && (
                <button className="btn-link" disabled={busy} onClick={() => selectVendor(c.quotation_id)}>
                  Select
                </button>
              )}
              {c.status === 'SELECTED' && hasPermission('purchase_order.create') && (
                <button className="btn-link" onClick={() => createPo(c.quotation_id)}>
                  Create PO
                </button>
              )}
            </div>
          </div>
        ))}
        {!selected && hasPermission('quotation.manage') && rfq.status === 'ISSUED' && (rfq.vendors ?? []).length > 0 && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Vendor" required>
              <select value={quotePartnerId} onChange={(e) => setQuotePartnerId(e.target.value)} style={{ ...inputStyle, width: 200 }}>
                <option value="">Select…</option>
                {(rfq.vendors ?? []).map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Unit Price (applies to all lines)" required>
              <NumericInput step="0.01" value={quoteUnitPrice} onChange={(e) => setQuoteUnitPrice(e.target.value)} style={{ ...inputStyle, width: 130 }} />
            </FormField>
            <FormField label="Lead Time (days)">
              <NumericInput value={quoteLeadTime} onChange={(e) => setQuoteLeadTime(e.target.value)} style={{ ...inputStyle, width: 110 }} />
            </FormField>
            <button className="btn-secondary" disabled={busy || !quotePartnerId || !quoteUnitPrice} onClick={submitQuotation} style={{ marginBottom: 14 }}>
              Record Quotation
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
