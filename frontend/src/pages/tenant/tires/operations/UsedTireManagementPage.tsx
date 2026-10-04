import { RETREAD_PERMISSIONS } from '../../../../layouts/tenantNav';
import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';
import { RetreadCyclePanel } from '../retread/RetreadCyclePanel';
import { ScrappedTiresPanel } from '../scrap/ScrappedTiresPanel';

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
    candidatesTitle: 'Tires in Retread / Repair Cycle',
    actionLabel: 'Open Cycle',
    anchor: (tire) => (tire.current_status === 'REPAIR' ? 'repair' : 'retread'),
    // Open Cycle (Retread Form) → Receive → Tire Inspection; a cycle is "recent" once completed.
    panel: <RetreadCyclePanel />,
    activityTypes: ['RETREAD', 'REPAIR'],
    activityTitle: 'Recent Retread / Repair Cycles',
    completedCycles: true,
  },
  {
    key: 'scrap',
    label: 'Scrap',
    permissions: ['tire.scrap', 'sparepart_sale.create'],
    // A tire is scrapped through its inspection (SCRAP outcome); this tab lists the scrapped tires,
    // which are sold (row or bulk) through Sell Sparepart.
    statuses: ['SCRAPPED'],
    candidatesTitle: 'Recently Scrapped',
    actionLabel: 'Sell',
    anchor: 'scrap',
    panel: <ScrappedTiresPanel />,
    activityTypes: ['SCRAP'],
    activityTitle: 'Recently scrapped',
    hideActivity: true,
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
