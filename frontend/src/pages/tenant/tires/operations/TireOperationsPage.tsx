import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';
import { t, withLabels } from '../../../../i18n/i18n';

const TABS: WorkflowTab[] = withLabels([
  {
    key: 'installation',
    label: 'Installation', labelKey: 'tire.sections.installation',
    permissions: ['tire.install'],
    statuses: ['IN_STOCK', 'RESERVED'],
    get candidatesTitle() { return t('tire.sections.tiresAvailableToInstall'); },
    get actionLabel() { return t('tire.fields.install'); },
    anchor: 'install',
    activityTypes: ['INSTALLATION'],
    get activityTitle() { return t('tire.sections.recentInstallations'); },
  },
  {
    key: 'rotation',
    label: 'Rotation', labelKey: 'tire.sections.rotation',
    permissions: ['tire.rotate'],
    statuses: ['INSTALLED', 'IN_USE'],
    get candidatesTitle() { return t('tire.sections.installedTires'); },
    get actionLabel() { return t('tire.fields.rotate'); },
    anchor: 'in-service',
    activityTypes: ['ROTATION'],
    get activityTitle() { return t('tire.sections.recentRotations'); },
  },
  {
    key: 'inspection',
    label: 'Inspection', labelKey: 'breadcrumb.inspection',
    permissions: ['tire.inspect'],
    statuses: ['INSTALLED', 'IN_USE'],
    get candidatesTitle() { return t('tire.sections.installedTires'); },
    get actionLabel() { return t('tire.fields.inspect'); },
    anchor: 'in-service',
    activityTypes: ['INSPECTION'],
    get activityTitle() { return t('tire.sections.recentInspections'); },
  },
]);

/**
 * @deprecated Replaced by TireOperationsLandingPage (Recent Tire Operations + Add New Tire Operations).
 * ORPHANED: no longer routed or linked (owner decision); kept, unchanged, for a future decision.
 * Tire Operations: Installation, Rotation and Inspection in one place (replaces three menu aliases).
 */
export function TireOperationsPage() {
  return <TireWorkflowTabs title={t('tire.sections.tireOperations')} intro="Install, rotate and inspect tires. Each action opens the tire, where the step is recorded." tabs={TABS} />;
}
