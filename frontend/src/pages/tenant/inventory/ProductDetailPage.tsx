import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { VehicleBrandModelSelect } from './VehicleBrandModelSelect';
import { ProductDetailsSection } from './ProductDetailsSection';
import type { ProductItem, VehicleCategory } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { t } from '../../../i18n/i18n';

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
      <BackButton fallbackTo="/app/products" label={t('inventory.actions.backToProduct')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {product.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({product.code})</span>
        </h1>
        <StatusBadge status={product.status} />
      </div>
      {error && <ErrorState message={error} />}

      <ProductDetailsSection product={product} onChanged={load} />
      {product.product_type === 'TIRE' && hasPermission('tire.view') && (
        <p style={{ fontSize: 13, margin: '-6px 0 16px' }} data-tire-detail-link>
          {t('inventory.help.physicalTiresProductNewStockInstalled')} <Link to={`/app/tires/products/${product.id}`}>{t('inventory.actions.tireDetail')}</Link>.
        </p>
      )}

      {product.product_type === 'CONSUMABLE' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inventory.sections.safetyDataSheet')}</h3>
          {product.consumable_spec?.sds_file_path ? (
            <p style={{ fontSize: 13 }}>
              <strong>{t('inventory.fields.file')}:</strong> {product.consumable_spec.sds_original_filename ?? t('inventory.fields.uploadedDocument')}
              &nbsp;
              <button className="btn-link" disabled={sdsBusy} onClick={downloadSds}>
                {t('common.actions.download')}
              </button>
              {hasPermission('product.delete') && !product.is_system && (
                <>
                  &nbsp;
                  <button className="btn-link" style={{ color: '#b91c1c' }} disabled={sdsBusy} onClick={removeSds}>
                    {t('common.actions.remove')}
                  </button>
                </>
              )}
            </p>
          ) : (
            <p style={{ fontSize: 13, color: '#6b7280' }}>{t('inventory.empty.noSafetyDataSheetUploaded')}</p>
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
              <p style={{ fontSize: 11, color: '#9ca3af', marginTop: 4 }}>{t('inventory.help.jpgPngWebpPdfMax10mb')}</p>
            </div>
          )}
        </div>
      )}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inventory.sections.vehicleCompatibility')}</h3>
        {(product.compatibilities ?? []).length === 0 && <EmptyState label={t('inventory.empty.noCompatibilityRulesTreatedUniversallyCompatible')} />}
        {(product.compatibilities ?? []).map((c) => (
          <div key={c.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {editingRule?.id === c.id ? (
              <span style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
                <VehicleBrandModelSelect
                  brandId={editingRule.brandId}
                  modelId={editingRule.modelId}
                  onChange={(brandId, modelId) => setEditingRule({ ...editingRule, brandId, modelId })}
                  anyLabel={t('inventory.filters.any')}
                  current={{ brandName: editingRule.brandName, modelName: editingRule.modelName }}
                  width={150}
                />
                <button className="btn-secondary" disabled={busy} onClick={saveRule}>
                  {t('common.actions.save')}
                </button>
                <button className="btn-link" disabled={busy} onClick={() => setEditingRule(null)}>
                  {t('common.actions.cancel')}
                </button>
              </span>
            ) : (
              <span>
                {c.component_group ? componentGroupLabel(c.component_group) : t('inventory.fields.anyComponent')} — {c.vehicle_category?.name ?? t('inventory.fields.anyCategory')}
                {c.vehicle_brand && ` — ${c.brand_master?.name ?? c.vehicle_brand}`} {c.vehicle_model && ` ${c.model_master?.name ?? c.vehicle_model}`}
                {c.vehicle_brand && !c.vehicle_brand_id && <span style={{ color: '#9ca3af', fontSize: 11 }}> {t('inventory.help.legacyTextNotLinkedVehicleBrand')}</span>}
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
                  {t('common.actions.edit')}
                </button>
                <button className="btn-link" disabled={busy} onClick={() => removeCompatibility(c.id)}>
                  {t('common.actions.remove')}
                </button>
              </span>
            )}
          </div>
        ))}
        {hasPermission('product.update') && !product.is_system && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label={t('inspection.fields.vehicleCategory')}>
              <select value={vehicleCategoryId} onChange={(e) => setVehicleCategoryId(e.target.value)} style={{ ...inputStyle, width: 180 }}>
                <option value="">{t('inventory.filters.any')}</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label={t('inventory.fields.brandModel')}>
              <span style={{ display: 'flex', gap: 6 }}>
                <VehicleBrandModelSelect
                  brandId={vehicleBrandId}
                  modelId={vehicleModelId}
                  onChange={(brandId, modelId) => {
                    setVehicleBrandId(brandId);
                    setVehicleModelId(modelId);
                  }}
                  anyLabel={t('inventory.filters.any')}
                  width={150}
                />
              </span>
            </FormField>
            <button className="btn-secondary" disabled={busy} onClick={addCompatibility} style={{ marginBottom: 14 }}>
              {t('inventory.actions.addRule')}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
