import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
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

  if (error && !product) return <ErrorState message={error} />;
  if (!product) return <LoadingState />;

  return (
    <div>
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
              Edit Specifications
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
      {editingSpecs && <EditSpecsModal product={product} onClose={() => setEditingSpecs(false)} onSaved={() => { setEditingSpecs(false); load(); }} />}
    </div>
  );
}

function EditSpecsModal({ product, onClose, onSaved }: { product: ProductItem; onClose: () => void; onSaved: () => void }) {
  const [manufacturer, setManufacturer] = useState(product.manufacturer ?? '');
  const [material, setMaterial] = useState(product.material ?? '');
  const [productionYear, setProductionYear] = useState(product.production_year != null ? String(product.production_year) : '');
  const [weightKg, setWeightKg] = useState(product.weight_kg ?? '');
  const [lengthMm, setLengthMm] = useState(product.length_mm ?? '');
  const [widthMm, setWidthMm] = useState(product.width_mm ?? '');
  const [heightMm, setHeightMm] = useState(product.height_mm ?? '');
  const [imageUrl, setImageUrl] = useState(product.image_url ?? '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/products/${product.id}`, {
        manufacturer: manufacturer || null,
        material: material || null,
        production_year: productionYear || null,
        weight_kg: weightKg || null,
        length_mm: lengthMm || null,
        width_mm: widthMm || null,
        height_mm: heightMm || null,
        image_url: imageUrl || null,
      });
      onSaved();
    } catch (err) {
      const apiError = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Edit Product Specifications" onClose={onClose} width={560}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Manufacturer" errors={errors.manufacturer}>
          <input value={manufacturer} onChange={(e) => setManufacturer(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Material" errors={errors.material}>
          <input value={material} onChange={(e) => setMaterial(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Production Year" errors={errors.production_year}>
          <input type="number" value={productionYear} onChange={(e) => setProductionYear(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Weight (kg)" errors={errors.weight_kg}>
          <input type="number" step="0.001" value={weightKg} onChange={(e) => setWeightKg(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Length (mm)" errors={errors.length_mm}>
          <input type="number" value={lengthMm} onChange={(e) => setLengthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Width (mm)" errors={errors.width_mm}>
          <input type="number" value={widthMm} onChange={(e) => setWidthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Height (mm)" errors={errors.height_mm}>
          <input type="number" value={heightMm} onChange={(e) => setHeightMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Image URL" errors={errors.image_url}>
          <input value={imageUrl} onChange={(e) => setImageUrl(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
