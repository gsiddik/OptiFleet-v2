import { useEffect, useRef, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { DetailsWithImage, ImageContainer } from '../../../components/ImageContainer';
import { NumericInput } from '../../../components/NumericInput';
import { useAuth } from '../../../auth/AuthContext';
import { EditProductModal } from './EditProductModal';
import type { ProductItem } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { t } from '../../../i18n/i18n';

/**
 * The "Details" card of a Product — shared by Product Detail and Tire Detail so both show the same
 * product data and offer the same (permission-gated) Edit Product, image and Reference Tread Depth
 * actions. Every write goes through the Products API and is authorised there.
 */
export function ProductDetailsSection({ product, onChanged }: { product: ProductItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);
  const [referenceTreadDepthMm, setReferenceTreadDepthMm] = useState(product.reference_tread_depth_mm ?? '');
  const [imagePreviewUrl, setImagePreviewUrl] = useState<string | null>(null);
  const canUpdate = !product.is_system && hasPermission('product.update');
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    setReferenceTreadDepthMm(product.reference_tread_depth_mm ?? '');
  }, [product.reference_tread_depth_mm]);

  /** The uploaded image lives on the private disk, so its preview needs an authenticated blob fetch. */
  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    if (product.image_path) {
      apiClient
        .get(`/app/products/${product.id}/image`, { responseType: 'blob' })
        .then((res) => {
          if (cancelled) return;
          objectUrl = URL.createObjectURL(res.data);
          setImagePreviewUrl(objectUrl);
        })
        .catch(() => setImagePreviewUrl(null));
    } else {
      setImagePreviewUrl(product.image_url ?? null);
    }

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [product.id, product.image_path, product.image_url]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await action();
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  function uploadImage(file: File) {
    const form = new FormData();
    form.append('file', file);
    return run(() => apiClient.post(`/app/products/${product.id}/image`, form, { headers: { 'Content-Type': 'multipart/form-data' } }));
  }

  const deleted = (row: { deleted_at?: string | null } | null | undefined) => (row?.deleted_at ? ` ${t('common.fields.deletedMarker')}` : '');

  const line = { fontSize: 13, margin: '0 0 10px' };
  const details = (
    <>
      <p style={line}>
        <strong>{t('inventory.fields.sku')}:</strong> {product.sku} &nbsp; <strong>{t('common.fields.type')}:</strong> {product.product_type} &nbsp; <strong>{t('common.fields.category')}:</strong> {product.category?.name ?? '—'} &nbsp;{' '}
        <strong>{t('inventory.fields.uom2')}:</strong> {product.uom?.name ?? '—'}
        {(product.component_groups ?? []).length > 0 && (
          <>
            {' '}
            &nbsp; <strong>{t('inventory.fields.componentGroups')}:</strong> {(product.component_groups ?? []).map((g) => componentGroupLabel(g) + deleted(g)).join(', ')}
          </>
        )}
      </p>
      <p style={line}>
        <strong>{t('common.fields.componentGroup')}:</strong> {product.component_group ? componentGroupLabel(product.component_group) + deleted(product.component_group) : '—'}
        &nbsp; <strong>{t('common.fields.category')}:</strong> {product.component_category ? product.component_category.name + deleted(product.component_category) : '—'}
        &nbsp; <strong>{t('inventory.fields.subcategory')}:</strong> {product.component_subcategory ? product.component_subcategory.name + deleted(product.component_subcategory) : '—'}
      </p>
      {product.brand && (
        <p style={line}>
          <strong>{t('common.fields.brand')}:</strong> {product.brand} &nbsp; <strong>{t('inventory.fields.manufacturerPartNumber')}:</strong> {product.manufacturer_part_number ?? '—'}
        </p>
      )}
      <p style={line}>
        <strong>{t('inventory.fields.manufacturer')}:</strong> {product.manufacturer ?? '—'} &nbsp; <strong>{t('inventory.fields.material')}:</strong> {product.material ?? '—'} &nbsp;
        <strong>{t('inventory.fields.productionYear')}:</strong> {product.production_year ?? '—'}
      </p>
      <p style={line}>
        <strong>{t('inventory.fields.dimensionsLWHMm')}:</strong> {product.length_mm ? `${product.length_mm} × ${product.width_mm ?? '—'} × ${product.height_mm ?? '—'}` : '—'} &nbsp;
        <strong>{t('inventory.fields.weightKg')}:</strong> {product.weight_kg ?? '—'}
      </p>
      {product.product_type === 'TIRE' && product.tire_spec && <TireSpecification spec={product.tire_spec} />}
    </>
  );
  const removable = Boolean(imagePreviewUrl) && !product.is_system && hasPermission('product.delete');
  const image = (
    <div data-product-image>
      <ImageContainer src={imagePreviewUrl} alt={product.name} placeholder={canUpdate ? t('inventory.placeholders.noImageUseUploadImageJpg') : t('common.placeholders.noImage')} />
      {canUpdate && (
        <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
          <button type="button" className="btn-secondary" disabled={busy} onClick={() => fileRef.current?.click()}>
            {imagePreviewUrl ? t('inventory.actions.replaceImage') : t('common.actions.uploadImage')}
          </button>
          {removable && (
            <button type="button" className="btn-link" style={{ color: '#b91c1c' }} disabled={busy} onClick={() => run(() => apiClient.delete(`/app/products/${product.id}/image`))}>
              {t('inventory.actions.removeImage')}
            </button>
          )}
          <input
            ref={fileRef}
            type="file"
            accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            aria-label={t('inventory.fields.productImageFile')}
            style={{ display: 'none' }}
            onChange={(e) => {
              const f = e.target.files?.[0];
              e.target.value = '';
              if (!f) return;
              if (!['image/jpeg', 'image/png'].includes(f.type)) {
                setError(t('common.errors.onlyJpgPngImagesAccepted'));
                return;
              }
              uploadImage(f);
            }}
          />
        </div>
      )}
    </div>
  );

  return (
    <div className="card" style={{ marginBottom: 16 }} data-product-details>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12, flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0, fontSize: 15 }}>{t('common.sections.details')}</h3>
        {canUpdate && (
          <button className="btn-secondary" onClick={() => setEditing(true)}>
            {t('inventory.actions.editProduct')}
          </button>
        )}
      </div>
      {error && (
        <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginBottom: 8 }}>
          {error}
        </div>
      )}
      <DetailsWithImage details={details} image={image} />
      {product.is_system && <p style={{ fontSize: 12, color: '#9ca3af' }}>{t('inventory.help.platformSystemRecordReadOnly')}</p>}
      {product.product_type === 'TIRE' && (
        <div style={{ marginTop: 10 }}>
          <FormField label={t('inventory.fields.referenceTreadDepthMmRequiredBefore')}>
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <NumericInput
                step="0.01"
                min="0.01"
                value={referenceTreadDepthMm}
                onChange={(e) => setReferenceTreadDepthMm(e.target.value)}
                disabled={!canUpdate}
                style={{ ...inputStyle, width: 140 }}
              />
              {canUpdate && (
                <button className="btn-secondary" disabled={busy} onClick={() => run(() => apiClient.put(`/app/products/${product.id}`, { reference_tread_depth_mm: referenceTreadDepthMm || null }))}>
                  {t('common.actions.save')}
                </button>
              )}
            </div>
          </FormField>
        </div>
      )}
      {editing && (
        <EditProductModal
          product={product}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false);
            onChanged();
          }}
        />
      )}
    </div>
  );
}

function TireSpecification({ spec }: { spec: NonNullable<ProductItem['tire_spec']> }) {
  const item = (label: string, value: ReactNode) => (
    <span>
      <strong>{label}:</strong> {value ?? '—'}
    </span>
  );
  return (
    <p style={{ fontSize: 13, display: 'flex', flexWrap: 'wrap', gap: '4px 14px' }} data-tire-spec>
      {item(t('inventory.fields.tireSize'), spec.tire_size_computed)}
      {item(t('inventory.fields.rimDiameter'), spec.rim_diameter_inch != null ? `${spec.rim_diameter_inch}"` : null)}
      {item(t('inventory.fields.pattern'), spec.pattern_name)}
      {item(t('inventory.fields.vehicleGroup'), spec.vehicle_group === 'TRUCK_BUS' ? t('inventory.fields.truckAndBus') : spec.vehicle_group === 'OTR' ? t('inventory.fields.otrHeavyEquipment') : t('inventory.fields.car'))}
      {item(t('inventory.fields.construction'), spec.construction_type)}
      {item(t('inventory.fields.tireType'), spec.tire_type)}
      {item(t('inventory.fields.maxLoadSingle'), spec.single_max_load_kg_computed != null ? t('inventory.fields.dualMaxLoadKg', { dualMaxLoad: spec.single_max_load_kg_computed }) : null)}
      {item(t('inventory.fields.maxSpeed'), spec.max_speed_kmh_computed != null ? t('inventory.fields.maxSpeedKmH', { maxSpeed: spec.max_speed_kmh_computed }) : null)}
    </p>
  );
}
