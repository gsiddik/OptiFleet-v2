import { useEffect, useMemo, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ComponentClassificationFields } from '../../../components/masterdata/ComponentClassificationFields';
import { CATEGORY_REQUIRED_ITEM_TYPES, classificationPayload, type ClassificationValue } from '../../../utils/componentClassification';
import {
  ConsumableFields,
  EquipmentFields,
  RimFields,
  SparepartFields,
  TireFields,
  ToolFields,
  brandRequired,
  gradeSpecificationRequired,
  needsField,
  type Spec,
} from './CreateProductModal';
import type {
  EquipmentTypeItem,
  ProductCategoryItem,
  ProductItem,
  StorageRequirementItem,
  TireLoadIndexItem,
  TirePlyRatingItem,
  TireSpeedRatingItem,
  TireTraCodeItem,
  TireTraStarRatingItem,
  ToolTypeItem,
  UomItem,
  Warehouse,
  WarehouseBinItem,
  WarehouseRackItem,
  WarehouseZoneItem,
} from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { t as tt } from '../../../i18n/i18n';

const SPEC_RELATION_KEY: Record<string, keyof ProductItem> = {
  SPARE_PART: 'sparepart_spec',
  CONSUMABLE: 'consumable_spec',
  RIM: 'rim_spec',
  TIRE: 'tire_spec',
  TOOL: 'tool_spec',
  EQUIPMENT: 'equipment_spec',
};

/**
 * Section 15 (Product Edit Dynamic Form): Edit must reconstruct the correct schema — load
 * the Item Type's spec form and hydrate the existing values, not show a generic/empty form.
 * Item Type and Item Code both stay immutable (Section 13); Vehicle Compatibility keeps using
 * its own existing add/remove UI on the Product detail page, so this form never touches it.
 * Reuses the exact same field components as Create so the two forms can never drift apart.
 */
export function EditProductModal({ product, onClose, onSaved }: { product: ProductItem; onClose: () => void; onSaved: () => void }) {
  const itemType = product.product_type;
  const [categories, setCategories] = useState<ProductCategoryItem[]>([]);
  const [subcategories, setSubcategories] = useState<ProductCategoryItem[]>([]);
  const [uoms, setUoms] = useState<UomItem[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [zones, setZones] = useState<WarehouseZoneItem[]>([]);
  const [racks, setRacks] = useState<WarehouseRackItem[]>([]);
  const [bins, setBins] = useState<WarehouseBinItem[]>([]);
  const [toolTypes, setToolTypes] = useState<ToolTypeItem[]>([]);
  const [equipmentTypes, setEquipmentTypes] = useState<EquipmentTypeItem[]>([]);
  const [storageRequirements, setStorageRequirements] = useState<StorageRequirementItem[]>([]);
  const [loadIndices, setLoadIndices] = useState<TireLoadIndexItem[]>([]);
  const [speedRatings, setSpeedRatings] = useState<TireSpeedRatingItem[]>([]);
  const [plyRatings, setPlyRatings] = useState<TirePlyRatingItem[]>([]);
  const [traCodes, setTraCodes] = useState<TireTraCodeItem[]>([]);
  const [starRatings, setStarRatings] = useState<TireTraStarRatingItem[]>([]);

  const [name, setName] = useState(product.name);
  const [categoryId, setCategoryId] = useState('');
  const [subcategoryId, setSubcategoryId] = useState('');
  const [description, setDescription] = useState(product.description ?? '');
  const [uomId, setUomId] = useState(product.uom_id);
  const [warehouseId, setWarehouseId] = useState('');
  const [zoneId, setZoneId] = useState('');
  const [rackId, setRackId] = useState('');
  const [binId, setBinId] = useState(product.default_storage_bin_id ?? '');
  const [active, setActive] = useState(product.status === 'ACTIVE');
  const [brand, setBrand] = useState(product.brand ?? '');
  const [trackSerialNumber, setTrackSerialNumber] = useState(product.track_serial_number);
  const [trackBatch, setTrackBatch] = useState(product.track_batch);
  const [referenceTreadDepthMm, setReferenceTreadDepthMm] = useState(product.reference_tread_depth_mm ?? '');
  const [spec, setSpec] = useState<Spec>(() => (product[SPEC_RELATION_KEY[itemType]] as unknown as Spec) ?? {});
  // Legacy physical-attribute fields, predating the Dynamic Product Form — not part of the
  // authoritative General Information field list, but pre-existing and still editable (removing
  // this capability without a documented plan would be a backward-compatibility regression).
  const [manufacturer, setManufacturer] = useState(product.manufacturer ?? '');
  const [material, setMaterial] = useState(product.material ?? '');
  const [productionYear, setProductionYear] = useState(product.production_year != null ? String(product.production_year) : '');
  const [weightKg, setWeightKg] = useState(product.weight_kg ?? '');
  const [lengthMm, setLengthMm] = useState(product.length_mm ?? '');
  const [widthMm, setWidthMm] = useState(product.width_mm ?? '');
  const [heightMm, setHeightMm] = useState(product.height_mm ?? '');
  const [classification, setClassification] = useState<ClassificationValue>({
    componentGroupId: product.component_group_id ?? '',
    componentGroupAbbreviation: product.component_group?.abbreviation ?? null,
    componentCategoryId: product.component_category_id ?? '',
    componentSubcategoryId: product.component_subcategory_id ?? '',
  });
  const [locationHydrated, setLocationHydrated] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const setSpecField = (key: string, value: unknown) => setSpec((s) => ({ ...s, [key]: value }));

  // Category is scoped by this product's (immutable) Item Type.
  useEffect(() => {
    apiClient.get('/app/product-categories', { params: { per_page: 200, item_type: itemType, parent_id: '' } }).then((res) => setCategories(res.data.data));
  }, [itemType]);

  // Hydrate Category/Subcategory from the product's own (leaf) category: if it has a parent,
  // the leaf is the Subcategory and the parent is the Category; otherwise it's a top-level Category.
  useEffect(() => {
    const cat = product.category;
    if (!cat) return;
    if (cat.parent_id) {
      setCategoryId(cat.parent_id);
      apiClient.get('/app/product-categories', { params: { per_page: 200, parent_id: cat.parent_id } }).then((res) => {
        setSubcategories(res.data.data);
        setSubcategoryId(cat.id);
      });
    } else {
      setCategoryId(cat.id);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Subcategory list follows whichever Category is currently selected (including after the
  // user picks a different one — matches Create's behavior).
  useEffect(() => {
    if (!categoryId) {
      setSubcategories([]);
      setSubcategoryId('');
      return;
    }
    apiClient.get('/app/product-categories', { params: { per_page: 200, parent_id: categoryId } }).then((res) => setSubcategories(res.data.data));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [categoryId]);

  useEffect(() => {
    apiClient.get('/app/uoms', { params: { per_page: 100 } }).then((res) => setUoms(res.data.data));
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data));
    if (itemType === 'TOOL') apiClient.get('/app/tool-types', { params: { per_page: 100 } }).then((res) => setToolTypes(res.data.data));
    if (itemType === 'EQUIPMENT') apiClient.get('/app/equipment-types', { params: { per_page: 100 } }).then((res) => setEquipmentTypes(res.data.data));
    if (itemType === 'CONSUMABLE') apiClient.get('/app/storage-requirements', { params: { per_page: 100 } }).then((res) => setStorageRequirements(res.data.data));
    if (itemType === 'TIRE') {
      apiClient.get('/app/tire-load-indices', { params: { per_page: 100 } }).then((res) => setLoadIndices(res.data.data));
      apiClient.get('/app/tire-speed-ratings', { params: { per_page: 100 } }).then((res) => setSpeedRatings(res.data.data));
      apiClient.get('/app/tire-ply-ratings', { params: { per_page: 100 } }).then((res) => setPlyRatings(res.data.data));
      apiClient.get('/app/tire-tra-codes', { params: { per_page: 100 } }).then((res) => setTraCodes(res.data.data));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Resolve the existing Bin's Warehouse->Zone->Rack ancestry once, so the cascading pickers
  // below open pre-selected rather than empty. The bin/rack/zone list endpoints have no
  // single-record lookup, so this walks the chain from the already-loaded bin relation.
  useEffect(() => {
    if (!product.default_storage_bin_id || !product.default_storage_bin) {
      setLocationHydrated(true);
      return;
    }
    const rackId2 = product.default_storage_bin.warehouse_rack_id;
    apiClient.get('/app/warehouse-racks', { params: { per_page: 200 } }).then((racksRes) => {
      const rack = (racksRes.data.data as WarehouseRackItem[]).find((r) => r.id === rackId2);
      if (!rack) {
        setLocationHydrated(true);
        return;
      }
      apiClient.get('/app/warehouse-zones', { params: { per_page: 200 } }).then((zonesRes) => {
        const zone = (zonesRes.data.data as WarehouseZoneItem[]).find((z) => z.id === rack.warehouse_zone_id);
        if (zone) {
          setWarehouseId(zone.warehouse_id);
          setZoneId(zone.id);
          setRackId(rack.id);
        }
        setLocationHydrated(true);
      });
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Default Storage Location cascade — identical to Create, but skipped for the very first
  // render of each level until the ancestry hydration above has had a chance to seed it, so
  // it doesn't wipe out the just-hydrated selection before the user touches anything.
  useEffect(() => {
    if (!locationHydrated) return;
    apiClient.get('/app/warehouse-zones', { params: { per_page: 200, warehouse_id: warehouseId || undefined } }).then((res) => setZones(warehouseId ? res.data.data : []));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [locationHydrated, warehouseId]);
  useEffect(() => {
    if (!locationHydrated) return;
    apiClient.get('/app/warehouse-racks', { params: { per_page: 200, warehouse_zone_id: zoneId || undefined } }).then((res) => setRacks(zoneId ? res.data.data : []));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [locationHydrated, zoneId]);
  useEffect(() => {
    if (!locationHydrated) return;
    apiClient.get('/app/warehouse-bins', { params: { per_page: 200, warehouse_rack_id: rackId || undefined } }).then((res) => setBins(rackId ? res.data.data : []));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [locationHydrated, rackId]);

  // Star Rating options depend on the selected TRA Code — hydrate once from the product's
  // existing selection, then follow the same dependency the Create form uses.
  const traCodeId = spec.tra_code_id as string | undefined;
  const [traCodeHydrated, setTraCodeHydrated] = useState(itemType !== 'TIRE');
  useEffect(() => {
    if (!traCodeId) {
      setStarRatings([]);
      setTraCodeHydrated(true);
      return;
    }
    apiClient.get(`/app/tire-tra-codes/${traCodeId}`).then((res) => {
      setStarRatings(res.data.data.star_ratings ?? []);
      setTraCodeHydrated(true);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [traCodeId]);
  useEffect(() => {
    if (!traCodeHydrated) return;
    setSpecField('tra_star_rating_id', spec.tra_star_rating_id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [traCodeId]);

  const tireDerivedPreview = useMemo(() => {
    if (itemType !== 'TIRE') return null;
    const width = spec.width_mm as string | undefined;
    const aspect = spec.aspect_ratio_percent as string | undefined;
    const rimDiameter = spec.rim_diameter_inch as string | undefined;
    const single = loadIndices.find((l) => l.id === spec.single_load_index_id);
    const speed = speedRatings.find((s) => s.id === spec.speed_rating_id);
    const dual = loadIndices.find((l) => l.id === spec.dual_load_index_id);
    const ply = plyRatings.find((p) => p.id === spec.ply_rating_id);
    const traCode = traCodes.find((t) => t.id === spec.tra_code_id);
    const star = starRatings.find((s) => s.id === spec.tra_star_rating_id);
    const tireSize = width && aspect && rimDiameter ? `${width}/${aspect} R${parseFloat(rimDiameter)}` : null;

    return {
      tireSize,
      singleMaxLoad: single?.max_load_single_kg ?? null,
      maxSpeed: speed?.max_speed_kmh ?? null,
      dualMaxLoad: dual?.max_load_dual_kg ?? null,
      loadRange: ply?.load_range ?? null,
      traProfile: traCode?.profile ?? null,
      purpose: star?.purpose ?? null,
    };
  }, [itemType, spec, loadIndices, speedRatings, plyRatings, traCodes, starRatings]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/products/${product.id}`, {
        name,
        product_category_id: subcategoryId || categoryId,
        uom_id: uomId,
        default_storage_bin_id: binId,
        description: description || null,
        status: active ? 'ACTIVE' : 'INACTIVE',
        manufacturer: manufacturer || null,
        material: material || null,
        production_year: productionYear || null,
        weight_kg: weightKg || null,
        length_mm: lengthMm || null,
        width_mm: widthMm || null,
        height_mm: heightMm || null,
        brand: itemType !== 'OTHER' ? brand || null : undefined,
        track_serial_number: needsField('track_serial_number', itemType) ? trackSerialNumber : undefined,
        track_batch: itemType === 'CONSUMABLE' ? trackBatch : undefined,
        reference_tread_depth_mm: itemType === 'TIRE' ? referenceTreadDepthMm || null : undefined,
        spec: SPEC_RELATION_KEY[itemType] ? spec : undefined,
        // Unchanged values (even if since retired) are accepted as-is; only a changed
        // classification is re-validated. The SKU is never affected.
        ...classificationPayload(classification),
      });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  const canSubmit = name && (subcategoryId || categoryId) && uomId && binId && !submitting;

  return (
    <Modal open title={tt('inventory.modals.editName', { name: product.name })} onClose={onClose} width={680}>
      <FormField label={tt('common.fields.code')}>
        <input value={product.code} disabled style={{ ...inputStyle, color: '#888' }} />
      </FormField>
      <FormField label={tt('common.fields.itemType')}>
        <input value={itemType} disabled style={{ ...inputStyle, color: '#888' }} />
      </FormField>
      <FormField label={tt('inventory.fields.itemName')} errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={tt('common.fields.category')} errors={errors.product_category_id} required>
        <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </FormField>
      {subcategories.length > 0 && (
        <FormField label={tt('inventory.fields.subcategory')} errors={errors.product_category_id}>
          <select value={subcategoryId} onChange={(e) => setSubcategoryId(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.none')}</option>
            {subcategories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      <ComponentClassificationFields
        itemType={itemType}
        value={classification}
        onChange={setClassification}
        errors={errors}
        current={{ group: product.component_group, category: product.component_category, subcategory: product.component_subcategory }}
        requireCategory={CATEGORY_REQUIRED_ITEM_TYPES.includes(itemType) && !!product.component_category_id}
      />
      <FormField label={tt('common.fields.description')} errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label={tt('inventory.fields.baseUom')} errors={errors.uom_id} required>
        <select value={uomId} onChange={(e) => setUomId(e.target.value)} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {uoms.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </select>
      </FormField>

      <FormField label={tt('inventory.fields.defaultStorageLocation')} errors={errors.default_storage_bin_id} required>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 6 }}>
          <select value={warehouseId} onChange={(e) => { setWarehouseId(e.target.value); setZoneId(''); setRackId(''); setBinId(''); }} style={inputStyle}>
            <option value="">{tt('inventory.fields.warehouse')}</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
          <select value={zoneId} onChange={(e) => { setZoneId(e.target.value); setRackId(''); setBinId(''); }} style={inputStyle} disabled={!warehouseId}>
            <option value="">{tt('inventory.fields.zone')}</option>
            {zones.map((z) => (
              <option key={z.id} value={z.id}>
                {z.name}
              </option>
            ))}
          </select>
          <select value={rackId} onChange={(e) => { setRackId(e.target.value); setBinId(''); }} style={inputStyle} disabled={!zoneId}>
            <option value="">{tt('inventory.fields.rack')}</option>
            {racks.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
          <select value={binId} onChange={(e) => setBinId(e.target.value)} style={inputStyle} disabled={!rackId}>
            <option value="">{tt('inventory.fields.bin')}</option>
            {bins.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </div>
      </FormField>

      <FormField label={tt('common.fields.active')}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> {tt('common.fields.active')}
        </label>
      </FormField>

      <details style={{ marginBottom: 14 }}>
        <summary style={{ cursor: 'pointer', fontSize: 13, color: '#6b7280', marginBottom: 8 }}>{tt('inventory.sections.physicalAttributesOptional')}</summary>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 8 }}>
          <FormField label={tt('inventory.fields.manufacturer')} errors={errors.manufacturer}>
            <input value={manufacturer} onChange={(e) => setManufacturer(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label={tt('inventory.fields.material')} errors={errors.material}>
            <input value={material} onChange={(e) => setMaterial(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label={tt('inventory.fields.productionYear')} errors={errors.production_year}>
            <NumericInput value={productionYear} onChange={(e) => setProductionYear(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label={tt('inventory.fields.weightKg')} errors={errors.weight_kg}>
            <NumericInput step="0.001" value={weightKg} onChange={(e) => setWeightKg(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label={tt('inventory.fields.lengthMm')} errors={errors.length_mm}>
            <NumericInput value={lengthMm} onChange={(e) => setLengthMm(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label={tt('inventory.fields.widthMm')} errors={errors.width_mm}>
            <NumericInput value={widthMm} onChange={(e) => setWidthMm(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label={tt('inventory.fields.heightMm')} errors={errors.height_mm}>
            <NumericInput value={heightMm} onChange={(e) => setHeightMm(e.target.value)} style={inputStyle} />
          </FormField>
        </div>
      </details>
      {/* Batch 14: the raw "Image URL" text field is replaced by a real upload —
          see the "Product Image" card on the Product Detail page (same
          precedent as Consumable's Safety Data Sheet: an upload needs an
          existing Product ID, so it belongs outside this Create/Edit form). */}

      {itemType !== 'OTHER' && (
        <FormField label={tt('inventory.fields.brandManufacturer')} errors={errors.brand} required={brandRequired(itemType)}>
          <input value={brand} onChange={(e) => setBrand(e.target.value)} style={inputStyle} />
        </FormField>
      )}

      {needsField('track_serial_number', itemType) && (
        <FormField label={tt('inventory.fields.serialized')} errors={errors.track_serial_number} required>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <input type="checkbox" checked={trackSerialNumber} onChange={(e) => setTrackSerialNumber(e.target.checked)} /> {tt('inventory.fields.eachUnitRequiresSerialTracking')}
          </label>
        </FormField>
      )}

      {SPEC_RELATION_KEY[itemType] && (
        <>
          <hr style={{ margin: '18px 0', border: 0, borderTop: '1px solid #e5e7eb' }} />
          <p style={{ fontSize: 12, color: '#9ca3af', marginTop: -8, marginBottom: 12 }}>
            {tt('inventory.help.vehicleCompatibilityManagedSeparatelyBelowProduct')}
          </p>
        </>
      )}

      {itemType === 'SPARE_PART' && (
        <SparepartFields
          spec={spec}
          setSpecField={setSpecField}
          errors={errors}
          compatibilities={[]}
          updateCompatRow={() => {}}
          setCompatibilities={() => {}}
          showCompatibility={false}
        />
      )}
      {itemType === 'CONSUMABLE' && (
        <ConsumableFields
          spec={spec}
          setSpecField={setSpecField}
          errors={errors}
          uoms={uoms}
          storageRequirements={storageRequirements}
          trackBatch={trackBatch}
          setTrackBatch={setTrackBatch}
          gradeRequired={gradeSpecificationRequired(categories, subcategories, categoryId, subcategoryId)}
        />
      )}
      {itemType === 'RIM' && (
        <RimFields
          spec={spec}
          setSpecField={setSpecField}
          errors={errors}
          compatibilities={[]}
          updateCompatRow={() => {}}
          setCompatibilities={() => {}}
          showCompatibility={false}
        />
      )}
      {itemType === 'TIRE' && (
        <TireFields
          spec={spec}
          setSpecField={setSpecField}
          errors={errors}
          loadIndices={loadIndices}
          speedRatings={speedRatings}
          plyRatings={plyRatings}
          traCodes={traCodes}
          starRatings={starRatings}
          preview={tireDerivedPreview}
          referenceTreadDepthMm={referenceTreadDepthMm}
          setReferenceTreadDepthMm={setReferenceTreadDepthMm}
        />
      )}
      {itemType === 'TOOL' && <ToolFields spec={spec} setSpecField={setSpecField} errors={errors} toolTypes={toolTypes} />}
      {itemType === 'EQUIPMENT' && <EquipmentFields spec={spec} setSpecField={setSpecField} errors={errors} equipmentTypes={equipmentTypes} uoms={uoms} />}

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={!canSubmit} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
