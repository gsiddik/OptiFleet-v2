import { useEffect, useState, type ReactNode } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { ImageUploadField } from '../../../components/ImageUploadField';
import { NumericInput } from '../../../components/NumericInput';
import { useAuth } from '../../../auth/AuthContext';
import { EditProductModal } from './EditProductModal';
import type { ProductItem } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';

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

  const deleted = (row: { deleted_at?: string | null } | null | undefined) => (row?.deleted_at ? ' (deleted)' : '');

  return (
    <div className="card" style={{ marginBottom: 16 }} data-product-details>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
      {error && (
        <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginBottom: 8 }}>
          {error}
        </div>
      )}
      <p style={{ fontSize: 13 }}>
        <strong>SKU:</strong> {product.sku} &nbsp; <strong>Type:</strong> {product.product_type} &nbsp; <strong>Category:</strong> {product.category?.name ?? '—'} &nbsp;{' '}
        <strong>UOM:</strong> {product.uom?.name ?? '—'}
        {(product.component_groups ?? []).length > 0 && (
          <>
            {' '}
            &nbsp; <strong>Component Groups:</strong> {(product.component_groups ?? []).map((g) => componentGroupLabel(g) + deleted(g)).join(', ')}
          </>
        )}
      </p>
      <p style={{ fontSize: 13 }}>
        <strong>Component Group:</strong> {product.component_group ? componentGroupLabel(product.component_group) + deleted(product.component_group) : '—'}
        &nbsp; <strong>Category:</strong> {product.component_category ? product.component_category.name + deleted(product.component_category) : '—'}
        &nbsp; <strong>Subcategory:</strong> {product.component_subcategory ? product.component_subcategory.name + deleted(product.component_subcategory) : '—'}
      </p>
      {product.brand && (
        <p style={{ fontSize: 13 }}>
          <strong>Brand:</strong> {product.brand} &nbsp; <strong>Manufacturer Part #:</strong> {product.manufacturer_part_number ?? '—'}
        </p>
      )}
      <p style={{ fontSize: 13 }}>
        <strong>Manufacturer:</strong> {product.manufacturer ?? '—'} &nbsp; <strong>Material:</strong> {product.material ?? '—'} &nbsp;
        <strong>Production Year:</strong> {product.production_year ?? '—'}
      </p>
      <p style={{ fontSize: 13 }}>
        <strong>Dimensions (L×W×H mm):</strong> {product.length_mm ? `${product.length_mm} × ${product.width_mm ?? '—'} × ${product.height_mm ?? '—'}` : '—'} &nbsp;
        <strong>Weight (kg):</strong> {product.weight_kg ?? '—'}
      </p>
      {product.product_type === 'TIRE' && product.tire_spec && <TireSpecification spec={product.tire_spec} />}
      <div style={{ marginTop: 8, maxWidth: 200 }}>
        <ImageUploadField
          images={imagePreviewUrl ? [{ id: 'product-image', previewUrl: imagePreviewUrl, name: product.name }] : []}
          onUpload={uploadImage}
          onRemove={!product.is_system && hasPermission('product.delete') ? () => run(() => apiClient.delete(`/app/products/${product.id}/image`)) : undefined}
          disabled={!canUpdate}
          multiple={false}
        />
      </div>
      {product.is_system && <p style={{ fontSize: 12, color: '#9ca3af' }}>Platform system record — read-only.</p>}
      {canUpdate && (
        <div style={{ marginTop: 10 }}>
          <button className="btn-secondary" onClick={() => setEditing(true)}>
            Edit Product
          </button>
        </div>
      )}
      {product.product_type === 'TIRE' && (
        <div style={{ marginTop: 10 }}>
          <FormField label="Reference Tread Depth (mm) — required before this Tire product's tires can be scored">
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
                  Save
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
      {item('Tire Size', spec.tire_size_computed)}
      {item('Rim Diameter', spec.rim_diameter_inch != null ? `${spec.rim_diameter_inch}"` : null)}
      {item('Pattern', spec.pattern_name)}
      {item('Vehicle Group', spec.vehicle_group === 'TRUCK_BUS' ? 'Truck & Bus' : spec.vehicle_group === 'OTR' ? 'OTR / Heavy Equipment' : 'Car')}
      {item('Construction', spec.construction_type)}
      {item('Tire Type', spec.tire_type)}
      {item('Max Load (single)', spec.single_max_load_kg_computed != null ? `${spec.single_max_load_kg_computed} kg` : null)}
      {item('Max Speed', spec.max_speed_kmh_computed != null ? `${spec.max_speed_kmh_computed} km/h` : null)}
    </p>
  );
}
