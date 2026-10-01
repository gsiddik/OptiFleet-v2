import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { PartnerItem, ProductItem, WarrantyItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';

export function WarrantyListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<WarrantyItem>('/app/warranties', {}, reloadKey);

  const columns: Column<WarrantyItem>[] = [
    { key: 'coverage', header: 'Coverage', render: (w) => <Link to={`/app/warranties/${w.id}`}>{w.coverage_basis}</Link> },
    { key: 'product', header: 'Product', render: (w) => w.product?.name ?? '—' },
    { key: 'partner', header: 'Vendor', render: (w) => w.partner?.name ?? '—' },
    { key: 'duration', header: 'Duration', render: (w) => `${w.duration_months ?? '—'}mo / ${w.duration_km ?? '—'}km` },
    { key: 'starts_at', header: 'Starts', render: (w) => w.starts_at },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Warranties</h1>
      <Toolbar
        actions={
          hasPermission('warranty.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Warranty
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No warranties found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [products, setProducts] = useState<ProductItem[]>([]);
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [coverageBasis, setCoverageBasis] = useState('DATE');
  const [durationMonths, setDurationMonths] = useState('');
  const [durationKm, setDurationKm] = useState('');
  const [startsAt, setStartsAt] = useState('');
  const [productId, setProductId] = useState('');
  const [partnerId, setPartnerId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/products', { params: { per_page: 100 } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
    apiClient.get('/app/partners', { params: { per_page: 100 } }).then((res) => setPartners(res.data.data)).catch(() => setPartners([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/warranties', {
        coverage_basis: coverageBasis, duration_months: durationMonths || undefined, duration_km: durationKm || undefined,
        starts_at: startsAt, product_id: productId || undefined, partner_id: partnerId || undefined,
      });
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="New Warranty" onClose={onClose}>
      <FormField label="Coverage Basis" errors={errors.coverage_basis} required>
        <select value={coverageBasis} onChange={(e) => setCoverageBasis(e.target.value)} style={inputStyle}>
          {['DATE', 'MILEAGE', 'ENGINE_HOUR', 'COMBINATION'].map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Duration (months)" errors={errors.duration_months}>
        <NumericInput value={durationMonths} onChange={(e) => setDurationMonths(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Duration (km)" errors={errors.duration_km}>
        <NumericInput value={durationKm} onChange={(e) => setDurationKm(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Starts At" errors={errors.starts_at} required>
        <input type="date" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Product" errors={errors.product_id}>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">None</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Vendor" errors={errors.partner_id}>
        <select value={partnerId} onChange={(e) => setPartnerId(e.target.value)} style={inputStyle}>
          <option value="">None</option>
          {partners.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !startsAt} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
