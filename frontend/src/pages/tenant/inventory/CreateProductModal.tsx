import { useEffect, useMemo, useState } from 'react';
import { VehicleBrandModelSelect } from './VehicleBrandModelSelect';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ComponentClassificationFields } from '../../../components/masterdata/ComponentClassificationFields';
import { CATEGORY_REQUIRED_ITEM_TYPES, classificationPayload, emptyClassification, skuPreview, type ClassificationValue } from '../../../utils/componentClassification';
import type {
  EquipmentTypeItem,
  ItemType,
  ProductCategoryItem,
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

/**
 * Batch 14: the doc's Item Type dropdown is exactly these 6 values
 * ("Next Improvement Tenant Portal - Products": "Sparepart / Consumable
 * / Rim / Tire / Tool / Equipment"). OTHER predates this document (the
 * original Phase 4 catch-all, before RIM existed as its own type) and
 * is deliberately excluded here — see StoreProductRequest's matching
 * comment for the backend side of this decision.
 */
export const ITEM_TYPES: ItemType[] = ['SPARE_PART', 'TOOL', 'TIRE', 'CONSUMABLE', 'EQUIPMENT', 'RIM'];

const INTERVAL_UNITS = ['DAYS', 'WEEKS', 'MONTHS', 'YEARS'];

export type Spec = Record<string, unknown>;
// Brand / Model are Vehicle Brand / Vehicle Model master ids (dependent dropdowns), never free text.
export type CompatRow = { vehicle_brand_id: string; vehicle_model_id: string; variant: string; year_from: string; year_to: string; position: string };

export function emptyCompatRow(): CompatRow {
  return { vehicle_brand_id: '', vehicle_model_id: '', variant: '', year_from: '', year_to: '', position: '' };
}

export function splitCommaList(value: string): string[] {
  return value
    .split(',')
    .map((v) => v.trim())
    .filter(Boolean);
}

/**
 * Owner decision: Consumable Specification/Grade's Conditional-Mandatory trigger is the
 * Product's own Category/Subcategory — mirrors `product_category_id: subcategoryId ||
 * categoryId` (the value actually submitted): whichever leaf category row that resolves to
 * carries the authoritative `requires_specification_grade` flag (Superadmin-managed master
 * data, never a hardcoded name/code comparison here). The backend re-validates the same way —
 * this is UX only.
 */
export function gradeSpecificationRequired(
  categories: ProductCategoryItem[],
  subcategories: ProductCategoryItem[],
  categoryId: string,
  subcategoryId: string,
): boolean {
  const effectiveId = subcategoryId || categoryId;
  const match = subcategories.find((c) => c.id === effectiveId) ?? categories.find((c) => c.id === effectiveId);

  return match?.requires_specification_grade ?? false;
}

/**
 * "Next Improvement Tenant Portal - Products": the Dynamic Product Form.
 * General Information (Section: all Item Types) always renders first;
 * selecting an Item Type reveals that type's own specification section
 * (progressive disclosure). All Mandatory/Optional/Conditional-Mandatory
 * rules are re-enforced server-side by ProductSpecificationService — this
 * form mirrors them for UX only, it is never the authority.
 */
/** Where the form is opened: PRODUCT = Products (any Item Type); TIRE = Tires → "New Tire" (Item Type and Component Group fixed). */
export type ProductCreationContext = 'PRODUCT' | 'TIRE';
const TIRE_COMPONENT_GROUP_CODE = 'CG-TYRE';

export function CreateProductModal({
  open,
  onClose,
  onCreated,
  context = 'PRODUCT',
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
  context?: ProductCreationContext;
}) {
  const initialItemType: ItemType = context === 'TIRE' ? 'TIRE' : 'SPARE_PART';
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

  const [itemType, setItemType] = useState<ItemType>(initialItemType);
  const [name, setName] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [subcategoryId, setSubcategoryId] = useState('');
  const [description, setDescription] = useState('');
  const [uomId, setUomId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [zoneId, setZoneId] = useState('');
  const [rackId, setRackId] = useState('');
  const [binId, setBinId] = useState('');
  const [brand, setBrand] = useState('');
  const [trackSerialNumber, setTrackSerialNumber] = useState(false);
  const [trackBatch, setTrackBatch] = useState(false);
  const [referenceTreadDepthMm, setReferenceTreadDepthMm] = useState('');
  const [spec, setSpec] = useState<Spec>({});
  const [compatibilities, setCompatibilities] = useState<CompatRow[]>([emptyCompatRow()]);
  const [classification, setClassification] = useState<ClassificationValue>(emptyClassification);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const setSpecField = (key: string, value: unknown) => setSpec((s) => ({ ...s, [key]: value }));

  // Reset the type-specific parts of the form whenever the Item Type changes.
  useEffect(() => {
    setCategoryId('');
    setSubcategoryId('');
    // Keep the chosen Component Group / Category; the Subcategory's Item Type
    // applicability may differ, so it is re-chosen.
    setClassification((c) => ({ ...c, componentSubcategoryId: '' }));
    setSpec({});
    setCompatibilities([emptyCompatRow()]);
    setBrand('');
    setTrackSerialNumber(false);
    setTrackBatch(false);
    setReferenceTreadDepthMm('');
  }, [itemType]);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/uoms', { params: { per_page: 100 } }).then((res) => setUoms(res.data.data));
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data));
    apiClient.get('/app/tool-types', { params: { per_page: 100 } }).then((res) => setToolTypes(res.data.data));
    apiClient.get('/app/equipment-types', { params: { per_page: 100 } }).then((res) => setEquipmentTypes(res.data.data));
    apiClient.get('/app/storage-requirements', { params: { per_page: 100 } }).then((res) => setStorageRequirements(res.data.data));
    apiClient.get('/app/tire-load-indices', { params: { per_page: 100 } }).then((res) => setLoadIndices(res.data.data));
    apiClient.get('/app/tire-speed-ratings', { params: { per_page: 100 } }).then((res) => setSpeedRatings(res.data.data));
    apiClient.get('/app/tire-ply-ratings', { params: { per_page: 100 } }).then((res) => setPlyRatings(res.data.data));
    apiClient.get('/app/tire-tra-codes', { params: { per_page: 100 } }).then((res) => setTraCodes(res.data.data));
  }, [open]);

  // Category is scoped by Item Type (Section: General Information).
  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/product-categories', { params: { per_page: 200, item_type: itemType, parent_id: '' } }).then((res) => setCategories(res.data.data));
  }, [open, itemType]);

  // Subcategory is filtered by the selected Category.
  useEffect(() => {
    if (!categoryId) {
      setSubcategories([]);
      setSubcategoryId('');
      return;
    }
    apiClient.get('/app/product-categories', { params: { per_page: 200, parent_id: categoryId } }).then((res) => setSubcategories(res.data.data));
  }, [categoryId]);

  // Default Storage Location: Warehouse -> Zone -> Rack -> Bin.
  useEffect(() => {
    setZoneId('');
    setZones([]);
    if (!warehouseId) return;
    apiClient.get('/app/warehouse-zones', { params: { per_page: 200, warehouse_id: warehouseId } }).then((res) => setZones(res.data.data));
  }, [warehouseId]);
  useEffect(() => {
    setRackId('');
    setRacks([]);
    if (!zoneId) return;
    apiClient.get('/app/warehouse-racks', { params: { per_page: 200, warehouse_zone_id: zoneId } }).then((res) => setRacks(res.data.data));
  }, [zoneId]);
  useEffect(() => {
    setBinId('');
    setBins([]);
    if (!rackId) return;
    apiClient.get('/app/warehouse-bins', { params: { per_page: 200, warehouse_rack_id: rackId } }).then((res) => setBins(res.data.data));
  }, [rackId]);

  // Star Rating options depend on the selected TRA Code.
  const traCodeId = spec.tra_code_id as string | undefined;
  useEffect(() => {
    setSpecField('tra_star_rating_id', undefined);
    if (!traCodeId) {
      setStarRatings([]);
      return;
    }
    apiClient.get(`/app/tire-tra-codes/${traCodeId}`).then((res) => setStarRatings(res.data.data.star_ratings ?? []));
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

  function reset() {
    setItemType(initialItemType);
    setName('');
    setCategoryId('');
    setSubcategoryId('');
    setDescription('');
    setUomId('');
    setWarehouseId('');
    setZoneId('');
    setRackId('');
    setBinId('');
    setBrand('');
    setTrackSerialNumber(false);
    setTrackBatch(false);
    setReferenceTreadDepthMm('');
    setSpec({});
    setCompatibilities([emptyCompatRow()]);
    setClassification(emptyClassification);
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const specPayload: Spec = { ...spec };
      if (itemType === 'SPARE_PART' || itemType === 'RIM') {
        specPayload.compatibilities = compatibilities
          .filter((r) => r.vehicle_brand_id || r.vehicle_model_id)
          .map((r) => ({
            vehicle_brand_id: r.vehicle_brand_id,
            vehicle_model_id: r.vehicle_model_id,
            variant: r.variant || undefined,
            year_from: r.year_from || undefined,
            year_to: r.year_to || undefined,
            position: r.position || undefined,
          }));
      }

      await apiClient.post('/app/products', {
        name,
        product_category_id: subcategoryId || categoryId,
        product_type: itemType,
        uom_id: uomId,
        default_storage_bin_id: binId,
        description: description || undefined,
        brand: brand || undefined,
        track_serial_number: needsField('track_serial_number', itemType) ? trackSerialNumber : undefined,
        track_batch: itemType === 'CONSUMABLE' ? trackBatch : undefined,
        reference_tread_depth_mm: itemType === 'TIRE' && referenceTreadDepthMm ? referenceTreadDepthMm : undefined,
        spec: specPayload,
        ...classificationPayload(classification),
        creation_context: context === 'TIRE' ? 'TIRE' : undefined,
      });
      reset();
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  function updateCompatRow(index: number, patch: Partial<CompatRow>) {
    setCompatibilities((rows) => rows.map((r, i) => (i === index ? { ...r, ...patch } : r)));
  }

  const categoryRequired = CATEGORY_REQUIRED_ITEM_TYPES.includes(itemType);
  const canSubmit = name && (subcategoryId || categoryId) && uomId && binId && (!categoryRequired || classification.componentCategoryId) && !submitting;

  return (
    <Modal open={open} title={context === 'TIRE' ? 'New Tire' : 'New Product'} onClose={onClose} width={680}>
      <FormField label="Code" errors={errors.code}>
        <input value="Auto-generated on save" disabled style={{ ...inputStyle, color: '#888' }} />
      </FormField>
      <FormField label="Item Type" errors={errors.product_type} required>
        <select aria-label="Item Type" value={itemType} disabled={context === 'TIRE'} onChange={(e) => setItemType(e.target.value as ItemType)} style={inputStyle}>
          {ITEM_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Item Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="SKU" errors={errors.sku}>
        <input value={`${skuPreview(itemType, classification)} (auto-generated on save)`} disabled style={{ ...inputStyle, color: '#888', fontFamily: 'monospace' }} />
      </FormField>
      <FormField label="Category" errors={errors.product_category_id} required>
        <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </FormField>
      {subcategories.length > 0 && (
        <FormField label="Subcategory" errors={errors.product_category_id}>
          <select value={subcategoryId} onChange={(e) => setSubcategoryId(e.target.value)} style={inputStyle}>
            <option value="">None</option>
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
        requireCategory={categoryRequired}
        lockedGroupCode={context === 'TIRE' ? TIRE_COMPONENT_GROUP_CODE : undefined}
      />
      <FormField label="Description" errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <FormField label="Base UOM" errors={errors.uom_id} required>
        <select value={uomId} onChange={(e) => setUomId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {uoms.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </select>
      </FormField>

      <FormField label="Default Storage Location" errors={errors.default_storage_bin_id} required>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 6 }}>
          <select value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)} style={inputStyle}>
            <option value="">Warehouse…</option>
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
          <select value={zoneId} onChange={(e) => setZoneId(e.target.value)} style={inputStyle} disabled={!warehouseId}>
            <option value="">Zone…</option>
            {zones.map((z) => (
              <option key={z.id} value={z.id}>
                {z.name}
              </option>
            ))}
          </select>
          <select value={rackId} onChange={(e) => setRackId(e.target.value)} style={inputStyle} disabled={!zoneId}>
            <option value="">Rack…</option>
            {racks.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
          <select value={binId} onChange={(e) => setBinId(e.target.value)} style={inputStyle} disabled={!rackId}>
            <option value="">Bin…</option>
            {bins.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </div>
        {((warehouseId && zones.length === 0) || (zoneId && racks.length === 0) || (rackId && bins.length === 0)) && (
          <div style={{ fontSize: 12, color: '#b45309', marginTop: 4 }}>
            No storage location here yet — set up Zones, Racks and Bins under Organization → Warehouses → Storage Layout.
          </div>
        )}
      </FormField>

      {itemType !== 'OTHER' && (
        <FormField label="Brand / Manufacturer" errors={errors.brand} required={brandRequired(itemType)}>
          <input value={brand} onChange={(e) => setBrand(e.target.value)} style={inputStyle} />
        </FormField>
      )}

      {needsField('track_serial_number', itemType) && (
        <FormField label="Serialized" errors={errors.track_serial_number} required>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <input type="checkbox" checked={trackSerialNumber} onChange={(e) => setTrackSerialNumber(e.target.checked)} /> Each unit requires serial tracking
          </label>
        </FormField>
      )}

      <hr style={{ margin: '18px 0', border: 0, borderTop: '1px solid #e5e7eb' }} />

      {itemType === 'SPARE_PART' && (
        <SparepartFields spec={spec} setSpecField={setSpecField} errors={errors} compatibilities={compatibilities} updateCompatRow={updateCompatRow} setCompatibilities={setCompatibilities} />
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
      {itemType === 'RIM' && <RimFields spec={spec} setSpecField={setSpecField} errors={errors} compatibilities={compatibilities} updateCompatRow={updateCompatRow} setCompatibilities={setCompatibilities} />}
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
          Cancel
        </button>
        <button className="btn-primary" disabled={!canSubmit} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}

export function brandRequired(itemType: ItemType): boolean {
  return ['SPARE_PART', 'RIM', 'TIRE', 'EQUIPMENT'].includes(itemType);
}

export function needsField(field: 'track_serial_number', itemType: ItemType): boolean {
  if (field === 'track_serial_number') return ['SPARE_PART', 'RIM', 'TOOL', 'EQUIPMENT'].includes(itemType);
  return false;
}

function IntervalPair({
  label,
  requiredLabel,
  required,
  onRequiredChange,
  value,
  unit,
  onValueChange,
  onUnitChange,
  errorKey,
  errors,
}: {
  label: string;
  requiredLabel: string;
  required: boolean;
  onRequiredChange: (v: boolean) => void;
  value: string;
  unit: string;
  onValueChange: (v: string) => void;
  onUnitChange: (v: string) => void;
  errorKey: string;
  errors: Record<string, string[]>;
}) {
  return (
    <>
      <FormField label={label} required>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={required} onChange={(e) => onRequiredChange(e.target.checked)} /> {requiredLabel}
        </label>
      </FormField>
      {required && (
        <FormField label={`${label} Interval`} errors={errors[errorKey]} required>
          <div style={{ display: 'flex', gap: 6 }}>
            <NumericInput min="1" value={value} onChange={(e) => onValueChange(e.target.value)} style={inputStyle} />
            <select value={unit} onChange={(e) => onUnitChange(e.target.value)} style={inputStyle}>
              <option value="">Unit…</option>
              {INTERVAL_UNITS.map((u) => (
                <option key={u} value={u}>
                  {u}
                </option>
              ))}
            </select>
          </div>
        </FormField>
      )}
    </>
  );
}

/** Optional "Number + Unit Dropdown" pair with no required/enabling toggle (e.g. Warranty Period, Shelf Life). */
function NumberUnitField({
  label,
  value,
  unit,
  onValueChange,
  onUnitChange,
  errorKey,
  errors,
}: {
  label: string;
  value: string;
  unit: string;
  onValueChange: (v: string) => void;
  onUnitChange: (v: string) => void;
  errorKey: string;
  errors: Record<string, string[]>;
}) {
  return (
    <FormField label={label} errors={errors[errorKey]}>
      <div style={{ display: 'flex', gap: 6 }}>
        <NumericInput min="0" value={value} onChange={(e) => onValueChange(e.target.value)} style={inputStyle} />
        <select value={unit} onChange={(e) => onUnitChange(e.target.value)} style={inputStyle}>
          <option value="">Unit…</option>
          {INTERVAL_UNITS.map((u) => (
            <option key={u} value={u}>
              {u}
            </option>
          ))}
        </select>
      </div>
    </FormField>
  );
}

function CompatibilityRows({
  compatibilities,
  updateCompatRow,
  setCompatibilities,
  required,
  errors = {},
}: {
  compatibilities: CompatRow[];
  updateCompatRow: (i: number, patch: Partial<CompatRow>) => void;
  setCompatibilities: (rows: CompatRow[]) => void;
  required: boolean;
  errors?: Record<string, string[]>;
}) {
  // Backend keys: "compatibilities" (list-level) and "compatibilities.{i}.{field}" (row-level).
  const rowErrors = (i: number) => Object.entries(errors).filter(([k]) => k.startsWith(`compatibilities.${i}.`)).flatMap(([, v]) => v);
  return (
    <FormField label="Vehicle Compatibility" required={required} errors={errors.compatibilities}>
      {compatibilities.map((row, i) => (
        <div key={i}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6, 1fr) auto', gap: 4, marginBottom: 6 }}>
          <VehicleBrandModelSelect
            brandId={row.vehicle_brand_id}
            modelId={row.vehicle_model_id}
            onChange={(brandId, modelId) => updateCompatRow(i, { vehicle_brand_id: brandId, vehicle_model_id: modelId })}
          />
          <input placeholder="Variant" value={row.variant} onChange={(e) => updateCompatRow(i, { variant: e.target.value })} style={inputStyle} />
          <NumericInput placeholder="Year From" value={row.year_from} onChange={(e) => updateCompatRow(i, { year_from: e.target.value })} style={inputStyle} />
          <NumericInput placeholder="Year To" value={row.year_to} onChange={(e) => updateCompatRow(i, { year_to: e.target.value })} style={inputStyle} />
          <input placeholder="Position" value={row.position} onChange={(e) => updateCompatRow(i, { position: e.target.value })} style={inputStyle} />
          <button className="btn-secondary" onClick={() => setCompatibilities(compatibilities.filter((_, idx) => idx !== i))} disabled={compatibilities.length === 1}>
            ×
          </button>
        </div>
        {rowErrors(i).map((err) => (
          <div key={err} style={{ color: '#b91c1c', fontSize: 12, marginTop: -2, marginBottom: 6 }}>
            Row {i + 1}: {err.replace(/compatibilities\.\d+\./, '')}
          </div>
        ))}
        </div>
      ))}
      <button className="btn-secondary" onClick={() => setCompatibilities([...compatibilities, emptyCompatRow()])} style={{ fontSize: 12 }}>
        + Add Compatibility
      </button>
    </FormField>
  );
}

export function SparepartFields({
  spec,
  setSpecField,
  errors,
  compatibilities,
  updateCompatRow,
  setCompatibilities,
  showCompatibility = true,
}: {
  spec: Spec;
  setSpecField: (k: string, v: unknown) => void;
  errors: Record<string, string[]>;
  compatibilities: CompatRow[];
  updateCompatRow: (i: number, patch: Partial<CompatRow>) => void;
  setCompatibilities: (rows: CompatRow[]) => void;
  showCompatibility?: boolean;
}) {
  return (
    <>
      <FormField label="Part Number" errors={errors['spec.part_number']} required>
        <input value={(spec.part_number as string) ?? ''} onChange={(e) => setSpecField('part_number', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Part Type" errors={errors['spec.part_type']} required>
        <select value={(spec.part_type as string) ?? ''} onChange={(e) => setSpecField('part_type', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {['GENUINE', 'OEM', 'OES', 'AFTERMARKET'].map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="OEM Part Number" errors={errors['spec.oem_part_number']}>
        <input value={(spec.oem_part_number as string) ?? ''} onChange={(e) => setSpecField('oem_part_number', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Alternate Part Number" errors={errors['spec.alternate_part_numbers']}>
        <input
          placeholder="Comma-separated, e.g. ALT-1, ALT-2"
          value={((spec.alternate_part_numbers as string[]) ?? []).join(', ')}
          onChange={(e) => setSpecField('alternate_part_numbers', splitCommaList(e.target.value))}
          style={inputStyle}
        />
      </FormField>
      <FormField label="Specification" errors={errors['spec.specification']}>
        <textarea value={(spec.specification as string) ?? ''} onChange={(e) => setSpecField('specification', e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <FormField label="Applicable Position" errors={errors['spec.applicable_position']}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
          {['FRONT', 'REAR', 'LEFT', 'RIGHT', 'UPPER', 'LOWER', 'INNER', 'OUTER'].map((pos) => {
            const selected = (spec.applicable_position as string[]) ?? [];
            return (
              <label key={pos} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                <input
                  type="checkbox"
                  checked={selected.includes(pos)}
                  onChange={(e) => setSpecField('applicable_position', e.target.checked ? [...selected, pos] : selected.filter((p) => p !== pos))}
                />{' '}
                {pos}
              </label>
            );
          })}
        </div>
      </FormField>
      <FormField label="Critical Part" errors={errors['spec.critical_part']}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={(spec.critical_part as boolean) ?? false} onChange={(e) => setSpecField('critical_part', e.target.checked)} /> Safety / operation critical
        </label>
      </FormField>
      <NumberUnitField
        label="Warranty Period"
        value={(spec.warranty_period_value as string) ?? ''}
        unit={(spec.warranty_period_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('warranty_period_value', v)}
        onUnitChange={(v) => setSpecField('warranty_period_unit', v)}
        errorKey="spec.warranty_period_value"
        errors={errors}
      />
      <FormField label="Warranty Mileage (km)" errors={errors['spec.warranty_mileage_km']}>
        <NumericInput min="0" value={(spec.warranty_mileage_km as string) ?? ''} onChange={(e) => setSpecField('warranty_mileage_km', e.target.value)} style={inputStyle} />
      </FormField>
      <NumberUnitField
        label="Shelf Life"
        value={(spec.shelf_life_value as string) ?? ''}
        unit={(spec.shelf_life_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('shelf_life_value', v)}
        onUnitChange={(v) => setSpecField('shelf_life_unit', v)}
        errorKey="spec.shelf_life_value"
        errors={errors}
      />
      {showCompatibility && (
        <CompatibilityRows compatibilities={compatibilities} updateCompatRow={updateCompatRow} setCompatibilities={setCompatibilities} required errors={errors} />
      )}
    </>
  );
}

export function ConsumableFields({
  spec,
  setSpecField,
  errors,
  uoms,
  storageRequirements,
  trackBatch,
  setTrackBatch,
  gradeRequired = false,
}: {
  spec: Spec;
  setSpecField: (k: string, v: unknown) => void;
  errors: Record<string, string[]>;
  uoms: UomItem[];
  storageRequirements: StorageRequirementItem[];
  trackBatch: boolean;
  setTrackBatch: (v: boolean) => void;
  gradeRequired?: boolean;
}) {
  const trackExpiry = (spec.track_expiry as boolean) ?? false;
  const isHazardous = (spec.is_hazardous as boolean) ?? false;
  const selectedStorageReqs = (spec.storage_requirement_ids as string[]) ?? [];

  return (
    <>
      <FormField label="Batch Tracking" errors={errors.track_batch} required>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={trackBatch} onChange={(e) => setTrackBatch(e.target.checked)} /> Yes
        </label>
      </FormField>
      <FormField label="Specification / Grade" errors={errors['spec.grade_specification']} required={gradeRequired}>
        <input placeholder="e.g. SAE 15W-40, DOT 4" value={(spec.grade_specification as string) ?? ''} onChange={(e) => setSpecField('grade_specification', e.target.value)} style={inputStyle} />
        {/* Owner decision: required when the selected Category/Subcategory's
            requires_specification_grade flag is set (Superadmin-managed master data) — see
            ProductSpecificationService::validateConsumable() for the authoritative backend rule. */}
      </FormField>
      <FormField label="Package Size" errors={errors['spec.package_size_value']}>
        <div style={{ display: 'flex', gap: 6 }}>
          <NumericInput
            step="0.001"
            placeholder="e.g. 20"
            value={(spec.package_size_value as string) ?? ''}
            onChange={(e) => setSpecField('package_size_value', e.target.value)}
            style={inputStyle}
          />
          <select value={(spec.package_size_uom_id as string) ?? ''} onChange={(e) => setSpecField('package_size_uom_id', e.target.value || undefined)} style={inputStyle}>
            <option value="">UOM…</option>
            {uoms.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </select>
        </div>
      </FormField>
      <FormField label="Purchase UOM" errors={errors['spec.purchase_uom_id']}>
        <select value={(spec.purchase_uom_id as string) ?? ''} onChange={(e) => setSpecField('purchase_uom_id', e.target.value || undefined)} style={inputStyle}>
          <option value="">None</option>
          {uoms.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </select>
      </FormField>
      {!!spec.purchase_uom_id && (
        <FormField label="Conversion to Base UOM" errors={errors['spec.conversion_to_base_uom']} required>
          <NumericInput step="0.0001" value={(spec.conversion_to_base_uom as string) ?? ''} onChange={(e) => setSpecField('conversion_to_base_uom', e.target.value)} style={inputStyle} />
        </FormField>
      )}
      <FormField label="Issue UOM" errors={errors['spec.issue_uom_id']}>
        <select value={(spec.issue_uom_id as string) ?? ''} onChange={(e) => setSpecField('issue_uom_id', e.target.value || undefined)} style={inputStyle}>
          <option value="">None</option>
          {uoms.map((u) => (
            <option key={u.id} value={u.id}>
              {u.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Expiry Tracking" errors={errors.track_expiry} required>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={trackExpiry} onChange={(e) => setSpecField('track_expiry', e.target.checked)} /> Yes
        </label>
      </FormField>
      {trackExpiry && (
        <FormField label="Shelf Life" errors={errors['spec.shelf_life_value']} required>
          <div style={{ display: 'flex', gap: 6 }}>
            <NumericInput value={(spec.shelf_life_value as string) ?? ''} onChange={(e) => setSpecField('shelf_life_value', e.target.value)} style={inputStyle} />
            <select value={(spec.shelf_life_unit as string) ?? ''} onChange={(e) => setSpecField('shelf_life_unit', e.target.value)} style={inputStyle}>
              <option value="">Unit…</option>
              {INTERVAL_UNITS.map((u) => (
                <option key={u} value={u}>
                  {u}
                </option>
              ))}
            </select>
          </div>
        </FormField>
      )}
      <FormField label="Hazardous Material" errors={errors.is_hazardous} required>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={isHazardous} onChange={(e) => setSpecField('is_hazardous', e.target.checked)} /> Yes
        </label>
      </FormField>
      {isHazardous && (
        <FormField label="Storage Requirement" errors={errors['spec.storage_requirement_ids']} required>
          {storageRequirements.map((s) => (
            <label key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
              <input
                type="checkbox"
                checked={selectedStorageReqs.includes(s.id)}
                onChange={(e) =>
                  setSpecField('storage_requirement_ids', e.target.checked ? [...selectedStorageReqs, s.id] : selectedStorageReqs.filter((id) => id !== s.id))
                }
              />{' '}
              {s.name}
            </label>
          ))}
        </FormField>
      )}
    </>
  );
}

export function RimFields({
  spec,
  setSpecField,
  errors,
  compatibilities,
  updateCompatRow,
  setCompatibilities,
  showCompatibility = true,
}: {
  spec: Spec;
  setSpecField: (k: string, v: unknown) => void;
  errors: Record<string, string[]>;
  compatibilities: CompatRow[];
  updateCompatRow: (i: number, patch: Partial<CompatRow>) => void;
  setCompatibilities: (rows: CompatRow[]) => void;
  showCompatibility?: boolean;
}) {
  return (
    <>
      <FormField label="Model" errors={errors['spec.model']}>
        <input value={(spec.model as string) ?? ''} onChange={(e) => setSpecField('model', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Rim Type" errors={errors['spec.rim_type']} required>
        <select value={(spec.rim_type as string) ?? ''} onChange={(e) => setSpecField('rim_type', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {['STEEL', 'ALLOY', 'FORGED'].map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Diameter (inch)" errors={errors['spec.diameter_inch']} required>
        <NumericInput step="0.01" value={(spec.diameter_inch as string) ?? ''} onChange={(e) => setSpecField('diameter_inch', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Width (inch)" errors={errors['spec.width_inch']} required>
        <NumericInput step="0.01" value={(spec.width_inch as string) ?? ''} onChange={(e) => setSpecField('width_inch', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Bolt Holes" errors={errors['spec.bolt_holes']} required>
        <NumericInput value={(spec.bolt_holes as string) ?? ''} onChange={(e) => setSpecField('bolt_holes', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="PCD (mm)" errors={errors['spec.pcd_mm']} required>
        <NumericInput step="0.01" value={(spec.pcd_mm as string) ?? ''} onChange={(e) => setSpecField('pcd_mm', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Center Bore (mm)" errors={errors['spec.center_bore_mm']}>
        <NumericInput step="0.01" value={(spec.center_bore_mm as string) ?? ''} onChange={(e) => setSpecField('center_bore_mm', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Offset (mm)" errors={errors['spec.offset_mm']}>
        <NumericInput step="0.01" value={(spec.offset_mm as string) ?? ''} onChange={(e) => setSpecField('offset_mm', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Material" errors={errors['spec.material']}>
        <input value={(spec.material as string) ?? ''} onChange={(e) => setSpecField('material', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Maximum Load (kg)" errors={errors['spec.max_load_kg']}>
        <NumericInput step="0.01" value={(spec.max_load_kg as string) ?? ''} onChange={(e) => setSpecField('max_load_kg', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Compatible Tire Size" errors={errors['spec.compatible_tire_sizes']}>
        <input
          placeholder="Comma-separated, e.g. 185/70 R14, 195/65 R14"
          value={((spec.compatible_tire_sizes as string[]) ?? []).join(', ')}
          onChange={(e) => setSpecField('compatible_tire_sizes', splitCommaList(e.target.value))}
          style={inputStyle}
        />
      </FormField>
      {showCompatibility && (
        <CompatibilityRows compatibilities={compatibilities} updateCompatRow={updateCompatRow} setCompatibilities={setCompatibilities} required={false} errors={errors} />
      )}
    </>
  );
}

export function TireFields({
  spec,
  setSpecField,
  errors,
  loadIndices,
  speedRatings,
  plyRatings,
  traCodes,
  starRatings,
  preview,
  referenceTreadDepthMm,
  setReferenceTreadDepthMm,
}: {
  spec: Spec;
  setSpecField: (k: string, v: unknown) => void;
  errors: Record<string, string[]>;
  loadIndices: TireLoadIndexItem[];
  speedRatings: TireSpeedRatingItem[];
  plyRatings: TirePlyRatingItem[];
  traCodes: TireTraCodeItem[];
  starRatings: TireTraStarRatingItem[];
  preview: { tireSize: string | null; singleMaxLoad: string | null; maxSpeed: string | null; dualMaxLoad: string | null; loadRange: string | null; traProfile: string | null; purpose: string | null } | null;
  referenceTreadDepthMm: string;
  setReferenceTreadDepthMm: (v: string) => void;
}) {
  const vehicleGroup = (spec.vehicle_group as string) ?? 'CAR';
  const isTruckBus = vehicleGroup === 'TRUCK_BUS';

  return (
    <>
      <FormField label="Vehicle Group" errors={errors['spec.vehicle_group']} required>
        <select value={vehicleGroup} onChange={(e) => setSpecField('vehicle_group', e.target.value)} style={inputStyle}>
          <option value="CAR">Car</option>
          <option value="TRUCK_BUS">Truck & Bus</option>
        </select>
      </FormField>
      <FormField label="Product Name / Pattern" errors={errors['spec.pattern_name']} required>
        <input value={(spec.pattern_name as string) ?? ''} onChange={(e) => setSpecField('pattern_name', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Width (mm)" errors={errors['spec.width_mm']} required>
        <NumericInput value={(spec.width_mm as string) ?? ''} onChange={(e) => setSpecField('width_mm', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Aspect Ratio (%)" errors={errors['spec.aspect_ratio_percent']} required>
        <NumericInput value={(spec.aspect_ratio_percent as string) ?? ''} onChange={(e) => setSpecField('aspect_ratio_percent', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Construction Type" errors={errors['spec.construction_type']} required>
        <select value={(spec.construction_type as string) ?? ''} onChange={(e) => setSpecField('construction_type', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          <option value="RADIAL">Radial</option>
          <option value="BIAS">Bias</option>
        </select>
      </FormField>
      <FormField label="Rim Diameter (inch)" errors={errors['spec.rim_diameter_inch']} required>
        <NumericInput step="0.1" value={(spec.rim_diameter_inch as string) ?? ''} onChange={(e) => setSpecField('rim_diameter_inch', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Tire Type" errors={errors['spec.tire_type']} required>
        <select value={(spec.tire_type as string) ?? ''} onChange={(e) => setSpecField('tire_type', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          <option value="TUBELESS">Tubeless</option>
          <option value="TUBE_TYPE">Tube Type</option>
        </select>
      </FormField>
      <FormField label="Single Load Index" errors={errors['spec.single_load_index_id']} required>
        <select value={(spec.single_load_index_id as string) ?? ''} onChange={(e) => setSpecField('single_load_index_id', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {loadIndices.map((l) => (
            <option key={l.id} value={l.id}>
              {l.code} — {l.max_load_single_kg} kg
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Speed Rating" errors={errors['spec.speed_rating_id']} required>
        <select value={(spec.speed_rating_id as string) ?? ''} onChange={(e) => setSpecField('speed_rating_id', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {speedRatings.map((s) => (
            <option key={s.id} value={s.id}>
              {s.code} — {s.max_speed_kmh} km/h
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Reference Tread Depth (mm)" errors={errors.reference_tread_depth_mm}>
        <NumericInput
          step="0.01"
          min="0.01"
          value={referenceTreadDepthMm}
          onChange={(e) => setReferenceTreadDepthMm(e.target.value)}
          placeholder="e.g. 8.00 — required before this Tire product can be scored"
          style={inputStyle}
        />
      </FormField>

      {preview?.tireSize && (
        <div style={{ background: '#f9fafb', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10, marginBottom: 14, fontSize: 13 }}>
          <div>
            Tire Size: <strong>{preview.tireSize}</strong> (auto)
          </div>
          {preview.singleMaxLoad && (
            <div>
              Single Max Load: <strong>{preview.singleMaxLoad} kg</strong> (auto)
            </div>
          )}
          {preview.maxSpeed && (
            <div>
              Max Speed: <strong>{preview.maxSpeed} km/h</strong> (auto)
            </div>
          )}
          {isTruckBus && preview.dualMaxLoad && (
            <div>
              Dual Max Load: <strong>{preview.dualMaxLoad} kg</strong> (auto)
            </div>
          )}
          {isTruckBus && preview.loadRange && (
            <div>
              Load Range: <strong>{preview.loadRange}</strong> (auto)
            </div>
          )}
          {isTruckBus && preview.traProfile && (
            <div>
              TRA Profile: <strong>{preview.traProfile}</strong> (auto)
            </div>
          )}
          {isTruckBus && preview.purpose && (
            <div>
              Purpose: <strong>{preview.purpose}</strong> (auto)
            </div>
          )}
        </div>
      )}

      {isTruckBus && (
        <>
          <FormField label="Dual Load Index" errors={errors['spec.dual_load_index_id']} required>
            <select value={(spec.dual_load_index_id as string) ?? ''} onChange={(e) => setSpecField('dual_load_index_id', e.target.value)} style={inputStyle}>
              <option value="">Select…</option>
              {loadIndices.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.code} — {l.max_load_dual_kg} kg
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Ply Rating" errors={errors['spec.ply_rating_id']} required>
            <select value={(spec.ply_rating_id as string) ?? ''} onChange={(e) => setSpecField('ply_rating_id', e.target.value)} style={inputStyle}>
              <option value="">Select…</option>
              {plyRatings.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.code} ({p.load_range})
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="TRA Code" errors={errors['spec.tra_code_id']}>
            <select value={(spec.tra_code_id as string) ?? ''} onChange={(e) => setSpecField('tra_code_id', e.target.value || undefined)} style={inputStyle}>
              <option value="">None</option>
              {traCodes.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.code}
                </option>
              ))}
            </select>
          </FormField>
          {!!spec.tra_code_id && (
            <FormField label="Star Rating" errors={errors['spec.tra_star_rating_id']} required>
              <select value={(spec.tra_star_rating_id as string) ?? ''} onChange={(e) => setSpecField('tra_star_rating_id', e.target.value)} style={inputStyle}>
                <option value="">Select…</option>
                {starRatings.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.star_rating}★ — {s.purpose}
                  </option>
                ))}
              </select>
            </FormField>
          )}
        </>
      )}
    </>
  );
}

export function ToolFields({ spec, setSpecField, errors, toolTypes }: { spec: Spec; setSpecField: (k: string, v: unknown) => void; errors: Record<string, string[]>; toolTypes: ToolTypeItem[] }) {
  return (
    <>
      <FormField label="Tool Type" errors={errors['spec.tool_type_id']} required>
        <select value={(spec.tool_type_id as string) ?? ''} onChange={(e) => setSpecField('tool_type_id', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {toolTypes.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Model" errors={errors['spec.model']}>
        <input value={(spec.model as string) ?? ''} onChange={(e) => setSpecField('model', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Specification" errors={errors['spec.specification']}>
        <textarea value={(spec.specification as string) ?? ''} onChange={(e) => setSpecField('specification', e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <FormField label="Checkout Required" errors={errors['spec.checkout_required']} required>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={(spec.checkout_required as boolean) ?? false} onChange={(e) => setSpecField('checkout_required', e.target.checked)} /> Loaned out to mechanics
        </label>
      </FormField>
      <IntervalPair
        label="Calibration"
        requiredLabel="Calibration required"
        required={(spec.calibration_required as boolean) ?? false}
        onRequiredChange={(v) => setSpecField('calibration_required', v)}
        value={(spec.calibration_interval_value as string) ?? ''}
        unit={(spec.calibration_interval_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('calibration_interval_value', v)}
        onUnitChange={(v) => setSpecField('calibration_interval_unit', v)}
        errorKey="spec.calibration_interval_value"
        errors={errors}
      />
      <IntervalPair
        label="Maintenance"
        requiredLabel="Maintenance required"
        required={(spec.maintenance_required as boolean) ?? false}
        onRequiredChange={(v) => setSpecField('maintenance_required', v)}
        value={(spec.maintenance_interval_value as string) ?? ''}
        unit={(spec.maintenance_interval_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('maintenance_interval_value', v)}
        onUnitChange={(v) => setSpecField('maintenance_interval_unit', v)}
        errorKey="spec.maintenance_interval_value"
        errors={errors}
      />
    </>
  );
}

export function EquipmentFields({
  spec,
  setSpecField,
  errors,
  equipmentTypes,
  uoms,
}: {
  spec: Spec;
  setSpecField: (k: string, v: unknown) => void;
  errors: Record<string, string[]>;
  equipmentTypes: EquipmentTypeItem[];
  uoms: UomItem[];
}) {
  return (
    <>
      <FormField label="Model" errors={errors['spec.model']} required>
        <input value={(spec.model as string) ?? ''} onChange={(e) => setSpecField('model', e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Equipment Type" errors={errors['spec.equipment_type_id']} required>
        <select value={(spec.equipment_type_id as string) ?? ''} onChange={(e) => setSpecField('equipment_type_id', e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {equipmentTypes.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Specification" errors={errors['spec.specification']}>
        <textarea value={(spec.specification as string) ?? ''} onChange={(e) => setSpecField('specification', e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <FormField label="Capacity" errors={errors['spec.capacity_value']}>
        <div style={{ display: 'flex', gap: 6 }}>
          <NumericInput step="0.01" value={(spec.capacity_value as string) ?? ''} onChange={(e) => setSpecField('capacity_value', e.target.value)} style={inputStyle} />
          <select value={(spec.capacity_uom_id as string) ?? ''} onChange={(e) => setSpecField('capacity_uom_id', e.target.value || undefined)} style={inputStyle}>
            <option value="">UOM…</option>
            {uoms.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </select>
        </div>
      </FormField>
      <FormField label="Power Source" errors={errors['spec.power_source']}>
        <select value={(spec.power_source as string) ?? ''} onChange={(e) => setSpecField('power_source', e.target.value || undefined)} style={inputStyle}>
          <option value="">None</option>
          {['ELECTRIC', 'HYDRAULIC', 'PNEUMATIC', 'FUEL', 'MANUAL'].map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Power Rating" errors={errors['spec.power_rating_value']}>
        <div style={{ display: 'flex', gap: 6 }}>
          <NumericInput step="0.01" value={(spec.power_rating_value as string) ?? ''} onChange={(e) => setSpecField('power_rating_value', e.target.value)} style={inputStyle} />
          <select value={(spec.power_rating_unit as string) ?? ''} onChange={(e) => setSpecField('power_rating_unit', e.target.value || undefined)} style={inputStyle}>
            <option value="">Unit…</option>
            <option value="KW">kW</option>
            <option value="HP">HP</option>
          </select>
        </div>
      </FormField>
      <FormField label="Voltage (V)" errors={errors['spec.voltage_v']}>
        <NumericInput value={(spec.voltage_v as string) ?? ''} onChange={(e) => setSpecField('voltage_v', e.target.value)} style={inputStyle} />
      </FormField>
      <IntervalPair
        label="Maintenance"
        requiredLabel="Maintenance required"
        required={(spec.maintenance_required as boolean) ?? false}
        onRequiredChange={(v) => setSpecField('maintenance_required', v)}
        value={(spec.maintenance_interval_value as string) ?? ''}
        unit={(spec.maintenance_interval_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('maintenance_interval_value', v)}
        onUnitChange={(v) => setSpecField('maintenance_interval_unit', v)}
        errorKey="spec.maintenance_interval_value"
        errors={errors}
      />
      <IntervalPair
        label="Inspection"
        requiredLabel="Inspection required"
        required={(spec.inspection_required as boolean) ?? false}
        onRequiredChange={(v) => setSpecField('inspection_required', v)}
        value={(spec.inspection_interval_value as string) ?? ''}
        unit={(spec.inspection_interval_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('inspection_interval_value', v)}
        onUnitChange={(v) => setSpecField('inspection_interval_unit', v)}
        errorKey="spec.inspection_interval_value"
        errors={errors}
      />
      <IntervalPair
        label="Calibration"
        requiredLabel="Calibration required"
        required={(spec.calibration_required as boolean) ?? false}
        onRequiredChange={(v) => setSpecField('calibration_required', v)}
        value={(spec.calibration_interval_value as string) ?? ''}
        unit={(spec.calibration_interval_unit as string) ?? ''}
        onValueChange={(v) => setSpecField('calibration_interval_value', v)}
        onUnitChange={(v) => setSpecField('calibration_interval_unit', v)}
        errorKey="spec.calibration_interval_value"
        errors={errors}
      />
      <FormField label="Certification Required" errors={errors['spec.certification_required']}>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <input type="checkbox" checked={(spec.certification_required as boolean) ?? false} onChange={(e) => setSpecField('certification_required', e.target.checked)} /> Yes
        </label>
      </FormField>
      {!!spec.certification_required && (
        <FormField label="Certification Type" errors={errors['spec.certification_type']} required>
          <input value={(spec.certification_type as string) ?? ''} onChange={(e) => setSpecField('certification_type', e.target.value)} style={inputStyle} />
        </FormField>
      )}
    </>
  );
}
