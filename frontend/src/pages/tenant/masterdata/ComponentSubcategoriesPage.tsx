import { ComponentSubcategoryManager } from '../../../components/masterdata/ComponentTaxonomyManagers';

export function ComponentSubcategoriesPage() {
  return (
    <ComponentSubcategoryManager
      endpoints={{ groups: '/app/component-groups', categories: '/app/component-categories', subcategories: '/app/component-subcategories' }}
      canManageRow={(row) => !row.is_system}
      intro="System rows are the shared platform baseline (read-only here); rows your organization adds can be edited and deleted."
    />
  );
}
