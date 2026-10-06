import { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import type { ProductItem } from '../../../types';
import { t } from '../../../i18n/i18n';

/**
 * Registers one physical (serial-numbered) tire of a Tire product. Tire creation goes through the
 * Product (Item Type = Tire): the product's tire specification is the source of truth and is
 * applied by the backend, so only what is unique to this physical tire is entered here.
 */
export function RegisterTireModal({ product, onClose, onRegistered }: { product: ProductItem; onClose: () => void; onRegistered?: () => void }) {
  const [serialNumber, setSerialNumber] = useState('');
  const [manufactureDateCode, setManufactureDateCode] = useState('');
  const [purchaseDate, setPurchaseDate] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [registered, setRegistered] = useState<{ id: string; serial_number: string } | null>(null);
  const spec = product.tire_spec;

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setError(null);
    try {
      const res = await apiClient.post('/app/tires', {
        product_id: product.id,
        serial_number: serialNumber,
        manufacture_date_code: manufactureDateCode || undefined,
        purchase_date: purchaseDate || undefined,
      });
      setRegistered(res.data.data);
      onRegistered?.();
      setSerialNumber('');
      setManufactureDateCode('');
      setPurchaseDate('');
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      if (!apiError.errors) setError(apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('tire.actions.registerTire')} onClose={onClose}>
      <div style={{ fontSize: 13, background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10, marginBottom: 12 }}>
        <div>
          <strong>{product.name}</strong>
        </div>
        <div style={{ color: '#6b7280', marginTop: 4 }}>
          {spec
            ? [spec.tire_size_computed, spec.pattern_name, spec.construction_type, spec.tire_type].filter(Boolean).join(' · ')
            : t('tire.help.productNoTireSpecificationYetComplete')}
        </div>
        <div style={{ color: '#6b7280', marginTop: 4, fontSize: 12 }}>{t('tire.help.specificationComesProductAppliedRegisteredTire')}</div>
      </div>
      {registered && (
        <div style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', borderRadius: 6, padding: 8, marginBottom: 10 }}>
          {t('masterData.itemType.tire')} <Link to={`/app/tires/${registered.id}`}>{registered.serial_number}</Link> {t('tire.help.registeredNowListedTireManagementTire')}
        </div>
      )}
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 8 }}>{error}</div>}
      <FormField label={t('common.fields.serialNumber')} errors={errors.serial_number} required>
        <input aria-label={t('tire.fields.serialNumber')} value={serialNumber} onChange={(e) => setSerialNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('tire.fields.manufactureDateCode')} errors={errors.manufacture_date_code}>
        <input aria-label={t('tire.fields.manufactureDateCode2')} value={manufactureDateCode} onChange={(e) => setManufactureDateCode(e.target.value)} placeholder={t('tire.placeholders.eGDotWeekYearCode')} style={inputStyle} />
      </FormField>
      <FormField label={t('tire.fields.purchaseDate')} errors={errors.purchase_date}>
        <input type="date" aria-label={t('tire.fields.purchaseDate2')} value={purchaseDate} onChange={(e) => setPurchaseDate(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.close')}
        </button>
        <button className="btn-primary" disabled={submitting || !serialNumber.trim() || !spec} onClick={submit}>
          {submitting ? t('tire.actions.registering') : t('tire.actions.registerTire')}
        </button>
      </div>
    </Modal>
  );
}
