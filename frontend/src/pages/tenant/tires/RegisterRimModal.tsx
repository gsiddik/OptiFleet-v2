import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { SearchableSelect, type SearchableOption } from '../../../components/SearchableSelect';
import type { ProductItem } from '../../../types';
import { t } from '../../../i18n/i18n';

export type RimRegistrationMode = 'INSTALLED' | 'NEW_STOCK';

interface PositionOption {
  position_code: string;
  rim_serial_number: string | null;
}

/**
 * Register Rim: one physical, serial-numbered rim of a Rim Product, either directly Installed on a
 * vehicle's wheel position (the usual case — rims are mostly already on vehicles) or as New Stock in a
 * warehouse. Positions come from the vehicle's Wheels Configuration (never free text); a position that
 * already holds a rim cannot be chosen. A tire on the same position is not affected.
 */
export function RegisterRimModal({ product, initialMode, onClose, onRegistered }: { product: ProductItem; initialMode: RimRegistrationMode; onClose: () => void; onRegistered: () => void }) {
  const [mode, setMode] = useState<RimRegistrationMode>(initialMode);
  const [serialNumber, setSerialNumber] = useState('');
  const [vehicleId, setVehicleId] = useState('');
  const [vehicleLabel, setVehicleLabel] = useState<string | null>(null);
  const [positions, setPositions] = useState<PositionOption[] | null>(null);
  const [hasConfiguration, setHasConfiguration] = useState(true);
  const [positionCode, setPositionCode] = useState('');
  const [warehouses, setWarehouses] = useState<{ id: string; code: string; name: string }[]>([]);
  const [warehouseId, setWarehouseId] = useState('');
  const [purchaseDate, setPurchaseDate] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [registered, setRegistered] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/warehouses', { params: { per_page: 100 } })
      .then((res) => setWarehouses(res.data.data))
      .catch(() => setWarehouses([]));
  }, []);

  useEffect(() => {
    if (!vehicleId) return;
    apiClient
      .get(`/app/rim-products/vehicles/${vehicleId}/positions`)
      .then((res) => {
        setHasConfiguration(res.data.data.has_configuration);
        setPositions(res.data.data.positions);
      })
      .catch((e) => setError(extractApiError(e).message));
  }, [vehicleId]);

  const loadVehicles = (search: string): Promise<SearchableOption[]> =>
    apiClient
      .get('/app/vehicles', { params: { search, per_page: 20 } })
      .then((res) => res.data.data.map((v: { id: string; registration_number: string; brand?: string; model?: string }) => ({ value: v.id, label: v.registration_number, hint: [v.brand, v.model].filter(Boolean).join(' ') })));

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setError(null);
    try {
      const res = await apiClient.post(`/app/rim-products/${product.id}/rims`, {
        serial_number: serialNumber,
        purchase_date: purchaseDate || null,
        ...(mode === 'INSTALLED' ? { vehicle_id: vehicleId || null, position_code: positionCode || null } : { warehouse_id: warehouseId || null }),
      });
      setRegistered(res.data.data.serial_number);
      setSerialNumber('');
      setPositionCode('');
      onRegistered();
      if (mode === 'INSTALLED' && vehicleId) {
        const refreshed = await apiClient.get(`/app/rim-products/vehicles/${vehicleId}/positions`);
        setPositions(refreshed.data.data.positions);
      }
    } catch (err) {
      const api: ApiErrorShape = extractApiError(err);
      setErrors(api.errors ?? {});
      if (!api.errors) setError(api.message);
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = serialNumber.trim() !== '' && (mode === 'INSTALLED' ? vehicleId && positionCode : warehouseId) && !submitting;

  return (
    <Modal open title={t('rim.modals.registerRim')} onClose={onClose} width={560}>
      <p style={{ fontSize: 13, marginTop: 0, color: '#374151' }}>{t('rim.help.registerFor', { productName: product.name })}</p>
      {registered && (
        <div role="status" style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, padding: '8px 12px', marginBottom: 10 }}>
          {t('rim.help.registeredSerial', { serial: registered })}
        </div>
      )}
      {error && (
        <div role="alert" style={{ fontSize: 13, color: '#991b1b', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6, padding: '8px 12px', marginBottom: 10 }}>
          {error}
        </div>
      )}
      <FormField label={t('rim.fields.registerAs')}>
        <div style={{ display: 'flex', gap: 16, fontSize: 14 }} role="radiogroup">
          {(['INSTALLED', 'NEW_STOCK'] as RimRegistrationMode[]).map((m) => (
            <label key={m} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <input type="radio" name="rim-mode" checked={mode === m} onChange={() => setMode(m)} /> {m === 'INSTALLED' ? t('tire.sections.installed') : t('tire.sections.newStock')}
            </label>
          ))}
        </div>
      </FormField>
      <FormField label={t('common.fields.serialNumber')} errors={errors.serial_number} required>
        <input value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} style={inputStyle} maxLength={100} aria-label={t('common.fields.serialNumber')} />
      </FormField>
      {mode === 'INSTALLED' ? (
        <>
          <FormField label={t('common.fields.vehicle')} errors={errors.vehicle_id} required>
            <SearchableSelect
              value={vehicleId}
              selectedLabel={vehicleLabel}
              onChange={(value, option) => {
                setPositions(null);
                setPositionCode('');
                setVehicleId(value);
                setVehicleLabel(option?.label ?? null);
              }}
              loadOptions={loadVehicles}
              ariaLabel={t('common.fields.vehicle')}
              placeholder={t('rim.placeholders.searchVehicle')}
            />
          </FormField>
          <FormField label={t('tire.fields.position')} errors={errors.position_code} required hint={t('rim.help.positionFromConfiguration')}>
            <select value={positionCode} onChange={(e) => setPositionCode(e.target.value)} style={inputStyle} disabled={!positions || !hasConfiguration} aria-label={t('tire.fields.position')}>
              <option value="">{t('common.fields.select')}</option>
              {(positions ?? []).map((p) => (
                <option key={p.position_code} value={p.position_code} disabled={!!p.rim_serial_number}>
                  {p.rim_serial_number ? t('rim.fields.positionOccupiedBy', { position: p.position_code, serial: p.rim_serial_number }) : p.position_code}
                </option>
              ))}
            </select>
          </FormField>
          {vehicleId && positions && !hasConfiguration && <p style={{ fontSize: 12, color: '#b45309', marginTop: -6 }}>{t('rim.help.noWheelConfiguration')}</p>}
        </>
      ) : (
        <FormField label={t('common.fields.warehouse')} errors={errors.warehouse_id} required>
          <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle} aria-label={t('common.fields.warehouse')}>
            <option value="">{t('common.fields.select')}</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.code} — {w.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <FormField label={t('rim.fields.purchaseDateOptional')} errors={errors.purchase_date}>
        <input type="date" value={purchaseDate} onChange={(e) => setPurchaseDate(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.close')}
        </button>
        <button className="btn-primary" disabled={!canSubmit} onClick={submit} data-register-rim-submit>
          {submitting ? t('rim.actions.registering') : t('rim.actions.registerRim')}
        </button>
      </div>
    </Modal>
  );
}
