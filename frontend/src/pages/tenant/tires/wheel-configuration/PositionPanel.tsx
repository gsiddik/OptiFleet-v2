import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { FormField, inputStyle } from '../../../../components/FormField';
import { NumericInput } from '../../../../components/NumericInput';
import { SearchableSelect, type SearchableOption } from '../../../../components/SearchableSelect';
import { StatusBadge } from '../../../../components/StatusBadge';
import { useAuth } from '../../../../auth/AuthContext';
import { formatDate } from '../../../../utils/date';
import type { PositionInstallation, VersionPosition } from './masterTypes';

const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

/**
 * Side panel for the selected wheel position: the registered tire (read-only), or — when none is
 * registered and the user may install tires — the initial / last-known Tire Registration form.
 * Registration records a tire already on the vehicle; it never affects warehouse stock.
 */
export function PositionPanel({ vehicleId, position, installation, onSaved }: { vehicleId: string; position: VersionPosition; installation: PositionInstallation | null; onSaved: () => void }) {
  const { hasPermission } = useAuth();
  return (
    <div data-position-panel={position.position_code}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>
        Position <span style={{ fontFamily: 'monospace' }}>{position.position_code}</span>
      </h3>
      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 12 }}>{position.label}</div>
      {installation ? (
        <InstalledTire installation={installation} />
      ) : hasPermission('tire.install') ? (
        <TireRegistrationForm vehicleId={vehicleId} positionCode={position.position_code} onSaved={onSaved} />
      ) : (
        <p style={{ fontSize: 13, color: '#6b7280' }}>No tire registered on this position.</p>
      )}
    </div>
  );
}

function TireRegistrationForm({ vehicleId, positionCode, onSaved }: { vehicleId: string; positionCode: string; onSaved: () => void }) {
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [km, setKm] = useState('');
  const [product, setProduct] = useState<SearchableOption | null>(null);
  const [serial, setSerial] = useState('');
  const [tread, setTread] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const loadProducts = (search: string) =>
    apiClient
      .get(`/app/vehicles/${vehicleId}/wheel-configuration/tire-products`, { params: { search: search || undefined } })
      .then((res) => (res.data.data as { id: string; name: string; sku: string | null }[]).map((p) => ({ value: p.id, label: p.name, hint: p.sku })));

  // Client checks are for quick feedback only; the backend validates everything again.
  const clientErrors: Record<string, string[]> = {};
  if (time && !TIME_PATTERN.test(time)) clientErrors.installed_time = ['Use HH:mm (24-hour), e.g. 07:30.'];
  const ready = date !== '' && TIME_PATTERN.test(time) && km.trim() !== '' && product !== null && serial.trim() !== '';

  async function submit() {
    setSaving(true);
    setErrors({});
    setMessage(null);
    try {
      await apiClient.post(`/app/vehicles/${vehicleId}/wheel-configuration/tires`, {
        position_code: positionCode,
        installed_date: date,
        installed_time: time,
        installation_km: km,
        product_id: product?.value,
        serial_number: serial,
        tread_depth_mm: tread || null,
      });
      onSaved();
    } catch (e) {
      const err = extractApiError(e);
      setErrors(err.errors ?? {});
      setMessage(err.errors ? null : err.message);
    } finally {
      setSaving(false);
    }
  }

  const fieldErrors = (key: string) => errors[key] ?? clientErrors[key];

  return (
    <div data-tire-registration-form>
      <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 10px' }}>Register the tire currently on this position (last known data). Warehouse stock is not affected.</p>
      <FormField label="Tire Position Code">
        <input aria-label="Tire Position Code" value={positionCode} readOnly disabled style={{ ...inputStyle, background: '#f3f4f6', fontFamily: 'monospace', fontWeight: 700 }} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '0 12px' }}>
        <FormField label="Last Known Installation Date" required errors={fieldErrors('installed_date')}>
          <input aria-label="Last Known Installation Date" type="date" value={date} max={todayIso()} onChange={(e) => setDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Last Known Installation Time" required errors={fieldErrors('installed_time')}>
          <input
            aria-label="Last Known Installation Time"
            type="text"
            inputMode="numeric"
            placeholder="HH:mm"
            maxLength={5}
            autoComplete="off"
            value={time}
            onChange={(e) => setTime(autoColon(e.target.value))}
            style={inputStyle}
          />
        </FormField>
      </div>
      <FormField label="Last Known Installation KM" required errors={fieldErrors('installation_km')}>
        <NumericInput aria-label="Last Known Installation KM" placeholder="e.g. 12500.75 (estimate is fine)" value={km} onChange={(e) => setKm(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Tire" required errors={fieldErrors('product_id')}>
        <SearchableSelect
          ariaLabel="Tire"
          value={product?.value ?? ''}
          selectedLabel={product ? `${product.label}${product.hint ? ` — ${product.hint}` : ''}` : null}
          onChange={(_, option) => setProduct(option)}
          loadOptions={loadProducts}
          placeholder="Select tire product…"
          searchPlaceholder="Search product name or SKU…"
          width="100%"
        />
      </FormField>
      <FormField label="Serial Number" required errors={fieldErrors('serial_number')}>
        <input aria-label="Serial Number" value={serial} maxLength={100} autoComplete="off" onChange={(e) => setSerial(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Last Known Tread Depth (mm)" errors={fieldErrors('tread_depth_mm')}>
        <NumericInput aria-label="Last Known Tread Depth" placeholder="e.g. 8.5" value={tread} onChange={(e) => setTread(e.target.value)} style={inputStyle} />
      </FormField>
      {(message || errors.position_code) && (
        <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>
          {message ?? errors.position_code?.[0]}
        </div>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <button className="btn-primary" disabled={!ready || saving} onClick={submit}>
          {saving ? 'Saving…' : 'Register Tire'}
        </button>
      </div>
    </div>
  );
}

/** "0730" → "07:30" while typing; digits and one colon only. */
function autoColon(raw: string): string {
  const cleaned = raw.replace(/[^\d:]/g, '');
  if (/^\d{3,4}$/.test(cleaned)) return `${cleaned.slice(0, 2)}:${cleaned.slice(2)}`;
  return cleaned.slice(0, 5);
}

function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function InstalledTire({ installation }: { installation: PositionInstallation }) {
  const product = installation.tire.product;
  return (
    <dl data-installed-tire style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 14px', fontSize: 13, margin: 0 }}>
      <Row label="Tire Position Code" value={<span style={{ fontFamily: 'monospace' }}>{installation.position_code}</span>} />
      <Row label="Product" value={product ? `${product.name}${product.sku ? ` — ${product.sku}` : ''}` : '—'} />
      <Row label="Serial Number" value={<Link to={`/app/tires/${installation.tire.id}`}>{installation.tire.serial_number}</Link>} />
      <Row label="Last Known Installation Date" value={formatDate(installation.installed_date)} />
      <Row label="Last Known Installation Time" value={installation.installed_time} />
      <Row label="Last Known Installation KM" value={installation.installation_odometer ?? '—'} />
      <Row label="Last Known Tread Depth" value={installation.tread_depth_mm != null ? `${installation.tread_depth_mm} mm` : '—'} />
      <Row label="Status" value={<StatusBadge status={installation.tire.current_status} />} />
      {installation.installation_source === 'INITIAL_REGISTRATION' && <Row label="Source" value="Initial registration" />}
    </dl>
  );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <>
      <dt style={{ color: '#6b7280' }}>{label}</dt>
      <dd style={{ margin: 0 }}>{value}</dd>
    </>
  );
}
