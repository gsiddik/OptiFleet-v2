import { useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { BackButton } from '../../../components/BackButton';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { useAuth } from '../../../auth/AuthContext';
import type { ContractAmendmentItem, ContractItem } from '../../../types';

const PRODUCT_TYPES = ['BUNDLE', 'MODULE', 'ADD_ON', 'CAPACITY', 'SETUP_FEE', 'OTHER'];
const FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'ANNUAL', 'CUSTOM'];

export function ContractDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [searchParams] = useSearchParams();
  const [contract, setContract] = useState<ContractItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [noteModal, setNoteModal] = useState<'approve' | 'reject' | 'terminate' | null>(null);
  const [showAmendModal, setShowAmendModal] = useState(false);
  const [showRenewModal, setShowRenewModal] = useState(false);

  // Reached from a Tenant Detail page's Contract tab -> Back must return
  // there (to the Contract tab specifically), not to Contract Management.
  const fromTenantId = searchParams.get('fromTenant');
  const backFallback = fromTenantId ? `/platform/tenants/${fromTenantId}?tab=Contract` : '/platform/contracts';
  const backLabel = fromTenantId ? '← Back to Tenant Contracts' : '← Back to Contract Management';

  function load() {
    apiClient
      .get(`/platform/contracts/${id}`)
      .then((res) => setContract(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(contract?.id, contract ? contract.contract_number : undefined);

  async function action(path: string, body?: Record<string, unknown>) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/platform/contracts/${id}${path}`, body);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !contract) return <ErrorState message={error} />;
  if (!contract) return <LoadingState />;

  const canSubmit = contract.status === 'DRAFT' && hasPermission('contract.submit');
  const canApprove = contract.status === 'PENDING_APPROVAL' && hasPermission('contract.approve');
  const canTerminate = ['ACTIVE', 'EXPIRING'].includes(contract.status) && hasPermission('contract.terminate');
  const canAmend = contract.status === 'ACTIVE' && hasPermission('contract.amend');
  const canRenew = ['ACTIVE', 'EXPIRING'].includes(contract.status) && hasPermission('contract.renew');

  return (
    <div>
      <BackButton fallbackTo={backFallback} label={backLabel} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {contract.contract_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({contract.tenant?.name})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusBadge status={contract.status} />
          {canSubmit && (
            <button className="btn-primary" disabled={busy} onClick={() => action('/submit')}>
              Submit for Approval
            </button>
          )}
          {canApprove && (
            <>
              <button className="btn-primary" disabled={busy} onClick={() => setNoteModal('approve')}>
                Approve
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => setNoteModal('reject')}>
                Reject
              </button>
            </>
          )}
          {canAmend && (
            <button className="btn-secondary" disabled={busy} onClick={() => setShowAmendModal(true)}>
              Create Amendment
            </button>
          )}
          {canRenew && (
            <button className="btn-secondary" disabled={busy} onClick={() => setShowRenewModal(true)}>
              Renew
            </button>
          )}
          {canTerminate && (
            <button className="btn-secondary" style={{ color: '#b91c1c' }} disabled={busy} onClick={() => setNoteModal('terminate')}>
              Terminate
            </button>
          )}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
        <SummaryCard label="Start Date" value={contract.start_date} />
        <SummaryCard label="End Date" value={contract.end_date} />
        <SummaryCard label="Billing Cycle" value={contract.billing_cycle} />
        <SummaryCard label="Total" value={`${contract.currency} ${Number(contract.total).toLocaleString()}`} />
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Line Items</h3>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px 8px' }}>Type</th>
              <th style={{ padding: '6px 8px' }}>Description</th>
              <th style={{ padding: '6px 8px' }}>Qty</th>
              <th style={{ padding: '6px 8px' }}>Unit Price</th>
              <th style={{ padding: '6px 8px' }}>Discount</th>
              <th style={{ padding: '6px 8px' }}>Tax</th>
              <th style={{ padding: '6px 8px' }}>Amount</th>
            </tr>
          </thead>
          <tbody>
            {(contract.items ?? []).map((it) => (
              <tr key={it.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '6px 8px' }}>
                  {it.product_type} {it.product_reference ? `(${it.product_reference})` : ''}
                </td>
                <td style={{ padding: '6px 8px' }}>{it.description}</td>
                <td style={{ padding: '6px 8px' }}>{it.quantity}</td>
                <td style={{ padding: '6px 8px' }}>{Number(it.unit_price).toLocaleString()}</td>
                <td style={{ padding: '6px 8px' }}>{Number(it.discount).toLocaleString()}</td>
                <td style={{ padding: '6px 8px' }}>{Number(it.tax).toLocaleString()}</td>
                <td style={{ padding: '6px 8px', fontWeight: 600 }}>{Number(it.final_amount).toLocaleString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ textAlign: 'right', marginTop: 10, fontSize: 13, color: '#374151' }}>
          Subtotal: {Number(contract.subtotal).toLocaleString()} &nbsp; Discount: {Number(contract.discount).toLocaleString()} &nbsp; Tax:{' '}
          {Number(contract.tax).toLocaleString()} &nbsp;{' '}
          <strong>Total: {contract.currency} {Number(contract.total).toLocaleString()}</strong>
        </div>
      </div>

      {contract.subscription && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Subscription</h3>
          <div style={{ display: 'flex', gap: 24, fontSize: 13, alignItems: 'center', flexWrap: 'wrap' }}>
            <StatusBadge status={contract.subscription.status} />
            <span>Next billing: {contract.subscription.next_billing_date}</span>
            <span>Grace period end: {contract.subscription.grace_period_end ?? '—'}</span>
          </div>
        </div>
      )}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Amendments</h3>
        {(contract.amendments ?? []).length === 0 && <p style={{ fontSize: 13, color: '#9ca3af' }}>No amendments.</p>}
        {(contract.amendments ?? []).map((a) => (
          <AmendmentCard key={a.id} contractId={contract.id} amendment={a} onChanged={load} />
        ))}
      </div>

      {noteModal && (
        <NoteModal
          title={noteModal === 'approve' ? 'Approve Contract' : noteModal === 'reject' ? 'Reject Contract' : 'Terminate Contract'}
          onClose={() => setNoteModal(null)}
          onSubmit={(note) => {
            const path = noteModal === 'approve' ? '/approve' : noteModal === 'reject' ? '/reject' : '/terminate';
            setNoteModal(null);
            action(path, { note });
          }}
        />
      )}

      {showAmendModal && (
        <CreateAmendmentModal
          onClose={() => setShowAmendModal(false)}
          onCreated={() => {
            setShowAmendModal(false);
            load();
          }}
          contractId={contract.id}
        />
      )}

      {showRenewModal && (
        <RenewModal
          contract={contract}
          onClose={() => setShowRenewModal(false)}
          onCreated={() => {
            setShowRenewModal(false);
            load();
          }}
        />
      )}
    </div>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="card" style={{ padding: 14 }}>
      <div style={{ fontSize: 12, color: '#9ca3af', marginBottom: 4 }}>{label}</div>
      <div style={{ fontSize: 16, fontWeight: 600 }}>{value}</div>
    </div>
  );
}

function NoteModal({ title, onClose, onSubmit }: { title: string; onClose: () => void; onSubmit: (note: string) => void }) {
  const [note, setNote] = useState('');
  return (
    <Modal open title={title} onClose={onClose}>
      <FormField label="Note (optional)">
        <textarea value={note} onChange={(e) => setNote(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" onClick={() => onSubmit(note)}>
          Confirm
        </button>
      </div>
    </Modal>
  );
}

function CreateAmendmentModal({ contractId, onClose, onCreated }: { contractId: string; onClose: () => void; onCreated: () => void }) {
  const [reason, setReason] = useState('');
  const [effectiveDate, setEffectiveDate] = useState(new Date().toISOString().slice(0, 10));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/platform/contracts/${contractId}/amendments`, { reason, effective_date: effectiveDate });
      onCreated();
    } catch (err) {
      setErrors(extractApiError(err).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="New Amendment" onClose={onClose}>
      <FormField label="Reason" errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label="Effective Date" errors={errors.effective_date} required>
        <input type="date" value={effectiveDate} onChange={(e) => setEffectiveDate(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Creating…' : 'Create Draft Amendment'}
        </button>
      </div>
    </Modal>
  );
}

function AmendmentCard({
  contractId,
  amendment,
  onChanged,
}: {
  contractId: string;
  amendment: ContractAmendmentItem;
  onChanged: () => void;
}) {
  const { hasPermission } = useAuth();
  const [showAddItem, setShowAddItem] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submitForApproval() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/platform/contracts/${contractId}/amendments/${amendment.id}/submit`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function decide(kind: 'approve' | 'reject') {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/platform/contracts/${contractId}/amendments/${amendment.id}/${kind}`, kind === 'reject' ? { note: '' } : undefined);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  const canEdit = amendment.status === 'DRAFT' && hasPermission('contract.amend');
  const canApprove = amendment.status === 'PENDING_APPROVAL' && hasPermission('contract.approve');

  return (
    <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 12, marginBottom: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
        <strong style={{ fontSize: 13 }}>Amendment #{amendment.amendment_number}</strong>
        <StatusBadge status={amendment.status} />
      </div>
      <div style={{ fontSize: 13, color: '#374151', marginBottom: 6 }}>{amendment.reason}</div>
      <div style={{ fontSize: 12, color: '#9ca3af', marginBottom: 8 }}>Effective: {amendment.effective_date}</div>
      {error && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 6 }}>{error}</div>}
      {(amendment.items ?? []).length > 0 && (
        <table style={{ width: '100%', fontSize: 12, marginBottom: 8, borderCollapse: 'collapse' }}>
          <tbody>
            {(amendment.items ?? []).map((it) => (
              <tr key={it.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '4px 6px' }}>{it.action}</td>
                <td style={{ padding: '4px 6px' }}>{it.description}</td>
                <td style={{ padding: '4px 6px' }}>{it.final_amount}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <div style={{ display: 'flex', gap: 8 }}>
        {canEdit && (
          <button className="btn-secondary" disabled={busy} onClick={() => setShowAddItem(true)}>
            + Add Item
          </button>
        )}
        {canEdit && (
          <button className="btn-primary" disabled={busy} onClick={submitForApproval}>
            Submit for Approval
          </button>
        )}
        {canApprove && (
          <>
            <button className="btn-primary" disabled={busy} onClick={() => decide('approve')}>
              Approve
            </button>
            <button className="btn-secondary" disabled={busy} onClick={() => decide('reject')}>
              Reject
            </button>
          </>
        )}
      </div>
      {showAddItem && (
        <AddAmendmentItemModal
          contractId={contractId}
          amendmentId={amendment.id}
          onClose={() => setShowAddItem(false)}
          onCreated={() => {
            setShowAddItem(false);
            onChanged();
          }}
        />
      )}
    </div>
  );
}

function AddAmendmentItemModal({
  contractId,
  amendmentId,
  onClose,
  onCreated,
}: {
  contractId: string;
  amendmentId: string;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [productType, setProductType] = useState('MODULE');
  const [productReference, setProductReference] = useState('');
  const [description, setDescription] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [unitPrice, setUnitPrice] = useState('0');
  const [billingFrequency, setBillingFrequency] = useState('MONTHLY');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/platform/contracts/${contractId}/amendments/${amendmentId}/items`, {
        product_type: productType,
        product_reference: productReference || null,
        description,
        quantity,
        unit_price: unitPrice,
        billing_frequency: billingFrequency,
      });
      onCreated();
    } catch (err) {
      setErrors(extractApiError(err).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Add Amendment Item" onClose={onClose}>
      <FormField label="Product Type" errors={errors.product_type} required>
        <select value={productType} onChange={(e) => setProductType(e.target.value)} style={inputStyle}>
          {PRODUCT_TYPES.map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Product Reference (code)" errors={errors.product_reference}>
        <input value={productReference} onChange={(e) => setProductReference(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Description" errors={errors.description} required>
        <input value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
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
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Quantity" errors={errors.quantity} required>
          <input type="number" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Unit Price" errors={errors.unit_price} required>
          <input type="number" value={unitPrice} onChange={(e) => setUnitPrice(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Adding…' : 'Add Item'}
        </button>
      </div>
    </Modal>
  );
}

function RenewModal({ contract, onClose, onCreated }: { contract: ContractItem; onClose: () => void; onCreated: () => void }) {
  const [endDate, setEndDate] = useState('');
  const [billingCycle, setBillingCycle] = useState(contract.billing_cycle);
  const [items, setItems] = useState(
    (contract.items ?? []).map((it) => ({
      product_type: it.product_type,
      product_reference: it.product_reference ?? '',
      description: it.description,
      quantity: it.quantity,
      unit_price: it.unit_price,
      discount: it.discount,
      tax_rate_percent: '0',
      billing_frequency: it.billing_frequency,
    }))
  );
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/platform/contracts/${contract.id}/renew`, {
        end_date: endDate,
        billing_cycle: billingCycle,
        items,
      });
      onCreated();
    } catch (err) {
      setErrors(extractApiError(err).errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={`Renew ${contract.contract_number}`} onClose={onClose} width={600}>
      <p style={{ fontSize: 13, color: '#6b7280' }}>
        Creates a new draft contract carrying over the current line items. Adjust the end date and items as needed, then submit the new
        contract for approval separately.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="New End Date" errors={errors.end_date} required>
          <input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} style={inputStyle} />
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
      </div>
      <h4 style={{ fontSize: 14, marginBottom: 8 }}>Items carried over from current contract</h4>
      {items.map((it, idx) => (
        <div key={idx} style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 8, alignItems: 'center', marginBottom: 6 }}>
          <span style={{ fontSize: 13 }}>{it.description}</span>
          <input
            type="number"
            value={it.quantity}
            onChange={(e) => setItems((prev) => prev.map((row, i) => (i === idx ? { ...row, quantity: e.target.value } : row)))}
            style={inputStyle}
          />
          <input
            type="number"
            value={it.unit_price}
            onChange={(e) => setItems((prev) => prev.map((row, i) => (i === idx ? { ...row, unit_price: e.target.value } : row)))}
            style={inputStyle}
          />
          <button className="btn-secondary" onClick={() => setItems((prev) => prev.filter((_, i) => i !== idx))}>
            Remove
          </button>
        </div>
      ))}
      {errors.items && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 8 }}>{errors.items.join(', ')}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !endDate} onClick={submit}>
          {submitting ? 'Creating…' : 'Create Renewal Draft'}
        </button>
      </div>
    </Modal>
  );
}
