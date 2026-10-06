import { useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { PricingItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { t as tt } from '../../../i18n/i18n';

const PRICING_METHODS = ['FLAT', 'PER_VEHICLE', 'PER_USER', 'PER_BRANCH', 'PER_WORKSHOP', 'PER_WAREHOUSE', 'TIERED', 'CUSTOM'];
const FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL', 'CUSTOM'];
const PRICEABLE_TYPES = ['MODULE', 'BUNDLE', 'ADD_ON', 'CAPACITY'];

export function PricingListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [versioning, setVersioning] = useState<PricingItem | null>(null);
  const { data, loading, error } = useApiList<PricingItem>('/platform/pricing', {}, reloadKey);

  const columns: Column<PricingItem>[] = [
    { key: 'priceable_type', header: tt('common.fields.type'), render: (p) => p.priceable_type },
    { key: 'priceable_code', header: tt('common.fields.code'), render: (p) => p.priceable_code },
    { key: 'pricing_method', header: tt('common.fields.method'), render: (p) => p.pricing_method },
    { key: 'billing_frequency', header: tt('platform.pricing.fields.frequency'), render: (p) => p.billing_frequency },
    {
      key: 'active_amount',
      header: tt('platform.pricing.fields.activePrice'),
      render: (p) => {
        const active = p.versions?.find((v) => v.status === 'ACTIVE');
        return active ? `${p.currency} ${formatMoney(active.amount)}` : '—';
      },
    },
    { key: 'status', header: tt('common.fields.status'), render: (p) => <StatusBadge status={p.status} /> },
    {
      key: 'actions',
      header: '',
      render: (p) => (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {hasPermission('pricing.publish') && (
            <button className="btn-link" onClick={() => setVersioning(p)}>
              {tt('platform.pricing.actions.newVersion')}
            </button>
          )}
          {p.status === 'ACTIVE' && hasPermission('pricing.deactivate') && (
            <button className="btn-link" onClick={() => toggleActive(p)}>
              {tt('common.actions.deactivate')}
            </button>
          )}
          {p.status === 'ARCHIVED' && hasPermission('pricing.activate') && (
            <button className="btn-link" onClick={() => toggleActive(p)}>
              {tt('common.actions.reactivate')}
            </button>
          )}
          {hasPermission('pricing.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => deletePricing(p)}>
              {tt('common.actions.delete')}
            </button>
          )}
        </div>
      ),
    },
  ];

  async function toggleActive(p: PricingItem) {
    const action = p.status === 'ACTIVE' ? 'deactivate' : 'reactivate';
    const confirmed = window.confirm(
      action === 'deactivate'
        ? tt('platform.pricing.confirm.deactivatePricingPriceableTypePriceableCode', { priceable_type: p.priceable_type, priceable_code: p.priceable_code })
        : tt('platform.pricing.confirm.reactivatePricingPriceableTypePriceableCode', { priceable_type: p.priceable_type, priceable_code: p.priceable_code }),
    );
    if (!confirmed) return;
    await apiClient.post(`/platform/pricing/${p.id}/${action}`);
    setReloadKey((k) => k + 1);
  }

  async function deletePricing(p: PricingItem) {
    const confirmed = window.confirm(
      tt('platform.pricing.confirm.deletePricingPriceableTypePriceableCode', { priceable_type: p.priceable_type, priceable_code: p.priceable_code }),
    );
    if (!confirmed) return;
    await apiClient.delete(`/platform/pricing/${p.id}`);
    setReloadKey((k) => k + 1);
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('platform.pricing.titles.pricing')}</h1>
      <Toolbar
        actions={
          hasPermission('pricing.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('platform.pricing.actions.newPricing')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('platform.pricing.empty.noPricingConfiguredYet')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreatePricingModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
      {versioning && (
        <VersionModal
          pricing={versioning}
          onClose={() => setVersioning(null)}
          onCreated={() => {
            setVersioning(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function CreatePricingModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [priceableType, setPriceableType] = useState('MODULE');
  const [priceableCode, setPriceableCode] = useState('');
  const [pricingMethod, setPricingMethod] = useState('FLAT');
  const [billingFrequency, setBillingFrequency] = useState('MONTHLY');
  const [amount, setAmount] = useState('');
  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/platform/pricing', {
        priceable_type: priceableType,
        priceable_code: priceableCode.toUpperCase(),
        pricing_method: pricingMethod,
        billing_frequency: billingFrequency,
        amount,
        effective_from: effectiveFrom,
      });
      setPriceableCode('');
      setAmount('');
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
    <Modal open={open} title={tt('platform.pricing.modals.newPricing')} onClose={onClose}>
      <FormField label={tt('platform.pricing.fields.priceableType')} errors={errors.priceable_type} required>
        <select value={priceableType} onChange={(e) => setPriceableType(e.target.value)} style={inputStyle}>
          {PRICEABLE_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('platform.pricing.fields.codeModuleBundleCodeCapacityResource')} errors={errors.priceable_code} required>
        <input value={priceableCode} onChange={(e) => setPriceableCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('platform.pricing.fields.pricingMethod')} errors={errors.pricing_method} required>
        <select value={pricingMethod} onChange={(e) => setPricingMethod(e.target.value)} style={inputStyle}>
          {PRICING_METHODS.map((m) => (
            <option key={m} value={m}>
              {m}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('platform.contracts.fields.billingFrequency')} errors={errors.billing_frequency} required>
        <select value={billingFrequency} onChange={(e) => setBillingFrequency(e.target.value)} style={inputStyle}>
          {FREQUENCIES.map((f) => (
            <option key={f} value={f}>
              {f}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('platform.pricing.fields.amountIdr')} errors={errors.amount} required>
        <NumericInput value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('platform.pricing.fields.effectiveFrom')} errors={errors.effective_from} required>
        <input type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('common.actions.creating') : tt('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}

function VersionModal({ pricing, onClose, onCreated }: { pricing: PricingItem; onClose: () => void; onCreated: () => void }) {
  const [amount, setAmount] = useState('');
  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/platform/pricing/${pricing.id}/versions`, { amount, effective_from: effectiveFrom });
      onCreated();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={tt('platform.pricing.modals.newPriceVersionPriceableCode', { priceable_code: pricing.priceable_code })} onClose={onClose}>
      <FormField label={tt('platform.pricing.fields.amountIdr')} errors={errors.amount} required>
        <NumericInput value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('platform.pricing.fields.effectiveFrom')} errors={errors.effective_from} required>
        <input type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? tt('platform.bundles.actions.publishing') : tt('platform.pricing.actions.publishVersion')}
        </button>
      </div>
    </Modal>
  );
}
