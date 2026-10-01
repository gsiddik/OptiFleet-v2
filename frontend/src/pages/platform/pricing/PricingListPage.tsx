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
    { key: 'priceable_type', header: 'Type', render: (p) => p.priceable_type },
    { key: 'priceable_code', header: 'Code', render: (p) => p.priceable_code },
    { key: 'pricing_method', header: 'Method', render: (p) => p.pricing_method },
    { key: 'billing_frequency', header: 'Frequency', render: (p) => p.billing_frequency },
    {
      key: 'active_amount',
      header: 'Active Price',
      render: (p) => {
        const active = p.versions?.find((v) => v.status === 'ACTIVE');
        return active ? `${p.currency} ${Number(active.amount).toLocaleString()}` : '—';
      },
    },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
    {
      key: 'actions',
      header: '',
      render: (p) => (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {hasPermission('pricing.publish') && (
            <button className="btn-link" onClick={() => setVersioning(p)}>
              New Version
            </button>
          )}
          {p.status === 'ACTIVE' && hasPermission('pricing.deactivate') && (
            <button className="btn-link" onClick={() => toggleActive(p)}>
              Deactivate
            </button>
          )}
          {p.status === 'ARCHIVED' && hasPermission('pricing.activate') && (
            <button className="btn-link" onClick={() => toggleActive(p)}>
              Reactivate
            </button>
          )}
          {hasPermission('pricing.delete') && (
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => deletePricing(p)}>
              Delete
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
        ? `Deactivate pricing ${p.priceable_type} ${p.priceable_code}? It will no longer be selectable for new contracts; existing contracts are unaffected.`
        : `Reactivate pricing ${p.priceable_type} ${p.priceable_code} so it can be selected for new contracts again?`,
    );
    if (!confirmed) return;
    await apiClient.post(`/platform/pricing/${p.id}/${action}`);
    setReloadKey((k) => k + 1);
  }

  async function deletePricing(p: PricingItem) {
    const confirmed = window.confirm(
      `Delete pricing ${p.priceable_type} ${p.priceable_code}? It will be hidden from selection but existing contracts referencing it keep working — this cannot be undone from here.`,
    );
    if (!confirmed) return;
    await apiClient.delete(`/platform/pricing/${p.id}`);
    setReloadKey((k) => k + 1);
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Pricing</h1>
      <Toolbar
        actions={
          hasPermission('pricing.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Pricing
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No pricing configured yet." />}
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
    <Modal open={open} title="New Pricing" onClose={onClose}>
      <FormField label="Priceable Type" errors={errors.priceable_type} required>
        <select value={priceableType} onChange={(e) => setPriceableType(e.target.value)} style={inputStyle}>
          {PRICEABLE_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Code (module/bundle code or capacity resource type)" errors={errors.priceable_code} required>
        <input value={priceableCode} onChange={(e) => setPriceableCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Pricing Method" errors={errors.pricing_method} required>
        <select value={pricingMethod} onChange={(e) => setPricingMethod(e.target.value)} style={inputStyle}>
          {PRICING_METHODS.map((m) => (
            <option key={m} value={m}>
              {m}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Billing Frequency" errors={errors.billing_frequency} required>
        <select value={billingFrequency} onChange={(e) => setBillingFrequency(e.target.value)} style={inputStyle}>
          {FREQUENCIES.map((f) => (
            <option key={f} value={f}>
              {f}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Amount (IDR)" errors={errors.amount} required>
        <NumericInput value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Effective From" errors={errors.effective_from} required>
        <input type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Creating…' : 'Create'}
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
    <Modal open title={`New Price Version — ${pricing.priceable_code}`} onClose={onClose}>
      <FormField label="Amount (IDR)" errors={errors.amount} required>
        <NumericInput value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Effective From" errors={errors.effective_from} required>
        <input type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Publishing…' : 'Publish Version'}
        </button>
      </div>
    </Modal>
  );
}
