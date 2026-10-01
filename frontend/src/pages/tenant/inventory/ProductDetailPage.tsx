import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ImageUploadField } from '../../../components/ImageUploadField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { EditProductModal } from './EditProductModal';
import { VehicleBrandModelSelect } from './VehicleBrandModelSelect';
import type { ProductItem, VehicleCategory } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { NumericInput } from '../../../components/NumericInput';

export function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [product, setProduct] = useState<ProductItem | null>(null);
  const [categories, setCategories] = useState<VehicleCategory[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [vehicleCategoryId, setVehicleCategoryId] = useState('');
  const [vehicleBrandId, setVehicleBrandId] = useState('');
  const [vehicleModelId, setVehicleModelId] = useState('');
  // Inline edit of an existing compatibility rule (Brand / Model restored from the stored ids).
  const [editingRule, setEditingRule] = useState<{ id: string; brandId: string; modelId: string; brandName: string | null; modelName: string | null } | null>(null);
  const [referenceTreadDepthMm, setReferenceTreadDepthMm] = useState('');
  const [editingSpecs, setEditingSpecs] = useState(false);
  const [sdsBusy, setSdsBusy] = useState(false);
  const sdsFileInputRef = useRef<HTMLInputElement>(null);
  const [imagePreviewUrl, setImagePreviewUrl] = useState<string | null>(null);

  function load() {
    apiClient
      .get(`/app/products/${id}`)
      .then((res) => setProduct(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data)).catch(() => setCategories([]));
  }, []);
  useEffect(() => {
    setReferenceTreadDepthMm(product?.reference_tread_depth_mm ?? '');
  }, [product?.reference_tread_depth_mm]);

  /** Batch 14: the upload replacing "Image URL" lives on the private
   * `local` disk, so its preview (unlike the legacy public `image_url`)
   * needs an authenticated blob fetch — same pattern as Vehicle Brand's logo. */
  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    if (product?.image_path) {
      apiClient.get(`/app/products/${product.id}/image`, { responseType: 'blob' }).then((res) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(res.data);
        setImagePreviewUrl(objectUrl);
      });
    } else if (product?.image_url) {
      setImagePreviewUrl(product.image_url);
    } else {
      setImagePreviewUrl(null);
    }

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [product?.id, product?.image_path, product?.image_url]);

  async function uploadImage(file: File) {
    setError(null);
    const form = new FormData();
    form.append('file', file);
    try {
      await apiClient.post(`/app/products/${id}/image`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function removeImage() {
    setError(null);
    try {
      await apiClient.delete(`/app/products/${id}/image`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function saveReferenceTreadDepth() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.put(`/app/products/${id}`, { reference_tread_depth_mm: referenceTreadDepthMm || null });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function addCompatibility() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/products/${id}/compatibilities`, {
        vehicle_category_id: vehicleCategoryId || undefined,
        vehicle_brand_id: vehicleBrandId || undefined,
        vehicle_model_id: vehicleModelId || undefined,
      });
      setVehicleCategoryId('');
      setVehicleBrandId('');
      setVehicleModelId('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function saveRule() {
    if (!editingRule) return;
    const rule = product?.compatibilities?.find((c) => c.id === editingRule.id);
    setBusy(true);
    setError(null);
    try {
      await apiClient.put(`/app/products/${id}/compatibilities/${editingRule.id}`, {
        component_group_id: rule?.component_group_id ?? null,
        vehicle_category_id: rule?.vehicle_category_id ?? null,
        vehicle_brand_id: editingRule.brandId || null,
        vehicle_model_id: editingRule.modelId || null,
      });
      setEditingRule(null);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function removeCompatibility(compatibilityId: string) {
    setBusy(true);
    try {
      await apiClient.delete(`/app/products/${id}/compatibilities/${compatibilityId}`);
      load();
    } finally {
      setBusy(false);
    }
  }

  /** "Next Improvement Tenant Portal - Products": Consumable's Safety Data Sheet — File Upload. */
  async function uploadSds(file: File) {
    setSdsBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('file', file);
      await apiClient.post(`/app/products/${id}/sds`, form, { headers: { 'Content-Type': 'multipart/form-data' } });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSdsBusy(false);
      if (sdsFileInputRef.current) sdsFileInputRef.current.value = '';
    }
  }

  async function downloadSds() {
    setSdsBusy(true);
    setError(null);
    try {
      const res = await apiClient.get(`/app/products/${id}/sds`, { responseType: 'blob' });
      const contentType = typeof res.headers['content-type'] === 'string' ? res.headers['content-type'] : 'application/octet-stream';
      const url = URL.createObjectURL(new Blob([res.data], { type: contentType }));
      window.open(url, '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSdsBusy(false);
    }
  }

  async function removeSds() {
    setSdsBusy(true);
    setError(null);
    try {
      await apiClient.delete(`/app/products/${id}/sds`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSdsBusy(false);
    }
  }

  useBreadcrumbLabel(product?.id, product?.name);

  if (error && !product) return <ErrorState message={error} />;
  if (!product) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/products" label="← Back to Product" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {product.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({product.code})</span>
        </h1>
        <StatusBadge status={product.status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
        <p style={{ fontSize: 13 }}>
          <strong>SKU:</strong> {product.sku} &nbsp; <strong>Type:</strong> {product.product_type} &nbsp; <strong>Category:</strong>{' '}
          {product.category?.name ?? '—'} &nbsp; <strong>UOM:</strong> {product.uom?.name ?? '—'}
          {(product.component_groups ?? []).length > 0 && (
            <>
              {' '}
              &nbsp; <strong>Component Groups:</strong>{' '}
              {(product.component_groups ?? []).map((g) => componentGroupLabel(g) + (g.deleted_at ? ' (deleted)' : '')).join(', ')}
            </>
          )}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Component Group:</strong> {product.component_group ? componentGroupLabel(product.component_group) + (product.component_group.deleted_at ? ' (deleted)' : '') : '—'}
          &nbsp; <strong>Category:</strong> {product.component_category ? product.component_category.name + (product.component_category.deleted_at ? ' (deleted)' : '') : '—'}
          &nbsp; <strong>Subcategory:</strong> {product.component_subcategory ? product.component_subcategory.name + (product.component_subcategory.deleted_at ? ' (deleted)' : '') : '—'}
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
          <strong>Dimensions (L×W×H mm):</strong>{' '}
          {product.length_mm ? `${product.length_mm} × ${product.width_mm ?? '—'} × ${product.height_mm ?? '—'}` : '—'} &nbsp;
          <strong>Weight (kg):</strong> {product.weight_kg ?? '—'}
        </p>
        <div style={{ marginTop: 8, maxWidth: 200 }}>
          <ImageUploadField
            images={imagePreviewUrl ? [{ id: 'product-image', previewUrl: imagePreviewUrl, name: product.name }] : []}
            onUpload={uploadImage}
            onRemove={!product.is_system && hasPermission('product.delete') ? removeImage : undefined}
            disabled={product.is_system || !hasPermission('product.update')}
            multiple={false}
          />
        </div>
        {product.is_system && <p style={{ fontSize: 12, color: '#9ca3af' }}>Platform system record — read-only.</p>}
        {!product.is_system && hasPermission('product.update') && (
          <div style={{ marginTop: 10 }}>
            <button className="btn-secondary" onClick={() => setEditingSpecs(true)}>
              Edit Product
            </button>
          </div>
        )}
        {product.product_type === 'TIRE' && (
          <div style={{ marginTop: 10 }}>
            <FormField label="Reference Tread Depth (mm) — required before this Tire product's tires can be scored">
              <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
                <NumericInput step="0.01" min="0.01" value={referenceTreadDepthMm}
                  onChange={(e) => setReferenceTreadDepthMm(e.target.value)}
                  disabled={product.is_system || !hasPermission('product.update')}
                  style={{ ...inputStyle, width: 140 }}
                />
                {!product.is_system && hasPermission('product.update') && (
                  <button className="btn-secondary" disabled={busy} onClick={saveReferenceTreadDepth}>
                    Save
                  </button>
                )}
              </div>
            </FormField>
          </div>
        )}
      </div>

      {product.product_type === 'CONSUMABLE' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Safety Data Sheet</h3>
          {product.consumable_spec?.sds_file_path ? (
            <p style={{ fontSize: 13 }}>
              <strong>File:</strong> {product.consumable_spec.sds_original_filename ?? 'Uploaded document'}
              &nbsp;
              <button className="btn-link" disabled={sdsBusy} onClick={downloadSds}>
                Download
              </button>
              {hasPermission('product.delete') && !product.is_system && (
                <>
                  &nbsp;
                  <button className="btn-link" style={{ color: '#b91c1c' }} disabled={sdsBusy} onClick={removeSds}>
                    Remove
                  </button>
                </>
              )}
            </p>
          ) : (
            <p style={{ fontSize: 13, color: '#6b7280' }}>No Safety Data Sheet uploaded.</p>
          )}
          {hasPermission('product.update') && !product.is_system && (
            <div style={{ marginTop: 8 }}>
              <input
                ref={sdsFileInputRef}
                type="file"
                accept=".jpg,.jpeg,.png,.webp,.pdf"
                disabled={sdsBusy}
                onChange={(e) => e.target.files?.[0] && uploadSds(e.target.files[0])}
              />
              <p style={{ fontSize: 11, color: '#9ca3af', marginTop: 4 }}>JPG, PNG, WEBP, or PDF — max 10MB. Uploading replaces the current file.</p>
            </div>
          )}
        </div>
      )}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Compatibility</h3>
        {(product.compatibilities ?? []).length === 0 && <EmptyState label="No compatibility rules — treated as universally compatible." />}
        {(product.compatibilities ?? []).map((c) => (
          <div key={c.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {editingRule?.id === c.id ? (
              <span style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                <VehicleBrandModelSelect
                  brandId={editingRule.brandId}
                  modelId={editingRule.modelId}
                  onChange={(brandId, modelId) => setEditingRule({ ...editingRule, brandId, modelId })}
                  anyLabel="Any"
                  current={{ brandName: editingRule.brandName, modelName: editingRule.modelName }}
                  width={150}
                />
                <button className="btn-secondary" disabled={busy} onClick={saveRule}>
                  Save
                </button>
                <button className="btn-link" disabled={busy} onClick={() => setEditingRule(null)}>
                  Cancel
                </button>
              </span>
            ) : (
              <span>
                {c.component_group ? componentGroupLabel(c.component_group) : 'Any component'} — {c.vehicle_category?.name ?? 'Any category'}
                {c.vehicle_brand && ` — ${c.brand_master?.name ?? c.vehicle_brand}`} {c.vehicle_model && ` ${c.model_master?.name ?? c.vehicle_model}`}
                {c.vehicle_brand && !c.vehicle_brand_id && <span style={{ color: '#9ca3af', fontSize: 11 }}> (legacy text — not linked to the Vehicle Brand master)</span>}
              </span>
            )}
            {hasPermission('product.update') && !product.is_system && editingRule?.id !== c.id && (
              <span style={{ display: 'flex', gap: 8 }}>
                <button
                  className="btn-link"
                  disabled={busy}
                  onClick={() =>
                    setEditingRule({
                      id: c.id,
                      brandId: c.vehicle_brand_id ?? '',
                      modelId: c.vehicle_model_id ?? '',
                      brandName: c.brand_master?.name ?? c.vehicle_brand,
                      modelName: c.model_master?.name ?? c.vehicle_model,
                    })
                  }
                >
                  Edit
                </button>
                <button className="btn-link" disabled={busy} onClick={() => removeCompatibility(c.id)}>
                  Remove
                </button>
              </span>
            )}
          </div>
        ))}
        {hasPermission('product.update') && !product.is_system && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Vehicle Category">
              <select value={vehicleCategoryId} onChange={(e) => setVehicleCategoryId(e.target.value)} style={{ ...inputStyle, width: 180 }}>
                <option value="">Any</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Brand / Model">
              <span style={{ display: 'flex', gap: 6 }}>
                <VehicleBrandModelSelect
                  brandId={vehicleBrandId}
                  modelId={vehicleModelId}
                  onChange={(brandId, modelId) => {
                    setVehicleBrandId(brandId);
                    setVehicleModelId(modelId);
                  }}
                  anyLabel="Any"
                  width={150}
                />
              </span>
            </FormField>
            <button className="btn-secondary" disabled={busy} onClick={addCompatibility} style={{ marginBottom: 14 }}>
              Add Rule
            </button>
          </div>
        )}
      </div>
      {editingSpecs && <EditProductModal product={product} onClose={() => setEditingSpecs(false)} onSaved={() => { setEditingSpecs(false); load(); }} />}
    </div>
  );
}
