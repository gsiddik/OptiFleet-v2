import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import type { BundleItem, PricingItem, Tenant } from '../../../types';

const BILLING_CYCLES = [
  { value: 'MONTHLY', label: 'Monthly' },
  { value: 'QUARTERLY', label: 'Quarterly' },
  { value: 'SEMIANNUAL', label: 'Semi Annual' },
  { value: 'ANNUAL', label: 'Annual' },
  { value: 'CUSTOM', label: 'Custom' },
];
const ITEM_TYPES = ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER'] as const;
const PRICED_TYPES = new Set(['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY']);
type ItemType = (typeof ITEM_TYPES)[number];

interface DraftItem {
  product_type: ItemType;
  product_reference: string;
  description: string;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate_percent: string;
  billing_frequency: string;
  activePrice: string | null;
  priceStatus: 'idle' | 'loading' | 'ok' | 'error';
  priceError: string | null;
  resolvedKey: string | null;
}

function blankItem(): DraftItem {
  return {
    product_type: 'MODULE',
    product_reference: '',
    description: '',
    quantity: '1',
    unit_price: '0',
    discount: '0',
    tax_rate_percent: '0',
    billing_frequency: 'MONTHLY',
    activePrice: null,
    priceStatus: 'idle',
    priceError: null,
    resolvedKey: null,
  };
}

/**
 * Single reusable Contract Form (Section 9): used both by Contract
 * Management's "+ New Contract" and by a Tenant Detail page's Contract tab
 * "Add New Contract". When `lockedTenant` is set, the Tenant field is
 * shown read-only and the contract is created via the tenant-scoped
 * endpoint, which binds the tenant from the route rather than the payload
 * — the same rule the backend enforces regardless of what this form sends.
 */
export function ContractForm({
  open,
  onClose,
  onCreated,
  lockedTenant,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
  lockedTenant?: { id: string; name: string; code: string };
}) {
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [tenantId, setTenantId] = useState('');
  const [startDate, setStartDate] = useState(new Date().toISOString().slice(0, 10));
  const [endDate, setEndDate] = useState('');
  const [billingCycle, setBillingCycle] = useState('MONTHLY');
  const [paymentTermsDays, setPaymentTermsDays] = useState('30');
  const [gracePeriodDays, setGracePeriodDays] = useState('7');
  const [activationRequiresPayment, setActivationRequiresPayment] = useState(true);
  const [notes, setNotes] = useState('');
  const [items, setItems] = useState<DraftItem[]>([blankItem()]);
  const [bundleOptions, setBundleOptions] = useState<BundleItem[]>([]);
  const [pricingOptions, setPricingOptions] = useState<PricingItem[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const effectiveTenantId = lockedTenant?.id ?? tenantId;

  useEffect(() => {
    if (!open) return;
    if (!lockedTenant) {
      apiClient.get('/platform/tenants', { params: { per_page: 100 } }).then((res) => setTenants(res.data.data));
    }
    apiClient.get('/platform/bundles', { params: { active_only: 1 } }).then((res) => setBundleOptions(res.data.data));
    apiClient.get('/platform/pricing', { params: { active_only: 1 } }).then((res) => setPricingOptions(res.data.data));
  }, [open, lockedTenant]);

  function updateItem(idx: number, patch: Partial<DraftItem>) {
    setItems((prev) => prev.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  }

  function changeItemType(idx: number, nextType: ItemType) {
    // Product Reference never carries over across an Item Type change —
    // each type has its own reference domain (Section 10.2).
    updateItem(idx, {
      product_type: nextType,
      product_reference: '',
      activePrice: null,
      priceStatus: 'idle',
      priceError: null,
      resolvedKey: null,
      unit_price: PRICED_TYPES.has(nextType) ? '0' : items[idx].unit_price,
    });
  }

  function productReferenceOptions(type: ItemType): { value: string; label: string }[] {
    if (type === 'BUNDLE') {
      return bundleOptions.map((b) => ({ value: b.code, label: `${b.code} — ${b.name}` }));
    }
    if (type === 'MODULE' || type === 'ADD_ON' || type === 'CAPACITY') {
      const codes = new Map<string, string>();
      pricingOptions
        .filter((p) => p.priceable_type === type)
        .forEach((p) => codes.set(p.priceable_code, p.priceable_code));
      return Array.from(codes.keys()).map((code) => ({ value: code, label: code }));
    }
    return [];
  }

  // Auto-fill Unit Price from the Active Price whenever the combination
  // that determines it changes (Section 10.5) — always re-validated by the
  // backend at save time regardless of what this preview shows.
  useEffect(() => {
    if (!open || !effectiveTenantId) return;

    items.forEach((item, idx) => {
      if (!PRICED_TYPES.has(item.product_type) || !item.product_reference || !item.billing_frequency) return;

      const key = `${item.product_type}:${item.product_reference}:${item.billing_frequency}:${effectiveTenantId}`;
      if (item.resolvedKey === key) return;

      updateItem(idx, { priceStatus: 'loading', priceError: null, resolvedKey: key });
      apiClient
        .get('/platform/pricing/resolve', {
          params: {
            priceable_type: item.product_type,
            priceable_code: item.product_reference,
            billing_frequency: item.billing_frequency,
            tenant_id: effectiveTenantId,
          },
        })
        .then((res) => {
          updateItem(idx, {
            activePrice: res.data.data.amount,
            unit_price: res.data.data.amount,
            priceStatus: 'ok',
            priceError: null,
          });
        })
        .catch((err) => {
          updateItem(idx, { priceStatus: 'error', priceError: extractApiError(err).message, activePrice: null });
        });
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, effectiveTenantId, items.map((i) => `${i.product_type}:${i.product_reference}:${i.billing_frequency}`).join('|')]);

  function reset() {
    setTenantId('');
    setEndDate('');
    setNotes('');
    setItems([blankItem()]);
    setErrors({});
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    const payload = {
      tenant_id: lockedTenant ? undefined : tenantId,
      start_date: startDate,
      end_date: endDate,
      billing_cycle: billingCycle,
      payment_terms_days: Number(paymentTermsDays),
      grace_period_days: Number(gracePeriodDays),
      activation_requires_payment: activationRequiresPayment,
      notes: notes || null,
      items: items.map((it) => ({
        product_type: it.product_type,
        product_reference: PRICED_TYPES.has(it.product_type) ? it.product_reference || null : null,
        description: it.description,
        quantity: it.quantity,
        unit_price: it.unit_price,
        discount: it.discount,
        tax_rate_percent: it.tax_rate_percent,
        billing_frequency: it.billing_frequency,
      })),
    };

    try {
      const url = lockedTenant ? `/platform/tenants/${lockedTenant.id}/contracts` : '/platform/contracts';
      await apiClient.post(url, payload);
      reset();
      onCreated();
      onClose();
    } catch (err) {
      setErrors(extractApiError(err as ApiErrorShape).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = !!effectiveTenantId && !!endDate && !submitting;

  return (
    <Modal open={open} title="New Contract" onClose={onClose} width={760}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Tenant" errors={errors.tenant_id} required>
          {lockedTenant ? (
            <input value={`${lockedTenant.name} (${lockedTenant.code})`} disabled style={inputStyle} />
          ) : (
            <select value={tenantId} onChange={(e) => setTenantId(e.target.value)} style={inputStyle}>
              <option value="">Select tenant…</option>
              {tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.code})
                </option>
              ))}
            </select>
          )}
        </FormField>
        <FormField label="Billing Cycle" errors={errors.billing_cycle} required>
          <select value={billingCycle} onChange={(e) => setBillingCycle(e.target.value)} style={inputStyle}>
            {BILLING_CYCLES.map((f) => (
              <option key={f.value} value={f.value}>
                {f.label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Start Date" errors={errors.start_date} required>
          <input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="End Date" errors={errors.end_date} required>
          <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Payment Terms (days)" errors={errors.payment_terms_days}>
          <input type="number" min={0} value={paymentTermsDays} onChange={(e) => setPaymentTermsDays(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Grace Period (days)" errors={errors.grace_period_days}>
          <input type="number" min={0} value={gracePeriodDays} onChange={(e) => setGracePeriodDays(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, marginBottom: 14 }}>
        <input type="checkbox" checked={activationRequiresPayment} onChange={(e) => setActivationRequiresPayment(e.target.checked)} />
        Activation requires payment
      </label>
      <FormField label="Notes" errors={errors.notes}>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>

      <h4 style={{ fontSize: 14, marginBottom: 8 }}>Line Items</h4>
      {!effectiveTenantId && (
        <p style={{ fontSize: 12, color: '#a16207', marginTop: -4 }}>Select a tenant first — Active Price depends on the tenant.</p>
      )}
      {items.map((it, idx) => {
        const refOptions = productReferenceOptions(it.product_type);
        const isPriced = PRICED_TYPES.has(it.product_type);
        const itemErrors = (field: string) => errors[`items.${idx}.${field}`];

        return (
          <div key={idx} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 10, marginBottom: 8 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 8, marginBottom: 8, alignItems: 'end' }}>
              <FormField label="Item Type" errors={itemErrors('product_type')} required>
                <select value={it.product_type} onChange={(e) => changeItemType(idx, e.target.value as ItemType)} style={inputStyle}>
                  {ITEM_TYPES.map((p) => (
                    <option key={p} value={p}>
                      {p}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label="Product Reference" errors={itemErrors('product_reference')} required={isPriced}>
                {isPriced ? (
                  <select value={it.product_reference} onChange={(e) => updateItem(idx, { product_reference: e.target.value })} style={inputStyle}>
                    <option value="">Select code…</option>
                    {refOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input value="Not applicable" disabled style={inputStyle} />
                )}
              </FormField>
              <FormField label="Payment Frequency" errors={itemErrors('billing_frequency')} required>
                <select value={it.billing_frequency} onChange={(e) => updateItem(idx, { billing_frequency: e.target.value })} style={inputStyle}>
                  {BILLING_CYCLES.map((f) => (
                    <option key={f.value} value={f.value}>
                      {f.label}
                    </option>
                  ))}
                </select>
              </FormField>
              <button className="btn-secondary" onClick={() => setItems((prev) => prev.filter((_, i) => i !== idx))} disabled={items.length === 1}>
                Remove
              </button>
            </div>
            <FormField label="Description" errors={itemErrors('description')} required>
              <input value={it.description} onChange={(e) => updateItem(idx, { description: e.target.value })} style={inputStyle} />
            </FormField>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 8 }}>
              <FormField label="Qty" errors={itemErrors('quantity')} required>
                <input type="number" min={0.01} step="0.01" value={it.quantity} onChange={(e) => updateItem(idx, { quantity: e.target.value })} style={inputStyle} />
              </FormField>
              <FormField label="Unit Price" errors={itemErrors('unit_price')} required>
                <input type="number" min={0} value={it.unit_price} onChange={(e) => updateItem(idx, { unit_price: e.target.value })} style={inputStyle} />
              </FormField>
              <FormField label="Discount">
                <input type="number" min={0} value={it.discount} onChange={(e) => updateItem(idx, { discount: e.target.value })} style={inputStyle} />
              </FormField>
              <FormField label="Tax %">
                <input type="number" min={0} max={100} value={it.tax_rate_percent} onChange={(e) => updateItem(idx, { tax_rate_percent: e.target.value })} style={inputStyle} />
              </FormField>
            </div>
            {isPriced && it.priceStatus === 'loading' && <p style={{ fontSize: 12, color: '#6b7280' }}>Looking up Active Price…</p>}
            {isPriced && it.priceStatus === 'ok' && it.activePrice && (
              <p style={{ fontSize: 12, color: '#059669' }}>Active Price: {Number(it.activePrice).toLocaleString()} (unit price may not go below this)</p>
            )}
            {isPriced && it.priceStatus === 'error' && it.priceError && <p style={{ fontSize: 12, color: '#b91c1c' }}>{it.priceError}</p>}
          </div>
        );
      })}
      {errors.items && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 8 }}>{errors.items.join(', ')}</div>}
      <button className="btn-secondary" onClick={() => setItems((prev) => [...prev, blankItem()])}>
        + Add Item
      </button>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={!canSubmit} onClick={submit}>
          {submitting ? 'Creating…' : 'Create Draft Contract'}
        </button>
      </div>
    </Modal>
  );
}
