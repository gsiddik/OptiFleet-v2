import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';

const TABS: WorkflowTab[] = [
  {
    key: 'installation',
    label: 'Installation',
    permissions: ['tire.install'],
    statuses: ['IN_STOCK', 'RESERVED'],
    candidatesTitle: 'Tires available to install',
    actionLabel: 'Install',
    anchor: 'install',
    activityTypes: ['INSTALLATION'],
    activityTitle: 'Recent installations',
  },
  {
    key: 'rotation',
    label: 'Rotation',
    permissions: ['tire.rotate'],
    statuses: ['INSTALLED', 'IN_USE'],
    candidatesTitle: 'Installed tires',
    actionLabel: 'Rotate',
    anchor: 'in-service',
    activityTypes: ['ROTATION'],
    activityTitle: 'Recent rotations',
  },
  {
    key: 'inspection',
    label: 'Inspection',
    permissions: ['tire.inspect'],
    statuses: ['INSTALLED', 'IN_USE'],
    candidatesTitle: 'Installed tires',
    actionLabel: 'Inspect',
    anchor: 'in-service',
    activityTypes: ['INSPECTION'],
    activityTitle: 'Recent inspections',
  },
];

/** Tire Operations: Installation, Rotation and Inspection in one place (replaces three menu aliases). */
export function TireOperationsPage() {
  return <TireWorkflowTabs title="Tire Operations" intro="Install, rotate and inspect tires. Each action opens the tire, where the step is recorded." tabs={TABS} />;
}
