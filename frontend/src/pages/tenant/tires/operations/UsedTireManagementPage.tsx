import { RETREAD_PERMISSIONS } from '../../../../layouts/tenantNav';
import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';

const TABS: WorkflowTab[] = [
  {
    key: 'retread',
    label: 'Retread',
    permissions: RETREAD_PERMISSIONS,
    statuses: ['RETREAD'],
    candidatesTitle: 'Tires in the retread cycle',
    actionLabel: 'Open retread',
    anchor: 'retread',
    activityTypes: ['RETREAD'],
    activityTitle: 'Recent retread cycles',
  },
  {
    key: 'scrap',
    label: 'Scrap',
    permissions: ['tire.scrap'],
    statuses: ['REMOVED', 'UNDER_INSPECTION', 'QUARANTINED'],
    candidatesTitle: 'Used tires that can be scrapped',
    actionLabel: 'Scrap',
    anchor: 'scrap',
    activityTypes: ['SCRAP'],
    activityTitle: 'Recently scrapped',
  },
];

/** Used Tire Management: Retread and Scrap of removed tires (replaces two menu aliases). */
export function UsedTireManagementPage() {
  return (
    <TireWorkflowTabs
      title="Used Tire Management"
      intro="Retread or scrap tires that were removed from service. Tires removed with disposition Retread appear under Retread; each action opens the tire."
      tabs={TABS}
    />
  );
}
