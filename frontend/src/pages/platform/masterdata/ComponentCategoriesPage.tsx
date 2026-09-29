import { ComponentCategoryManager } from '../../../components/masterdata/ComponentTaxonomyManagers';

export function ComponentCategoriesPage() {
  return (
    <ComponentCategoryManager
      endpoints={{ groups: '/platform/component-groups', categories: '/platform/component-categories', subcategories: '/platform/component-subcategories' }}
      canManageRow={() => true}
      intro="Shared baseline taxonomy visible to every tenant. Edits and deletions here are never reverted by the seeder."
    />
  );
}
