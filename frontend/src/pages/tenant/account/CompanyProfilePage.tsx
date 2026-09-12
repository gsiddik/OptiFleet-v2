import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { useAuth } from '../../../auth/AuthContext';

interface CompanyProfile {
  id: string;
  code: string;
  name: string;
  legal_name: string | null;
  industry: string | null;
  tax_id: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
}

/** G-15: previously a tenant had no self-service way to view or maintain its own company profile. */
export function CompanyProfilePage() {
  const { hasPermission } = useAuth();
  const [profile, setProfile] = useState<CompanyProfile | null>(null);
  const [legalName, setLegalName] = useState('');
  const [industry, setIndustry] = useState('');
  const [taxId, setTaxId] = useState('');
  const [address, setAddress] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  function load() {
    apiClient
      .get('/app/account/company')
      .then((res) => {
        const p: CompanyProfile = res.data.data;
        setProfile(p);
        setLegalName(p.legal_name ?? '');
        setIndustry(p.industry ?? '');
        setTaxId(p.tax_id ?? '');
        setAddress(p.address ?? '');
        setPhone(p.phone ?? '');
        setEmail(p.email ?? '');
        setWebsite(p.website ?? '');
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, []);

  async function save() {
    setSaving(true);
    setErrors({});
    setSaved(false);
    try {
      await apiClient.put('/app/account/company', {
        legal_name: legalName || null,
        industry: industry || null,
        tax_id: taxId || null,
        address: address || null,
        phone: phone || null,
        email: email || null,
        website: website || null,
      });
      setSaved(true);
      load();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSaving(false);
    }
  }

  if (error) return <ErrorState message={error} />;
  if (!profile) return <LoadingState />;

  const canEdit = hasPermission('company.update');

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 20 }}>Company Profile</h1>
      <div className="card" style={{ maxWidth: 640 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 12 }}>
          <div>
            <div style={{ fontSize: 12, color: '#9ca3af' }}>Code</div>
            <div style={{ fontSize: 14 }}>{profile.code}</div>
          </div>
          <div>
            <div style={{ fontSize: 12, color: '#9ca3af' }}>Name</div>
            <div style={{ fontSize: 14 }}>{profile.name}</div>
          </div>
        </div>
        <FormField label="Legal Name" errors={errors.legal_name}>
          <input value={legalName} onChange={(e) => setLegalName(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Industry" errors={errors.industry}>
          <input value={industry} onChange={(e) => setIndustry(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Tax ID" errors={errors.tax_id}>
          <input value={taxId} onChange={(e) => setTaxId(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Address" errors={errors.address}>
          <textarea value={address} onChange={(e) => setAddress(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} disabled={!canEdit} />
        </FormField>
        <FormField label="Phone" errors={errors.phone}>
          <input value={phone} onChange={(e) => setPhone(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Email" errors={errors.email}>
          <input value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Website" errors={errors.website}>
          <input value={website} onChange={(e) => setWebsite(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        {canEdit && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12 }}>
            <button className="btn-primary" disabled={saving} onClick={save}>
              {saving ? 'Saving…' : 'Save'}
            </button>
            {saved && <span style={{ color: '#16a34a', fontSize: 13 }}>Saved.</span>}
          </div>
        )}
      </div>
    </div>
  );
}
