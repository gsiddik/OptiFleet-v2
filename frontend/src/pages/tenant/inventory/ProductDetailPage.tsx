import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { EditProductModal } from './EditProductModal';
import type { ProductItem, VehicleCategory } from '../../../types';

export function ProductDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [product, setProduct] = useState<ProductItem | null>(null);
  const [categories, setCategories] = useState<VehicleCategory[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [vehicleCategoryId, setVehicleCategoryId] = useState('');
  const [vehicleBrand, setVehicleBrand] = useState('');
  const [vehicleModel, setVehicleModel] = useState('');
  const [referenceTreadDepthMm, setReferenceTreadDepthMm] = useState('');
  const [editingSpecs, setEditingSpecs] = useState(false);
  const [sdsBusy, setSdsBusy] = useState(false);
  const sdsFileInputRef = useRef<HTMLInputElement>(null);

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
        vehicle_brand: vehicleBrand || undefined,
        vehicle_model: vehicleModel || undefined,
      });
      setVehicleCategoryId('');
      setVehicleBrand('');
      setVehicleModel('');
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
        {product.image_url && <img src={product.image_url} alt={product.name} style={{ maxWidth: 200, marginTop: 8, borderRadius: 6 }} />}
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
                <input
                  type="number" step="0.01" min="0.01" value={referenceTreadDepthMm}
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
            <span>
              {c.component_group?.name ?? 'Any component'} — {c.vehicle_category?.name ?? 'Any category'}
              {c.vehicle_brand && ` — ${c.vehicle_brand}`} {c.vehicle_model && ` ${c.vehicle_model}`}
            </span>
            {hasPermission('product.update') && !product.is_system && (
              <button className="btn-link" disabled={busy} onClick={() => removeCompatibility(c.id)}>
                Remove
              </button>
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
            <FormField label="Brand">
              <input value={vehicleBrand} onChange={(e) => setVehicleBrand(e.target.value)} style={{ ...inputStyle, width: 130 }} />
            </FormField>
            <FormField label="Model">
              <input value={vehicleModel} onChange={(e) => setVehicleModel(e.target.value)} style={{ ...inputStyle, width: 130 }} />
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
