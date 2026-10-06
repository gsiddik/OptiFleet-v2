import { useEffect, useMemo, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import type { BundleItem, ContractItem, PricingItem, Tenant } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { labelText, t as tt } from '../../../i18n/i18n';

const BILLING_CYCLES = [
  { value: 'MONTHLY', label: 'Monthly', labelKey: 'platform.contracts.fields.monthly' },
  { value: 'QUARTERLY', label: 'Quarterly', labelKey: 'platform.contracts.fields.quarterly' },
  { value: 'SEMIANNUAL', label: 'Semi Annual', labelKey: 'platform.contracts.fields.semiAnnual' },
  { value: 'ANNUAL', label: 'Annual', labelKey: 'platform.contracts.fields.annual' },
  { value: 'CUSTOM', label: 'Custom', labelKey: 'platform.contracts.fields.custom' },
];
const ITEM_TYPES = ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER'] as const;
const PRICED_TYPES = new Set(['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY']);
type ItemType = (typeof ITEM_TYPES)[number];

/** The shown name of a billing cycle code (MONTHLY → Monthly / Bulanan); unknown codes as stored. */
export function billingCycleLabel(code: string | null | undefined): string {
  const cycle = BILLING_CYCLES.find((c) => c.value === code);
  return cycle ? labelText(cycle) : (code ?? '—');
}

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
 * When `contract` is set, the form edits that DRAFT contract (PUT, `contract.update`):
 * its tenant stays fixed and every item is re-priced by the backend exactly as on create.
 */
export function ContractForm({
  open,
  onClose,
  onCreated,
  lockedTenant: lockedTenantProp,
  contract,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
  lockedTenant?: { id: string; name: string; code: string };
  contract?: ContractItem;
}) {
  const lockedTenant = useMemo(
    () =>
      lockedTenantProp ??
      (contract ? { id: contract.tenant_id, name: contract.tenant?.name ?? contract.tenant_id, code: contract.tenant?.code ?? '' } : undefined),
    [lockedTenantProp, contract],
  );
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
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const effectiveTenantId = lockedTenant?.id ?? tenantId;

  useEffect(() => {
    if (!open || !contract) return;
    setStartDate(contract.start_date.slice(0, 10));
    setEndDate(contract.end_date.slice(0, 10));
    setBillingCycle(contract.billing_cycle);
    setPaymentTermsDays(String(contract.payment_terms_days ?? 0));
    setGracePeriodDays(String(contract.grace_period_days ?? 0));
    setActivationRequiresPayment(contract.activation_requires_payment);
    setNotes(contract.notes ?? '');
    setItems(
      (contract.items ?? []).map((row) => {
        const base = Number(row.unit_price) * Number(row.quantity) - Number(row.discount);
        const rate = base > 0 ? Math.round((Number(row.tax) / base) * 10000) / 100 : 0;
        return {
          ...blankItem(),
          product_type: row.product_type,
          product_reference: row.product_reference ?? '',
          description: row.description,
          quantity: String(Number(row.quantity)),
          unit_price: row.unit_price,
          discount: row.discount,
          tax_rate_percent: String(rate),
          billing_frequency: row.billing_frequency,
          // Keep the contract's frozen unit price; the backend still rejects one below today's Active Price.
          resolvedKey: `${row.product_type}:${row.product_reference ?? ''}:${row.billing_frequency}:${contract.tenant_id}`,
        };
      }),
    );
  }, [open, contract]);

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
    setFormError(null);
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFormError(null);
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
      if (contract) {
        await apiClient.put(`/platform/contracts/${contract.id}`, payload);
      } else {
        const url = lockedTenant ? `/platform/tenants/${lockedTenant.id}/contracts` : '/platform/contracts';
        await apiClient.post(url, payload);
      }
      reset();
      onCreated();
      onClose();
    } catch (err) {
      const apiError = extractApiError(err as ApiErrorShape);
      setErrors(apiError.errors ?? {});
      setFormError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = !!effectiveTenantId && !!endDate && !submitting;

  return (
    <Modal open={open} title={contract ? tt('platform.contracts.modals.editContractContractNumber', { contract_number: contract.contract_number }) : tt('platform.contracts.modals.newContract')} onClose={onClose} width={760}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={tt('common.fields.tenant')} errors={errors.tenant_id} required>
          {lockedTenant ? (
            <input value={`${lockedTenant.name} (${lockedTenant.code})`} disabled style={inputStyle} />
          ) : (
            <select value={tenantId} onChange={(e) => setTenantId(e.target.value)} style={inputStyle}>
              <option value="">{tt('platform.contracts.fields.selectTenant')}</option>
              {tenants.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.code})
                </option>
              ))}
            </select>
          )}
        </FormField>
        <FormField label={tt('platform.contracts.fields.billingCycle')} errors={errors.billing_cycle} required>
          <select value={billingCycle} onChange={(e) => setBillingCycle(e.target.value)} style={inputStyle}>
            {BILLING_CYCLES.map((f) => (
              <option key={f.value} value={f.value}>
                {labelText(f)}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('platform.contracts.fields.startDate')} errors={errors.start_date} required>
          <input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('platform.contracts.fields.endDate')} errors={errors.end_date} required>
          <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('platform.contracts.fields.paymentTermsDays')} errors={errors.payment_terms_days}>
          <NumericInput min={0} value={paymentTermsDays} onChange={(e) => setPaymentTermsDays(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('platform.contracts.fields.gracePeriodDays')} errors={errors.grace_period_days}>
          <NumericInput min={0} value={gracePeriodDays} onChange={(e) => setGracePeriodDays(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, marginBottom: 14 }}>
        <input type="checkbox" checked={activationRequiresPayment} onChange={(e) => setActivationRequiresPayment(e.target.checked)} />
        {tt('platform.contracts.fields.activationRequiresPayment')}
      </label>
      <FormField label={tt('common.fields.notes')} errors={errors.notes}>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>

      <h4 style={{ fontSize: 14, marginBottom: 8 }}>{tt('common.sections.lineItems')}</h4>
      {!effectiveTenantId && (
        <p style={{ fontSize: 12, color: '#a16207', marginTop: -4 }}>{tt('platform.contracts.help.selectTenantFirstActivePriceDepends')}</p>
      )}
      {items.map((it, idx) => {
        const refOptions = productReferenceOptions(it.product_type);
        const isPriced = PRICED_TYPES.has(it.product_type);
        const itemErrors = (field: string) => errors[`items.${idx}.${field}`];

        return (
          <div key={idx} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 10, marginBottom: 8 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 8, marginBottom: 8, alignItems: 'end' }}>
              <FormField label={tt('common.fields.itemType')} errors={itemErrors('product_type')} required>
                <select value={it.product_type} onChange={(e) => changeItemType(idx, e.target.value as ItemType)} style={inputStyle}>
                  {ITEM_TYPES.map((p) => (
                    <option key={p} value={p}>
                      {p}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label={tt('platform.contracts.fields.productReference')} errors={itemErrors('product_reference')} required={isPriced}>
                {isPriced ? (
                  <select value={it.product_reference} onChange={(e) => updateItem(idx, { product_reference: e.target.value })} style={inputStyle}>
                    <option value="">{tt('platform.contracts.fields.selectCode')}</option>
                    {refOptions.map((o) => (
                      <option key={o.value} value={o.value}>
                        {labelText(o)}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input value="Not applicable" disabled style={inputStyle} />
                )}
              </FormField>
              <FormField label={tt('platform.contracts.fields.paymentFrequency')} errors={itemErrors('billing_frequency')} required>
                <select value={it.billing_frequency} onChange={(e) => updateItem(idx, { billing_frequency: e.target.value })} style={inputStyle}>
                  {BILLING_CYCLES.map((f) => (
                    <option key={f.value} value={f.value}>
                      {labelText(f)}
                    </option>
                  ))}
                </select>
              </FormField>
              <button className="btn-secondary" onClick={() => setItems((prev) => prev.filter((_, i) => i !== idx))} disabled={items.length === 1}>
                {tt('common.actions.remove')}
              </button>
            </div>
            <FormField label={tt('common.fields.description')} errors={itemErrors('description')} required>
              <input value={it.description} onChange={(e) => updateItem(idx, { description: e.target.value })} style={inputStyle} />
            </FormField>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 8 }}>
              <FormField label={tt('common.fields.qty')} errors={itemErrors('quantity')} required>
                <NumericInput min={0.01} step="0.01" value={it.quantity} onChange={(e) => updateItem(idx, { quantity: e.target.value })} style={inputStyle} />
              </FormField>
              <FormField label={tt('common.fields.unitPrice')} errors={itemErrors('unit_price')} required>
                <NumericInput min={0} value={it.unit_price} onChange={(e) => updateItem(idx, { unit_price: e.target.value })} style={inputStyle} />
              </FormField>
              <FormField label={tt('common.fields.discount')}>
                <NumericInput min={0} value={it.discount} onChange={(e) => updateItem(idx, { discount: e.target.value })} style={inputStyle} />
              </FormField>
              <FormField label={tt('platform.contracts.fields.taxPercent')}>
                <NumericInput min={0} max={100} value={it.tax_rate_percent} onChange={(e) => updateItem(idx, { tax_rate_percent: e.target.value })} style={inputStyle} />
              </FormField>
            </div>
            {isPriced && it.priceStatus === 'loading' && <p style={{ fontSize: 12, color: '#6b7280' }}>{tt('platform.contracts.help.lookingUpActivePrice')}</p>}
            {isPriced && it.priceStatus === 'ok' && it.activePrice && (
              <p style={{ fontSize: 12, color: '#059669' }}>{tt('platform.contracts.help.activePriceFloor', { price: formatMoney(it.activePrice) })}</p>
            )}
            {isPriced && it.priceStatus === 'error' && it.priceError && <p style={{ fontSize: 12, color: '#b91c1c' }}>{it.priceError}</p>}
          </div>
        );
      })}
      {errors.items && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 8 }}>{errors.items.join(', ')}</div>}
      <button className="btn-secondary" onClick={() => setItems((prev) => [...prev, blankItem()])}>
        {tt('platform.contracts.actions.addItem')}
      </button>

      {formError && (
        <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 10, borderRadius: 6, fontSize: 13, marginTop: 12 }} role="alert">
          {formError}
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button
          className="btn-secondary"
          onClick={() => {
            setFormError(null);
            onClose();
          }}
        >
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={!canSubmit} onClick={submit}>
          {contract ? (submitting ? tt('common.actions.saving') : tt('platform.contracts.actions.saveDraft')) : submitting ? tt('common.actions.creating') : tt('platform.contracts.actions.createDraftContract')}
        </button>
      </div>
    </Modal>
  );
}
