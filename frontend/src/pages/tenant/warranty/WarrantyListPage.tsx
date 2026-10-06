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
import { t } from '../../../i18n/i18n';

export function WarrantyListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<WarrantyItem>('/app/warranties', {}, reloadKey);

  const columns: Column<WarrantyItem>[] = [
    { key: 'coverage', header: t('warranty.fields.coverage'), render: (w) => <Link to={`/app/warranties/${w.id}`}>{w.coverage_basis}</Link> },
    { key: 'product', header: t('common.fields.product'), render: (w) => w.product?.name ?? '—' },
    { key: 'partner', header: t('common.fields.vendor'), render: (w) => w.partner?.name ?? '—' },
    { key: 'duration', header: t('warranty.fields.duration'), render: (w) => `${w.duration_months ?? '—'}mo / ${w.duration_km ?? '—'}km` },
    { key: 'starts_at', header: t('warranty.fields.starts'), render: (w) => w.starts_at },
    { key: 'status', header: t('common.fields.status'), render: (w) => <StatusBadge status={w.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('warranty.titles.warranties')}</h1>
      <Toolbar
        actions={
          hasPermission('warranty.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('warranty.actions.newWarranty')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('warranty.empty.noWarrantiesFound')} />}
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
    <Modal open={open} title={t('warranty.modals.newWarranty')} onClose={onClose}>
      <FormField label={t('warranty.fields.coverageBasis')} errors={errors.coverage_basis} required>
        <select value={coverageBasis} onChange={(e) => setCoverageBasis(e.target.value)} style={inputStyle}>
          {['DATE', 'MILEAGE', 'ENGINE_HOUR', 'COMBINATION'].map((c) => (
            <option key={c} value={c}>
              {c}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={t('warranty.fields.durationMonths')} errors={errors.duration_months}>
        <NumericInput value={durationMonths} onChange={(e) => setDurationMonths(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('warranty.fields.durationKm')} errors={errors.duration_km}>
        <NumericInput value={durationKm} onChange={(e) => setDurationKm(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('warranty.fields.startsAt')} errors={errors.starts_at} required>
        <input type="date" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.product')} errors={errors.product_id}>
        <select value={productId} onChange={(e) => setProductId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.none')}</option>
          {products.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={t('common.fields.vendor')} errors={errors.partner_id}>
        <select value={partnerId} onChange={(e) => setPartnerId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.none')}</option>
          {partners.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !startsAt} onClick={submit}>
          {t('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}
