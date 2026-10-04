import { RETREAD_PERMISSIONS } from '../../../../layouts/tenantNav';
import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';

const TABS: WorkflowTab[] = [
  {
    // Owner decision: a tire taken off by a Replacement comes here as REMOVED; it goes back to
    // stock as a Used (reusable) tire only after its inspection in Used Tire Management.
    key: 'removed',
    label: 'Removed',
    permissions: ['tire.view'],
    statuses: ['REMOVED', 'HOLD'],
    candidatesTitle: 'Removed tires awaiting inspection',
    actionLabel: 'Inspect',
    anchor: 'used-inspection',
    actionHref: (tire) => `/app/tires/${tire.id}/inspection`,
    serialOpensHistory: true,
    activityTypes: ['REMOVAL'],
    activityTitle: 'Recent removals',
  },
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
    // A REMOVED tire is scrapped through its inspection (SCRAP outcome), not directly.
    statuses: ['HOLD', 'UNDER_INSPECTION', 'QUARANTINED'],
    candidatesTitle: 'Used tires that can be scrapped',
    actionLabel: 'Scrap',
    anchor: 'scrap',
    activityTypes: ['SCRAP'],
    activityTitle: 'Recently scrapped',
  },
];

/** Used Tire Management: removed tires awaiting inspection, Retread and Scrap (replaces two menu aliases). */
export function UsedTireManagementPage() {
  return (
    <TireWorkflowTabs
      title="Used Tire Management"
      intro="Tires removed from service: removed tires wait here for inspection before they return to stock as Used; retread or scrap them. Tires removed with disposition Retread or Repair appear under Retread; each action opens the tire."
      tabs={TABS}
    />
  );
}
