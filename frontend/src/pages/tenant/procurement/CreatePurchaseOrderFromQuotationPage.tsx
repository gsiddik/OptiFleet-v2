import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import type { VendorQuotationItem, Warehouse } from '../../../types';

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Preview only — the backend computes and stores the Expected Receipt Date (calendar days). */
function addDays(isoDate: string, days: number): string {
  const [y, m, d] = isoDate.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d + days));
  return date.toISOString().slice(0, 10);
}

/**
 * Create Purchase Order from the selected quotation. The goods, quantities and the vendor's unit
 * prices shown here are the quotation's own lines (read-only — the backend copies them from the
 * quotation, never from this page). The Order Date is chosen here; Expected Receipt Date =
 * Order Date + the vendor's lead time.
 */
export function CreatePurchaseOrderFromQuotationPage() {
  const { quotationId } = useParams<{ quotationId: string }>();
  const navigate = useNavigate();
  const [quotation, setQuotation] = useState<VendorQuotationItem | null>(null);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
  const [orderDate, setOrderDate] = useState(today());
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiClient
      .get(`/app/quotations/${quotationId}`)
      .then((res) => {
        setQuotation(res.data.data);
        if (res.data.data?.rfq?.warehouse_id) setWarehouseId(res.data.data.rfq.warehouse_id);
      })
      .catch((err) => setError(extractApiError(err).message));
    apiClient.get('/app/warehouses', { params: { status: 'ACTIVE', per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, [quotationId]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setError(null);
    try {
      const res = await apiClient.post(`/app/quotations/${quotationId}/purchase-order`, { delivery_warehouse_id: warehouseId, order_date: orderDate });
      navigate(`/app/purchase-orders/${res.data.data.id}`);
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      setError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  if (error && !quotation) return <ErrorState message={error} />;
  if (!quotation) return <LoadingState />;

  // Opened directly (old link / bookmark) for a quotation that already has a PO or is not the
  // selected one: no form — the backend would reject the request anyway.
  if (!quotation.can_create_purchase_order) {
    return (
      <div style={{ maxWidth: 820 }}>
        <BackButton fallbackTo="/app/quotations" label="← Back to Quotation" />
        <h1 style={{ fontSize: 22, marginBottom: 16 }}>Create Purchase Order</h1>
        <div className="card" style={{ fontSize: 13 }}>
          {quotation.purchase_order ? (
            <>
              A Purchase Order has already been created from this quotation:{' '}
              <Link to={`/app/purchase-orders/${quotation.purchase_order.id}`}>{quotation.purchase_order.po_number}</Link>.
            </>
          ) : (
            <>This quotation is {quotation.status} — only the selected quotation of an RFQ can be converted to a Purchase Order.</>
          )}
        </div>
      </div>
    );
  }

  const leadDays = quotation.lead_time_days;
  const validDate = /^\d{4}-\d{2}-\d{2}$/.test(orderDate);

  return (
    <div style={{ maxWidth: 820 }}>
      <BackButton fallbackTo="/app/quotations" label="← Back to Quotation" />
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Create Purchase Order</h1>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13, marginTop: 0 }}>
          Vendor: <strong>{quotation.partner?.name}</strong>
          {quotation.rfq?.rfq_number && (
            <>
              {' '}· RFQ <strong>{quotation.rfq.rfq_number}</strong>
            </>
          )}
          {' '}· Lead time: <strong>{leadDays !== null ? `${leadDays} day(s) after PO` : 'not provided'}</strong>
        </p>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 520 }}>
            <thead>
              <tr style={{ textAlign: 'left', background: '#f9fafb' }}>
                <th style={{ padding: 6 }}>Product</th>
                <th style={{ padding: 6, textAlign: 'right' }}>Quantity</th>
                <th style={{ padding: 6, textAlign: 'right' }}>Unit Price (vendor)</th>
                <th style={{ padding: 6, textAlign: 'right' }}>Line Total</th>
              </tr>
            </thead>
            <tbody>
              {(quotation.items ?? []).map((line) => (
                <tr key={line.id} style={{ borderTop: '1px solid #f3f4f6' }}>
                  <td style={{ padding: 6 }}>{line.product?.name ?? line.product_id}</td>
                  <td style={{ padding: 6, textAlign: 'right' }}>{formatQty(line.quantity)}</td>
                  <td style={{ padding: 6, textAlign: 'right' }}>{formatMoney(line.unit_price)}</td>
                  <td style={{ padding: 6, textAlign: 'right' }}>{formatMoney(line.line_total)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr style={{ borderTop: '2px solid #e5e7eb', fontWeight: 600 }}>
                <td style={{ padding: 6 }} colSpan={3}>
                  Total
                </td>
                <td style={{ padding: 6, textAlign: 'right' }}>{formatMoney(quotation.total)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, maxWidth: 560 }}>
        <FormField label="Delivery Warehouse" errors={errors.delivery_warehouse_id} required>
          <select aria-label="Delivery warehouse" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
            <option value="">Select…</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Order Date" errors={errors.order_date} required>
          <input type="date" aria-label="Order date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <p style={{ fontSize: 13, color: '#374151' }}>
        Expected Receipt Date: <strong>{validDate && leadDays !== null ? addDays(orderDate, leadDays) : '—'}</strong>
        <span style={{ color: '#6b7280' }}> (Order Date + vendor lead time; calculated by the system)</span>
      </p>
      <button className="btn-primary" disabled={submitting || !warehouseId || !validDate} onClick={submit}>
        {submitting ? 'Creating…' : 'Create Purchase Order'}
      </button>
    </div>
  );
}
