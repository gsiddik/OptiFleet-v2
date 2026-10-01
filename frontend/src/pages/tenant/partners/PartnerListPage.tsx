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
    { key: 'code', header: 'Code', render: (p) => <Link to={`/app/partners/${p.id}`}>{p.code}</Link> },
    { key: 'name', header: 'Name', render: (p) => p.name },
    { key: 'type', header: 'Type', render: (p) => p.partner_type },
    { key: 'contact', header: 'Contact', render: (p) => p.contact_name ?? '—' },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vendors / Partners</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('partner.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Vendor
            </button>
          ) : null
        }
      >
        <select aria-label="Vendor type" value={typeFilter} onChange={(e) => changeType(e.target.value)} style={{ ...inputStyle, width: 220 }}>
          <option value="">All types</option>
          <option value="SUPPLIERS">All Suppliers</option>
          {PARTNER_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={typeFilter === 'SUPPLIERS' ? 'No suppliers found.' : 'No vendors found.'} />}
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
    <Modal open={open} title="New Vendor" onClose={onClose}>
      <FormField label="Code" errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Type" errors={errors.partner_type} required>
        <select value={partnerType} onChange={(e) => setPartnerType(e.target.value)} style={inputStyle}>
          {PARTNER_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Contact Name" errors={errors.contact_name}>
        <input value={contactName} onChange={(e) => setContactName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Contact Phone" errors={errors.contact_phone}>
        <input value={contactPhone} onChange={(e) => setContactPhone(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Province" errors={errors.province}>
          <input value={province} onChange={(e) => setProvince(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="City" errors={errors.city}>
          <input value={city} onChange={(e) => setCity(e.target.value)} style={inputStyle} />
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
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !code || !name} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
