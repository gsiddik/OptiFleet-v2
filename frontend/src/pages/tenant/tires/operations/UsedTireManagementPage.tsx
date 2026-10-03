import { RETREAD_PERMISSIONS } from '../../../../layouts/tenantNav';
import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';

const TABS: WorkflowTab[] = [
  {
    key: 'retread',
    label: 'Retread',
    permissions: RETREAD_PERMISSIONS,
    // Repair is a kind of retread (owner decision): both cycles live in this tab.
    statuses: ['RETREAD', 'REPAIR'],
    candidatesTitle: 'Tires in the retread / repair cycle',
    actionLabel: 'Open cycle',
    anchor: (tire) => (tire.current_status === 'REPAIR' ? 'repair' : 'retread'),
    activityTypes: ['RETREAD', 'REPAIR'],
    activityTitle: 'Recent retread / repair cycles',
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
      intro="Retread or scrap tires that were removed from service. Tires removed with disposition Retread or Repair appear under Retread; each action opens the tire."
      tabs={TABS}
    />
  );
}
