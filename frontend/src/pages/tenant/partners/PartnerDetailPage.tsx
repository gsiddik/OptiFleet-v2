import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { PartnerItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { t } from '../../../i18n/i18n';

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

  useBreadcrumbLabel(partner?.id, partner?.name);

  if (error && !partner) return <ErrorState message={error} />;
  if (!partner) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/partners" label={t('partner.actions.backToVendor')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {partner.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({partner.code})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={partner.status} />
          {hasPermission('partner.manage') && (
            <button className="btn-secondary" onClick={() => setEditing(true)}>
              {t('common.actions.edit')}
            </button>
          )}
        </div>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('common.sections.details')}</h3>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.type')}:</strong> {partner.partner_type} &nbsp; <strong>{t('partner.fields.contact')}:</strong> {partner.contact_name ?? '—'} ({partner.contact_phone ?? '—'})
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('partner.fields.paymentTerms')}:</strong> {partner.payment_terms ?? '—'} &nbsp; <strong>{t('account.fields.taxId')}:</strong> {partner.tax_id ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.address')}:</strong> {partner.address ?? '—'} &nbsp; <strong>{t('partner.fields.provinceCity')}:</strong> {partner.province ?? '—'} / {partner.city ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.bank')}:</strong> {partner.bank ?? '—'} &nbsp; <strong>{t('partner.fields.accountHolder')}:</strong> {partner.account_holder ?? '—'} &nbsp;
          <strong>{t('partner.fields.accountNumber')}:</strong> {partner.account_number ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.description')}:</strong> {partner.description ?? '—'}
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

      <VendorPerformanceCard partnerId={partner.id} />
    </div>
  );
}

interface VendorPerformance {
  category: 'EXTERNAL_WORKSHOP' | 'SUPPLIER' | 'SERVICE_PROVIDER';
  period: { from: string; to: string; basis: string };
  kpis: Record<string, number | string | null>;
}

type Kpi = { key: string; label: string; labelKey?: string; kind?: 'rate' | 'money' | 'hours' | 'days' };

/** Which KPIs apply depends on what the vendor does — an External Workshop is not judged on PO deliveries. */
const KPI_LAYOUT: Record<VendorPerformance['category'], Kpi[]> = {
  EXTERNAL_WORKSHOP: [
    { key: 'work_orders_assigned', label: 'Work Orders Assigned', labelKey: 'partner.sections.workOrdersAssigned' },
    { key: 'acknowledged', label: 'Acknowledged', labelKey: 'partner.sections.acknowledged' },
    { key: 'completed', label: 'Completed', labelKey: 'partner.sections.completed' },
    { key: 'cancelled', label: 'Cancelled', labelKey: 'partner.sections.cancelled' },
    { key: 'rejected', label: 'Rejected', labelKey: 'documents.goodsReceipt.rejected' },
    { key: 'acknowledgement_rate', label: 'Acknowledgement Rate', labelKey: 'partner.sections.acknowledgementRate', kind: 'rate' },
    { key: 'completion_rate', label: 'Completion Rate', labelKey: 'partner.sections.completionRate', kind: 'rate' },
    { key: 'cancellation_rate', label: 'Cancellation Rate', labelKey: 'partner.sections.cancellationRate', kind: 'rate' },
    { key: 'avg_acknowledgement_hours', label: 'Avg. Acknowledgement Time', labelKey: 'partner.sections.avgAcknowledgementTime', kind: 'hours' },
    { key: 'avg_completion_hours', label: 'Avg. Completion Time', labelKey: 'partner.sections.avgCompletionTime', kind: 'hours' },
    { key: 'invoice_amount', label: 'Invoice Amount', labelKey: 'partner.sections.invoiceAmount', kind: 'money' },
    { key: 'paid_amount', label: 'Paid Amount', labelKey: 'partner.sections.paidAmount', kind: 'money' },
    { key: 'outstanding_amount', label: 'Outstanding Amount', labelKey: 'partner.sections.outstandingAmount', kind: 'money' },
  ],
  SUPPLIER: [
    { key: 'purchase_orders_issued', label: 'POs Issued', labelKey: 'partner.sections.posIssued' },
    { key: 'purchase_orders_fully_received', label: 'POs Fully Received', labelKey: 'partner.sections.posFullyReceived' },
    { key: 'purchase_orders_cancelled', label: 'POs Cancelled', labelKey: 'partner.sections.posCancelled' },
    { key: 'purchase_order_value', label: 'PO Value', labelKey: 'partner.sections.poValue', kind: 'money' },
    { key: 'deliveries', label: 'Deliveries', labelKey: 'partner.sections.deliveries' },
    { key: 'deliveries_on_time', label: 'On-Time Deliveries', labelKey: 'partner.sections.onTimeDeliveries' },
    { key: 'deliveries_late', label: 'Late Deliveries', labelKey: 'partner.sections.lateDeliveries' },
    { key: 'on_time_rate', label: 'On-Time Rate', labelKey: 'partner.sections.onTimeRate', kind: 'rate' },
    { key: 'avg_lead_time_days', label: 'Avg. Lead Time', labelKey: 'partner.sections.avgLeadTime', kind: 'days' },
    { key: 'quantity_accepted', label: 'Qty Accepted', labelKey: 'partner.sections.qtyAccepted' },
    { key: 'quantity_rejected', label: 'Qty Rejected', labelKey: 'partner.sections.qtyRejected' },
    { key: 'quantity_damaged', label: 'Qty Damaged', labelKey: 'partner.sections.qtyDamaged' },
    { key: 'rejection_rate', label: 'Rejection Rate', labelKey: 'partner.sections.rejectionRate', kind: 'rate' },
    { key: 'invoice_amount', label: 'Invoice Amount', labelKey: 'partner.sections.invoiceAmount', kind: 'money' },
    { key: 'paid_amount', label: 'Paid Amount', labelKey: 'partner.sections.paidAmount', kind: 'money' },
    { key: 'outstanding_amount', label: 'Outstanding Amount', labelKey: 'partner.sections.outstandingAmount', kind: 'money' },
  ],
  SERVICE_PROVIDER: [
    { key: 'services_requested', label: 'Services Requested', labelKey: 'partner.sections.servicesRequested' },
    { key: 'services_completed', label: 'Completed', labelKey: 'partner.sections.completed' },
    { key: 'services_cancelled', label: 'Cancelled', labelKey: 'partner.sections.cancelled' },
    { key: 'completion_rate', label: 'Completion Rate', labelKey: 'partner.sections.completionRate', kind: 'rate' },
    { key: 'cancellation_rate', label: 'Cancellation Rate', labelKey: 'partner.sections.cancellationRate', kind: 'rate' },
    { key: 'avg_completion_hours', label: 'Avg. Completion Time', labelKey: 'partner.sections.avgCompletionTime', kind: 'hours' },
    { key: 'estimated_cost', label: 'Estimated Cost', labelKey: 'partner.sections.estimatedCost', kind: 'money' },
    { key: 'invoice_amount', label: 'Invoice Amount', labelKey: 'partner.sections.invoiceAmount', kind: 'money' },
    { key: 'paid_amount', label: 'Paid Amount', labelKey: 'partner.sections.paidAmount', kind: 'money' },
    { key: 'outstanding_amount', label: 'Outstanding Amount', labelKey: 'partner.sections.outstandingAmount', kind: 'money' },
  ],
};

function formatKpi(value: number | string | null | undefined, kind?: Kpi['kind']): string {
  if (value === null || value === undefined) return '—';
  if (kind === 'money') return formatMoney(value);
  if (kind === 'rate') return `${value}%`;
  if (kind === 'hours') return `${value} h`;
  if (kind === 'days') return `${value} days`;
  return String(value);
}

function VendorPerformanceCard({ partnerId }: { partnerId: string }) {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [data, setData] = useState<VendorPerformance | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiClient
      .get(`/app/partners/${partnerId}/performance`, { params: { from: from || undefined, to: to || undefined } })
      .then((res) => {
        if (cancelled) return;
        setData(res.data.data);
        setError(null);
      })
      .catch((err) => !cancelled && setError(extractApiError(err).message));
    return () => {
      cancelled = true;
    };
  }, [partnerId, from, to]);

  return (
    <div className="card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
        <div>
          <h3 style={{ margin: 0, fontSize: 15 }}>{t('partner.sections.performance')}</h3>
          {data && (
            <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
              {data.period.from} – {data.period.to} · {data.period.basis}
            </div>
          )}
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
          <label style={{ fontSize: 12, color: '#374151' }}>
            {t('common.fields.from')}
            <input type="date" aria-label={t('partner.fields.performanceFrom')} value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} style={{ ...inputStyle, display: 'block', width: 150 }} />
          </label>
          <label style={{ fontSize: 12, color: '#374151' }}>
            {t('common.fields.to')}
            <input type="date" aria-label={t('partner.fields.performanceTo')} value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} style={{ ...inputStyle, display: 'block', width: 150 }} />
          </label>
        </div>
      </div>
      {error && <ErrorState message={error} />}
      {!error && !data && <LoadingState />}
      {data && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 16 }}>
          {KPI_LAYOUT[data.category].map((k) => (
            <div key={k.key}>
              <div style={{ fontSize: 12, color: '#6b7280' }}>{k.label}</div>
              <div style={{ fontSize: 20, fontWeight: 700 }}>{formatKpi(data.kpis[k.key], k.kind)}</div>
            </div>
          ))}
        </div>
      )}
      {data?.category === 'EXTERNAL_WORKSHOP' && (
        <div style={{ fontSize: 12, color: '#6b7280', marginTop: 12 }}>
          {t('partner.help.rejectedExternalWorkshopWorkflowNoRejection')}
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
    <Modal open title={t('breadcrumb.usebreadcrumblabel3', { value: partner.name })} onClose={onClose} width={560}>
      <FormField label={t('common.fields.name')} errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={t('partner.fields.contactName')} errors={errors.contact_name}>
          <input value={contactName} onChange={(e) => setContactName(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('partner.fields.contactPhone')} errors={errors.contact_phone}>
          <input value={contactPhone} onChange={(e) => setContactPhone(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label={t('partner.fields.contactEmail')} errors={errors.contact_email}>
        <input value={contactEmail} onChange={(e) => setContactEmail(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.address')} errors={errors.address}>
        <textarea value={address} onChange={(e) => setAddress(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={t('common.fields.province')} errors={errors.province}>
          <input value={province} onChange={(e) => setProvince(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('common.fields.city')} errors={errors.city}>
          <input value={city} onChange={(e) => setCity(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={t('account.fields.taxId')} errors={errors.tax_id}>
          <input value={taxId} onChange={(e) => setTaxId(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('partner.fields.paymentTerms')} errors={errors.payment_terms}>
          <input value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label={t('common.fields.bank')} errors={errors.bank}>
        <input value={bank} onChange={(e) => setBank(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={t('partner.fields.accountHolder')} errors={errors.account_holder}>
          <input value={accountHolder} onChange={(e) => setAccountHolder(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('partner.fields.accountNumber')} errors={errors.account_number}>
          <input value={accountNumber} onChange={(e) => setAccountNumber(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label={t('common.fields.description')} errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !name} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
