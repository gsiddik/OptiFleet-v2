import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState } from '../../../components/States';
import type { VendorQuotationItem, Warehouse } from '../../../types';

export function CreatePurchaseOrderFromQuotationPage() {
  const { quotationId } = useParams<{ quotationId: string }>();
  const navigate = useNavigate();
  const [quotation, setQuotation] = useState<VendorQuotationItem | null>(null);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiClient.get(`/app/quotations/${quotationId}`).then((res) => setQuotation(res.data.data)).catch((err) => setError(extractApiError(err).message));
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, [quotationId]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const res = await apiClient.post(`/app/quotations/${quotationId}/purchase-order`, { delivery_warehouse_id: warehouseId });
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

  return (
    <div style={{ maxWidth: 480 }}>
      <BackButton fallbackTo="/app/quotations" label="← Back to Quotation" />
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Create Purchase Order</h1>
      {error && <ErrorState message={error} />}
      {quotation && (
        <div className="card" style={{ marginBottom: 16 }}>
          <p style={{ fontSize: 13 }}>
            Vendor: <strong>{quotation.partner?.name}</strong> — Total: <strong>{quotation.total}</strong>
          </p>
        </div>
      )}
      <FormField label="Delivery Warehouse" errors={errors.delivery_warehouse_id} required>
        <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <button className="btn-primary" disabled={submitting || !warehouseId} onClick={submit}>
        Create Purchase Order
      </button>
    </div>
  );
}
