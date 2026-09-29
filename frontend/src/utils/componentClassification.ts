/** Product form state for the Component Group -> Category -> Subcategory picker. */
export interface ClassificationValue {
  componentGroupId: string;
  /** Display only (SKU preview); never submitted. */
  componentGroupAbbreviation?: string | null;
  componentCategoryId: string;
  componentSubcategoryId: string;
}

export const emptyClassification: ClassificationValue = { componentGroupId: '', componentCategoryId: '', componentSubcategoryId: '' };

export function classificationPayload(value: ClassificationValue) {
  return {
    component_group_id: value.componentGroupId || null,
    component_category_id: value.componentCategoryId || null,
    component_subcategory_id: value.componentSubcategoryId || null,
  };
}

/** Component Group + Category are mandatory for these Item Types (owner decision); Subcategory is always optional. */
export const CATEGORY_REQUIRED_ITEM_TYPES = ['SPARE_PART', 'CONSUMABLE', 'TIRE', 'RIM'];

/** Mirrors ProductSkuService::ITEM_TYPE_CODES — display only. */
export const SKU_ITEM_TYPE_CODES: Record<string, string> = {
  SPARE_PART: 'SPR',
  CONSUMABLE: 'CON',
  TIRE: 'TIR',
  RIM: 'RIM',
  TOOL: 'TOL',
  EQUIPMENT: 'EQP',
  OTHER: 'OTH',
};

/**
 * Preview of the SKU the backend will issue, e.g. "SPR-BRK-######". The number is
 * assigned by the server on save (the backend is the only authority); this only
 * shows the prefix the selected Item Type + Component Group produce.
 */
export function skuPreview(itemType: string, value: ClassificationValue): string {
  const code = SKU_ITEM_TYPE_CODES[itemType] ?? 'OTH';
  return value.componentGroupId && value.componentGroupAbbreviation ? `${code}-${value.componentGroupAbbreviation}-######` : `${code}-######`;
}
