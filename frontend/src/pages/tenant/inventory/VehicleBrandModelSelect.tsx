import { useEffect, useState } from 'react';
import { apiClient } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { t } from '../../../i18n/i18n';

export interface VehicleOption {
  id: string;
  name: string;
}

// Shared per page load: every compatibility row reads the same brand list and each brand's
// models once, instead of one request per row.
let brandsRequest: Promise<VehicleOption[]> | null = null;
const modelRequests = new Map<string, Promise<VehicleOption[]>>();

function loadBrands(): Promise<VehicleOption[]> {
  brandsRequest ??= apiClient
    .get('/app/product-classification/vehicle-brands')
    .then((res) => res.data.data as VehicleOption[])
    .catch((err) => {
      brandsRequest = null;
      throw err;
    });
  return brandsRequest;
}

function loadModels(brandId: string): Promise<VehicleOption[]> {
  if (!modelRequests.has(brandId)) {
    modelRequests.set(
      brandId,
      apiClient
        .get('/app/product-classification/vehicle-models', { params: { vehicle_brand_id: brandId } })
        .then((res) => res.data.data as VehicleOption[])
        .catch((err) => {
          modelRequests.delete(brandId);
          throw err;
        }),
    );
  }
  return modelRequests.get(brandId)!;
}

/**
 * Vehicle Brand -> Vehicle Model dependent dropdowns (values are master ids). Changing the
 * brand always clears the model, since a model belongs to exactly one brand. A current value
 * that is no longer active is still shown (labelled) so an existing record never loses it.
 */
export function VehicleBrandModelSelect({
  brandId,
  modelId,
  onChange,
  anyLabel,
  current,
  disabled,
  width,
}: {
  brandId: string;
  modelId: string;
  onChange: (brandId: string, modelId: string) => void;
  /** When set, an empty choice with this label is offered (e.g. "Any"); otherwise a placeholder. */
  anyLabel?: string;
  /** Names of the stored selection, for retired masters not in the active lists. */
  current?: { brandName?: string | null; modelName?: string | null };
  disabled?: boolean;
  width?: number;
}) {
  const [brands, setBrands] = useState<VehicleOption[]>([]);
  const [models, setModels] = useState<VehicleOption[]>([]);
  const [modelsFor, setModelsFor] = useState('');

  useEffect(() => {
    loadBrands().then(setBrands).catch(() => setBrands([]));
  }, []);

  useEffect(() => {
    if (!brandId) return;
    loadModels(brandId)
      .then((rows) => {
        setModels(rows);
        setModelsFor(brandId);
      })
      .catch(() => setModels([]));
  }, [brandId]);

  const visibleModels = brandId && modelsFor === brandId ? models : [];
  const style = { ...inputStyle, ...(width ? { width } : {}) };

  return (
    <>
      <select aria-label={t('inventory.fields.vehicleBrand')} value={brandId} disabled={disabled} onChange={(e) => onChange(e.target.value, '')} style={style}>
        <option value="">{anyLabel ?? t('inventory.fields.brand')}</option>
        {brandId && !brands.some((b) => b.id === brandId) && <option value={brandId}>{current?.brandName ?? brandId} {t('masterData.fields.inactive')}</option>}
        {brands.map((b) => (
          <option key={b.id} value={b.id}>
            {b.name}
          </option>
        ))}
      </select>
      <select aria-label={t('inventory.fields.vehicleModel')} value={modelId} disabled={disabled || !brandId} onChange={(e) => onChange(brandId, e.target.value)} style={style}>
        <option value="">{brandId ? (anyLabel ?? t('inventory.fields.model')) : t('inventory.fields.selectABrandFirst')}</option>
        {modelId && modelsFor === brandId && !visibleModels.some((m) => m.id === modelId) && (
          <option value={modelId}>{current?.modelName ?? modelId} {t('masterData.fields.inactive')}</option>
        )}
        {visibleModels.map((m) => (
          <option key={m.id} value={m.id}>
            {m.name}
          </option>
        ))}
      </select>
    </>
  );
}
