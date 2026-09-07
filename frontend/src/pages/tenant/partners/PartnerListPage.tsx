import { useState } from 'react';
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
import type { PartnerItem } from '../../../types';

const PARTNER_TYPES = ['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER', 'EXTERNAL_WORKSHOP', 'TOWING_PROVIDER', 'OTHER_SERVICE_PROVIDER'];

export function PartnerListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<PartnerItem>('/app/partners', { search: search || undefined }, reloadKey);

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
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vendors found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreatePartnerModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreatePartnerModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [partnerType, setPartnerType] = useState('SPARE_PART_SUPPLIER');
  const [contactName, setContactName] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/partners', { code, name, partner_type: partnerType, contact_name: contactName || undefined, contact_phone: contactPhone || undefined });
      setCode('');
      setName('');
      setContactName('');
      setContactPhone('');
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
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Type" errors={errors.partner_type}>
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
