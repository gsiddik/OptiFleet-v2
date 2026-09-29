import { ComponentGroupManager } from '../../../components/masterdata/ComponentGroupManager';

/**
 * Platform management of the shared Component Group baseline (tenant_id NULL)
 * every tenant sees. Edits made here are never overwritten by the seeder, and
 * deleted groups are never resurrected by it.
 */
export function ComponentGroupsPage() {
  return (
    <ComponentGroupManager
      apiBase="/platform/component-groups"
      canManageRow={() => true}
      intro="Shared baseline visible to every tenant. Abbreviations are 3-letter Product SKU elements: unique, never reused, and locked once Products use the group."
    />
  );
}
