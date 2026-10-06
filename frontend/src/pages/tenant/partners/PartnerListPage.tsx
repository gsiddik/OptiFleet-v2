import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { PartnerItem } from '../../../types';
import { t as tt } from '../../../i18n/i18n';

const PARTNER_TYPES = ['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER', 'EXTERNAL_WORKSHOP', 'TOWING_PROVIDER', 'OTHER_SERVICE_PROVIDER'];

const SUPPLIER_TYPES = ['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER'];

/**
 * Vendors / Partners. Supplier is a partner type, not a separate entity (G-17): the former
 * Suppliers page is this list with the "All Suppliers" type filter (`/app/suppliers` redirects to
 * `?type=SUPPLIERS`). The filter lives in the URL so it can be linked and survives refresh.
 */
export function PartnerListPage() {
  const { hasPermission } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const typeFilter = searchParams.get('type') ?? '';
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const partnerType = typeFilter === 'SUPPLIERS' ? SUPPLIER_TYPES : PARTNER_TYPES.includes(typeFilter) ? typeFilter : undefined;
  const { data, loading, error } = useApiList<PartnerItem>(
    '/app/partners',
    { search: search || undefined, partner_type: partnerType },
    reloadKey,
  );

  function changeType(value: string) {
    const next = new URLSearchParams(searchParams);
    if (value) next.set('type', value);
    else next.delete('type');
    setSearchParams(next, { replace: true });
  }

  const columns: Column<PartnerItem>[] = [
    { key: 'code', header: tt('common.fields.code'), render: (p) => <Link to={`/app/partners/${p.id}`}>{p.code}</Link> },
    { key: 'name', header: tt('common.fields.name'), render: (p) => p.name },
    { key: 'type', header: tt('common.fields.type'), render: (p) => p.partner_type },
    { key: 'contact', header: tt('partner.fields.contact'), render: (p) => p.contact_name ?? '—' },
    { key: 'status', header: tt('common.fields.status'), render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('partner.titles.vendorsPartners')}</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('partner.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('partner.actions.newVendor')}
            </button>
          ) : null
        }
      >
        <select aria-label={tt('partner.fields.vendorType')} value={typeFilter} onChange={(e) => changeType(e.target.value)} style={{ ...inputStyle, width: 220 }}>
          <option value="">{tt('common.filters.allTypes')}</option>
          <option value="SUPPLIERS">{tt('partner.filters.allSuppliers')}</option>
          {PARTNER_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={typeFilter === 'SUPPLIERS' ? tt('partner.empty.noSuppliersFound') : tt('partner.empty.noVendorsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {/* Mounted only while open so the default type follows the current filter. */}
      {showCreate && (
        <CreatePartnerModal
          open
          defaultPartnerType={typeof partnerType === 'string' ? partnerType : undefined}
          onClose={() => setShowCreate(false)}
          onCreated={() => setReloadKey((k) => k + 1)}
        />
      )}
    </div>
  );
}

function CreatePartnerModal({
  open,
  defaultPartnerType,
  onClose,
  onCreated,
}: {
  open: boolean;
  defaultPartnerType?: string;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [partnerType, setPartnerType] = useState(defaultPartnerType ?? 'SPARE_PART_SUPPLIER');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [province, setProvince] = useState('');
  const [city, setCity] = useState('');
  const [bank, setBank] = useState('');
  const [accountHolder, setAccountHolder] = useState('');
  const [accountNumber, setAccountNumber] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/partners', {
        code,
        name,
        partner_type: partnerType,
        contact_name: contactName || undefined,
        contact_phone: contactPhone || undefined,
        province: province || undefined,
        city: city || undefined,
        bank: bank || undefined,
        account_holder: accountHolder || undefined,
        account_number: accountNumber || undefined,
        description: description || undefined,
      });
      setCode('');
      setName('');
      setContactName('');
      setContactPhone('');
      setProvince('');
      setCity('');
      setBank('');
      setAccountHolder('');
      setAccountNumber('');
      setDescription('');
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
    <Modal open={open} title={tt('partner.modals.newVendor')} onClose={onClose}>
      <FormField label={tt('common.fields.code')} errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.name')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.type')} errors={errors.partner_type} required>
        <select value={partnerType} onChange={(e) => setPartnerType(e.target.value)} style={inputStyle}>
          {PARTNER_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('partner.fields.contactName')} errors={errors.contact_name}>
        <input value={contactName} onChange={(e) => setContactName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('partner.fields.contactPhone')} errors={errors.contact_phone}>
        <input value={contactPhone} onChange={(e) => setContactPhone(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={tt('common.fields.province')} errors={errors.province}>
          <input value={province} onChange={(e) => setProvince(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('common.fields.city')} errors={errors.city}>
          <input value={city} onChange={(e) => setCity(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label={tt('common.fields.bank')} errors={errors.bank}>
        <input value={bank} onChange={(e) => setBank(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={tt('partner.fields.accountHolder')} errors={errors.account_holder}>
          <input value={accountHolder} onChange={(e) => setAccountHolder(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('partner.fields.accountNumber')} errors={errors.account_number}>
          <input value={accountNumber} onChange={(e) => setAccountNumber(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label={tt('common.fields.description')} errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !code || !name} onClick={submit}>
          {tt('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}
