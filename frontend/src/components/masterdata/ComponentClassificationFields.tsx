import { useEffect, useState } from 'react';
import { apiClient } from '../../api/client';
import { FormField, inputStyle } from '../FormField';
import type { ComponentCategory, ComponentGroup, ComponentSubcategory, ItemType } from '../../types';
import { componentGroupLabel } from '../../utils/componentGroup';
import type { ClassificationValue } from '../../utils/componentClassification';

const retired = (row: { status?: string; deleted_at?: string | null } | null | undefined) => !!row && (!!row.deleted_at || row.status === 'INACTIVE');

/**
 * Cascading Component Group -> Category -> Subcategory picker for the Product
 * form. Options come from the backend's effective-active lookups; choosing a
 * parent explicitly resets its children so no stale id is ever submitted.
 * Subcategories not applicable to the Item Type stay visible but disabled.
 * On Edit, a since-retired current value is still shown (marked) so an
 * untouched classification survives a save. The backend remains the
 * authority on hierarchy, availability and Item Type applicability.
 */
export function ComponentClassificationFields({
  itemType,
  value,
  onChange,
  errors,
  current,
}: {
  itemType: ItemType;
  value: ClassificationValue;
  onChange: (value: ClassificationValue) => void;
  errors: Record<string, string[]>;
  current?: { group?: ComponentGroup | null; category?: ComponentCategory | null; subcategory?: ComponentSubcategory | null };
}) {
  const [groups, setGroups] = useState<ComponentGroup[]>([]);
  const [categories, setCategories] = useState<ComponentCategory[]>([]);
  const [subcategories, setSubcategories] = useState<ComponentSubcategory[]>([]);

  useEffect(() => {
    apiClient.get('/app/product-classification/component-groups').then((res) => setGroups(res.data.data)).catch(() => setGroups([]));
  }, []);

  useEffect(() => {
    if (!value.componentGroupId) return;
    apiClient
      .get('/app/product-classification/categories', { params: { component_group_id: value.componentGroupId } })
      .then((res) => setCategories(res.data.data))
      .catch(() => setCategories([]));
  }, [value.componentGroupId]);

  useEffect(() => {
    if (!value.componentCategoryId) return;
    apiClient
      .get('/app/product-classification/subcategories', { params: { component_category_id: value.componentCategoryId, item_type: itemType } })
      .then((res) => setSubcategories(res.data.data))
      .catch(() => setSubcategories([]));
  }, [value.componentCategoryId, itemType]);

  const withCurrent = <T extends { id: string }>(rows: T[], row: T | null | undefined, selectedId: string): T[] =>
    row && row.id === selectedId && !rows.some((r) => r.id === row.id) ? [row, ...rows] : rows;

  const groupOptions = withCurrent(groups, current?.group ?? null, value.componentGroupId);
  // Children are only meaningful under the selected parent (stale lists from a previous parent are ignored).
  const liveCategories = categories.filter((c) => c.component_group_id === value.componentGroupId);
  const liveSubcategories = subcategories.filter((c) => c.component_category_id === value.componentCategoryId);
  const categoryOptions = withCurrent(liveCategories, current?.category ?? null, value.componentCategoryId);
  const subcategoryOptions = withCurrent(liveSubcategories, current?.subcategory ?? null, value.componentSubcategoryId);
  const selectedSub = subcategoryOptions.find((s) => s.id === value.componentSubcategoryId);

  return (
    <fieldset style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '10px 14px 2px', margin: '0 0 14px' }}>
      <legend style={{ fontSize: 13, fontWeight: 600, color: '#374151', padding: '0 6px' }}>Component Classification</legend>
      <FormField label="Component Group" errors={errors.component_group_id}>
        <select
          value={value.componentGroupId}
          onChange={(e) => onChange({ componentGroupId: e.target.value, componentCategoryId: '', componentSubcategoryId: '' })}
          style={inputStyle}
        >
          <option value="">— Not classified —</option>
          {groupOptions.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
              {retired(g) ? ' (retired)' : ''}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Category / Assembly" errors={errors.component_category_id}>
        <select
          value={value.componentCategoryId}
          disabled={!value.componentGroupId}
          onChange={(e) => onChange({ ...value, componentCategoryId: e.target.value, componentSubcategoryId: '' })}
          style={inputStyle}
        >
          <option value="">{value.componentGroupId ? 'Select…' : 'Select a Component Group first'}</option>
          {categoryOptions.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
              {retired(c) ? ' (retired)' : ''}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Subcategory / Component Family" errors={errors.component_subcategory_id}>
        <select
          value={value.componentSubcategoryId}
          disabled={!value.componentCategoryId}
          onChange={(e) => onChange({ ...value, componentSubcategoryId: e.target.value })}
          style={inputStyle}
        >
          <option value="">{value.componentCategoryId ? 'Select…' : 'Select a Category first'}</option>
          {subcategoryOptions.map((s) => (
            <option key={s.id} value={s.id} disabled={s.allowed === false && s.id !== value.componentSubcategoryId}>
              {s.name}
              {retired(s) ? ' (retired)' : ''}
              {s.allowed === false ? ` — not for ${itemType}` : ''}
            </option>
          ))}
        </select>
      </FormField>
      {selectedSub?.description && <div style={{ color: '#6b7280', fontSize: 12, marginTop: -8, marginBottom: 12 }}>{selectedSub.description}</div>}
    </fieldset>
  );
}
