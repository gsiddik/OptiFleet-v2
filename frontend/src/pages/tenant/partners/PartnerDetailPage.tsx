import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { PartnerItem } from '../../../types';

export function PartnerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [partner, setPartner] = useState<PartnerItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);

  function load() {
    apiClient
      .get(`/app/partners/${id}`)
      .then((res) => setPartner(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  if (error && !partner) return <ErrorState message={error} />;
  if (!partner) return <LoadingState />;

  const perf = partner.performance;

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {partner.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({partner.code})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={partner.status} />
          {hasPermission('partner.manage') && (
            <button className="btn-secondary" onClick={() => setEditing(true)}>
              Edit
            </button>
          )}
        </div>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
        <p style={{ fontSize: 13 }}>
          <strong>Type:</strong> {partner.partner_type} &nbsp; <strong>Contact:</strong> {partner.contact_name ?? '—'} ({partner.contact_phone ?? '—'})
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Payment Terms:</strong> {partner.payment_terms ?? '—'} &nbsp; <strong>Tax ID:</strong> {partner.tax_id ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Address:</strong> {partner.address ?? '—'} &nbsp; <strong>Province/City:</strong> {partner.province ?? '—'} / {partner.city ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Bank:</strong> {partner.bank ?? '—'} &nbsp; <strong>Account Holder:</strong> {partner.account_holder ?? '—'} &nbsp;
          <strong>Account Number:</strong> {partner.account_number ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Description:</strong> {partner.description ?? '—'}
        </p>
      </div>

      {editing && (
        <EditPartnerModal
          partner={partner}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false);
            load();
          }}
        />
      )}

      {perf && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Performance</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16 }}>
            {[
              { label: 'POs Issued', value: perf.purchase_orders_issued },
              { label: 'On-Time Rate', value: perf.on_time_rate !== null ? `${perf.on_time_rate}%` : '—' },
              { label: 'On-Time Deliveries', value: perf.deliveries_on_time },
              { label: 'Late Deliveries', value: perf.deliveries_late },
              { label: 'Qty Accepted', value: perf.quantity_accepted },
              { label: 'Qty Rejected', value: perf.quantity_rejected },
              { label: 'Total Purchase Value', value: perf.total_purchase_value },
              { label: 'Returns', value: perf.returns },
            ].map((s) => (
              <div key={s.label}>
                <div style={{ fontSize: 12, color: '#6b7280' }}>{s.label}</div>
                <div style={{ fontSize: 20, fontWeight: 700 }}>{s.value}</div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function EditPartnerModal({ partner, onClose, onSaved }: { partner: PartnerItem; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(partner.name);
  const [contactName, setContactName] = useState(partner.contact_name ?? '');
  const [contactPhone, setContactPhone] = useState(partner.contact_phone ?? '');
  const [contactEmail, setContactEmail] = useState(partner.contact_email ?? '');
  const [address, setAddress] = useState(partner.address ?? '');
  const [province, setProvince] = useState(partner.province ?? '');
  const [city, setCity] = useState(partner.city ?? '');
  const [taxId, setTaxId] = useState(partner.tax_id ?? '');
  const [paymentTerms, setPaymentTerms] = useState(partner.payment_terms ?? '');
  const [bank, setBank] = useState(partner.bank ?? '');
  const [accountHolder, setAccountHolder] = useState(partner.account_holder ?? '');
  const [accountNumber, setAccountNumber] = useState(partner.account_number ?? '');
  const [description, setDescription] = useState(partner.description ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/partners/${partner.id}`, {
        name,
        contact_name: contactName || null,
        contact_phone: contactPhone || null,
        contact_email: contactEmail || null,
        address: address || null,
        province: province || null,
        city: city || null,
        tax_id: taxId || null,
        payment_terms: paymentTerms || null,
        bank: bank || null,
        account_holder: accountHolder || null,
        account_number: accountNumber || null,
        description: description || null,
      });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={`Edit ${partner.name}`} onClose={onClose} width={560}>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Contact Name" errors={errors.contact_name}>
          <input value={contactName} onChange={(e) => setContactName(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Contact Phone" errors={errors.contact_phone}>
          <input value={contactPhone} onChange={(e) => setContactPhone(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label="Contact Email" errors={errors.contact_email}>
        <input value={contactEmail} onChange={(e) => setContactEmail(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Address" errors={errors.address}>
        <textarea value={address} onChange={(e) => setAddress(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Province" errors={errors.province}>
          <input value={province} onChange={(e) => setProvince(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="City" errors={errors.city}>
          <input value={city} onChange={(e) => setCity(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Tax ID" errors={errors.tax_id}>
          <input value={taxId} onChange={(e) => setTaxId(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Payment Terms" errors={errors.payment_terms}>
          <input value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label="Bank" errors={errors.bank}>
        <input value={bank} onChange={(e) => setBank(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Account Holder" errors={errors.account_holder}>
          <input value={accountHolder} onChange={(e) => setAccountHolder(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Account Number" errors={errors.account_number}>
          <input value={accountNumber} onChange={(e) => setAccountNumber(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !name} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
