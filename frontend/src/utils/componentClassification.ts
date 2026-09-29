/** Product form state for the Component Group -> Category -> Subcategory picker. */
export interface ClassificationValue {
  componentGroupId: string;
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
