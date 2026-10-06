import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SUPPORTED_LOCALES } from '../../../i18n/locale';
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
  province: string | null;
  city: string | null;
  phone: string | null;
  fax: string | null;
  email: string | null;
  website: string | null;
  logo_url: string | null;
  workshop_working_days: number | null;
  default_locale: string | null;
}

/** G-15: previously a tenant had no self-service way to view or maintain its own company profile. */
export function CompanyProfilePage() {
  const { t } = useTranslation();
  const { hasPermission, refresh } = useAuth();
  const [profile, setProfile] = useState<CompanyProfile | null>(null);
  const [legalName, setLegalName] = useState('');
  const [industry, setIndustry] = useState('');
  const [taxId, setTaxId] = useState('');
  const [address, setAddress] = useState('');
  const [province, setProvince] = useState('');
  const [city, setCity] = useState('');
  const [phone, setPhone] = useState('');
  const [fax, setFax] = useState('');
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const [workshopWorkingDays, setWorkshopWorkingDays] = useState<string>('');
  const [defaultLocale, setDefaultLocale] = useState<string>('');
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [uploadingLogo, setUploadingLogo] = useState(false);
  const [logoError, setLogoError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

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
        setProvince(p.province ?? '');
        setCity(p.city ?? '');
        setPhone(p.phone ?? '');
        setFax(p.fax ?? '');
        setEmail(p.email ?? '');
        setWebsite(p.website ?? '');
        setWorkshopWorkingDays(p.workshop_working_days ? String(p.workshop_working_days) : '');
        setDefaultLocale(p.default_locale ?? '');
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
        province: province || null,
        city: city || null,
        phone: phone || null,
        fax: fax || null,
        email: email || null,
        website: website || null,
        workshop_working_days: workshopWorkingDays ? Number(workshopWorkingDays) : null,
        default_locale: defaultLocale || null,
      });
      setSaved(true);
      load();
      // The tenant default language applies to users who have not chosen their own.
      void refresh();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSaving(false);
    }
  }

  async function uploadLogo(file: File) {
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setLogoError('Only JPG or PNG images are accepted.');
      return;
    }
    setUploadingLogo(true);
    setLogoError(null);
    try {
      const form = new FormData();
      form.append('file', file);
      const res = await apiClient.post('/app/account/company/logo', form);
      setProfile(res.data.data);
      await refresh();
    } catch (err) {
      setLogoError(extractApiError(err).message);
    } finally {
      setUploadingLogo(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
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
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <FormField label="Province" errors={errors.province}>
            <input value={province} onChange={(e) => setProvince(e.target.value)} style={inputStyle} disabled={!canEdit} />
          </FormField>
          <FormField label="City" errors={errors.city}>
            <input value={city} onChange={(e) => setCity(e.target.value)} style={inputStyle} disabled={!canEdit} />
          </FormField>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <FormField label="Phone" errors={errors.phone}>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} style={inputStyle} disabled={!canEdit} />
          </FormField>
          <FormField label="Fax" errors={errors.fax}>
            <input value={fax} onChange={(e) => setFax(e.target.value)} style={inputStyle} disabled={!canEdit} />
          </FormField>
        </div>
        <FormField label="Email" errors={errors.email}>
          <input value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Website" errors={errors.website}>
          <input value={website} onChange={(e) => setWebsite(e.target.value)} style={inputStyle} disabled={!canEdit} />
        </FormField>
        <FormField label="Logo (JPG or PNG)" errors={logoError ? [logoError] : undefined}>
          {profile.logo_url && (
            <img src={profile.logo_url} alt={profile.name} style={{ maxWidth: 160, display: 'block', marginBottom: 8, borderRadius: 6 }} />
          )}
          {canEdit && (
            <>
              <input
                ref={fileInputRef}
                type="file"
                accept="image/jpeg,image/png"
                disabled={uploadingLogo}
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) uploadLogo(file);
                }}
              />
              {uploadingLogo && <span style={{ fontSize: 12, color: '#6b7280', marginLeft: 8 }}>Uploading…</span>}
            </>
          )}
          <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
            Used in the sidebar and browser tab icon for this tenant.
          </div>
        </FormField>
        <FormField label={t('account.fields.defaultLanguage')} errors={errors.default_locale}>
          <select value={defaultLocale} onChange={(e) => setDefaultLocale(e.target.value)} style={inputStyle} disabled={!canEdit} data-default-locale>
            <option value="">{t('common.language.default')}</option>
            {SUPPORTED_LOCALES.map((locale) => (
              <option key={locale} value={locale} lang={locale}>
                {t(`common.language.${locale}`)}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Workshop Working Days" required errors={errors.workshop_working_days}>
          <select
            value={workshopWorkingDays}
            onChange={(e) => setWorkshopWorkingDays(e.target.value)}
            style={inputStyle}
            disabled={!canEdit}
          >
            <option value="">Select…</option>
            <option value="5">5 days (Monday–Friday)</option>
            <option value="6">6 days (Monday–Saturday)</option>
            <option value="7">7 days (Monday–Sunday)</option>
          </select>
          <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
            Drives working-day calculations for Planning &amp; Schedule. Must be completed before a periodic
            maintenance schedule can be created.
          </div>
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
