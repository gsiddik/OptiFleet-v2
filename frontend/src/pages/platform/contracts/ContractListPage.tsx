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
import type { ContractItem, ContractItemRow, Tenant } from '../../../types';

const TABS: { label: string; status: string }[] = [
  { label: 'All', status: '' },
  { label: 'Draft', status: 'DRAFT' },
  { label: 'Pending Approval', status: 'PENDING_APPROVAL' },
  { label: 'Active', status: 'ACTIVE' },
  { label: 'Expiring', status: 'EXPIRING' },
  { label: 'Expired', status: 'EXPIRED' },
  { label: 'Rejected', status: 'REJECTED' },
  { label: 'Terminated', status: 'TERMINATED' },
];

const PRODUCT_TYPES = ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER'];
const FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL', 'CUSTOM'];

export function ContractListPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<ContractItem>('/platform/contracts', { status: tab || undefined }, reloadKey);

  const columns: Column<ContractItem>[] = [
    { key: 'contract_number', header: 'Contract #', render: (c) => <Link to={`/platform/contracts/${c.id}`}>{c.contract_number}</Link> },
    { key: 'tenant', header: 'Tenant', render: (c) => c.tenant?.name ?? '—' },
    { key: 'billing_cycle', header: 'Cycle', render: (c) => c.billing_cycle },
    { key: 'total', header: 'Total', render: (c) => `${c.currency} ${Number(c.total).toLocaleString()}` },
    { key: 'start_date', header: 'Start', render: (c) => c.start_date },
    { key: 'end_date', header: 'End', render: (c) => c.end_date },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Contracts</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map((t) => (
          <button
            key={t.label}
            onClick={() => setTab(t.status)}
            className={tab === t.status ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '6px 12px', fontSize: 13 }}
          >
            {t.label}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('contract.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Contract
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No contracts found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateContractModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

type DraftItem = Omit<ContractItemRow, 'id' | 'final_amount'> & { tax_rate_percent: string };

function blankItem(): DraftItem {
  return {
    product_type: 'MODULE',
    product_reference: '',
    description: '',
    quantity: '1',
    unit_price: '0',
    discount: '0',
    tax: '0',
    tax_rate_percent: '0',
    billing_frequency: 'MONTHLY',
    valid_from: '',
    valid_until: null,
  };
}

function CreateContractModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
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
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/platform/tenants', { params: { per_page: 100 } }).then((res) => setTenants(res.data.data));
  }, [open]);

  function updateItem(idx: number, patch: Partial<DraftItem>) {
    setItems((prev) => prev.map((it, i) => (i === idx ? { ...it, ...patch } : it)));
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/platform/contracts', {
        tenant_id: tenantId,
        start_date: startDate,
        end_date: endDate,
        billing_cycle: billingCycle,
        payment_terms_days: Number(paymentTermsDays),
        grace_period_days: Number(gracePeriodDays),
        activation_requires_payment: activationRequiresPayment,
        notes: notes || null,
        items: items.map((it) => ({
          product_type: it.product_type,
          product_reference: it.product_reference || null,
          description: it.description,
          quantity: it.quantity,
          unit_price: it.unit_price,
          discount: it.discount,
          tax_rate_percent: it.tax_rate_percent,
          billing_frequency: it.billing_frequency,
        })),
      });
      setItems([blankItem()]);
      setTenantId('');
      setEndDate('');
      setNotes('');
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
    <Modal open={open} title="New Contract" onClose={onClose} width={720}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Tenant" errors={errors.tenant_id} required>
          <select value={tenantId} onChange={(e) => setTenantId(e.target.value)} style={inputStyle}>
            <option value="">Select tenant…</option>
            {tenants.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} ({t.code})
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Billing Cycle" errors={errors.billing_cycle} required>
          <select value={billingCycle} onChange={(e) => setBillingCycle(e.target.value)} style={inputStyle}>
            {FREQUENCIES.map((f) => (
              <option key={f} value={f}>
                {f}
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
          <input type="number" value={paymentTermsDays} onChange={(e) => setPaymentTermsDays(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Grace Period (days)" errors={errors.grace_period_days}>
          <input type="number" value={gracePeriodDays} onChange={(e) => setGracePeriodDays(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, marginBottom: 14 }}>
        <input type="checkbox" checked={activationRequiresPayment} onChange={(e) => setActivationRequiresPayment(e.target.checked)} />
        Activation requires payment
      </label>
      <FormField label="Notes" errors={errors.notes}>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>

      <h4 style={{ fontSize: 14, marginBottom: 8 }}>Line Items</h4>
      {items.map((it, idx) => (
        <div key={idx} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 10, marginBottom: 8 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 8, marginBottom: 8 }}>
            <select value={it.product_type} onChange={(e) => updateItem(idx, { product_type: e.target.value as DraftItem['product_type'] })} style={inputStyle}>
              {PRODUCT_TYPES.map((p) => (
                <option key={p} value={p}>
                  {p}
                </option>
              ))}
            </select>
            <input
              placeholder="Product reference (code)"
              value={it.product_reference ?? ''}
              onChange={(e) => updateItem(idx, { product_reference: e.target.value })}
              style={inputStyle}
            />
            <select value={it.billing_frequency} onChange={(e) => updateItem(idx, { billing_frequency: e.target.value })} style={inputStyle}>
              {FREQUENCIES.map((f) => (
                <option key={f} value={f}>
                  {f}
                </option>
              ))}
            </select>
            <button
              className="btn-secondary"
              onClick={() => setItems((prev) => prev.filter((_, i) => i !== idx))}
              disabled={items.length === 1}
            >
              Remove
            </button>
          </div>
          <input
            placeholder="Description"
            value={it.description}
            onChange={(e) => updateItem(idx, { description: e.target.value })}
            style={{ ...inputStyle, marginBottom: 8 }}
          />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 8 }}>
            <input type="number" placeholder="Qty" value={it.quantity} onChange={(e) => updateItem(idx, { quantity: e.target.value })} style={inputStyle} />
            <input type="number" placeholder="Unit Price" value={it.unit_price} onChange={(e) => updateItem(idx, { unit_price: e.target.value })} style={inputStyle} />
            <input type="number" placeholder="Discount" value={it.discount} onChange={(e) => updateItem(idx, { discount: e.target.value })} style={inputStyle} />
            <input
              type="number"
              placeholder="Tax %"
              value={it.tax_rate_percent}
              onChange={(e) => updateItem(idx, { tax_rate_percent: e.target.value })}
              style={inputStyle}
            />
          </div>
        </div>
      ))}
      {errors.items && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 8 }}>{errors.items.join(', ')}</div>}
      <button className="btn-secondary" onClick={() => setItems((prev) => [...prev, blankItem()])}>
        + Add Item
      </button>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !tenantId} onClick={submit}>
          {submitting ? 'Creating…' : 'Create Draft Contract'}
        </button>
      </div>
    </Modal>
  );
}
