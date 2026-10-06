import { RETREAD_PERMISSIONS } from '../../../../layouts/tenantNav';
import { TireWorkflowTabs, type WorkflowTab } from './TireWorkflowTabs';
import { RetreadCyclePanel } from '../retread/RetreadCyclePanel';
import { ScrappedTiresPanel } from '../scrap/ScrappedTiresPanel';
import { t, withLabels } from '../../../../i18n/i18n';

const TABS: WorkflowTab[] = withLabels([
  {
    // Owner decision: a tire taken off by a Replacement comes here as REMOVED; it goes back to
    // stock as a Used (reusable) tire only after its inspection in Used Tire Management.
    key: 'removed',
    label: 'Removed', labelKey: 'tire.sections.removed',
    permissions: ['tire.view'],
    statuses: ['REMOVED', 'HOLD'],
    get candidatesTitle() { return t('tire.sections.removedTiresAwaitingInspection'); },
    get actionLabel() { return t('tire.actions.inspect'); },
    anchor: 'used-inspection',
    actionHref: (tire) => `/app/tires/${tire.id}/inspection`,
    serialOpensHistory: true,
    activityTypes: ['REMOVAL'],
    get activityTitle() { return t('tire.sections.recentRemovals'); },
  },
  {
    key: 'retread',
    label: 'Retread', labelKey: 'tire.sections.retread',
    permissions: RETREAD_PERMISSIONS,
    // Repair is a kind of retread (owner decision): both cycles live in this tab.
    statuses: ['RETREAD', 'REPAIR'],
    get candidatesTitle() { return t('tire.sections.tiresRetreadRepairCycle'); },
    get actionLabel() { return t('tire.fields.openCycle'); },
    anchor: (tire) => (tire.current_status === 'REPAIR' ? 'repair' : 'retread'),
    // Open Cycle (Retread Form) → Receive → Tire Inspection; a cycle is "recent" once completed.
    panel: <RetreadCyclePanel />,
    activityTypes: ['RETREAD', 'REPAIR'],
    get activityTitle() { return t('tire.sections.recentRetreadRepairCycles'); },
    completedCycles: true,
  },
  {
    key: 'scrap',
    label: 'Scrap', labelKey: 'tire.sections.scrap',
    permissions: ['tire.scrap', 'sparepart_sale.create'],
    // A tire is scrapped through its inspection (SCRAP outcome); this tab lists the scrapped tires,
    // which are sold (row or bulk) through Sell Sparepart.
    statuses: ['SCRAPPED'],
    get candidatesTitle() { return t('tire.sections.recentlyScrapped'); },
    get actionLabel() { return t('tire.fields.sell'); },
    anchor: 'scrap',
    panel: <ScrappedTiresPanel />,
    activityTypes: ['SCRAP'],
    get activityTitle() { return t('tire.sections.recentlyScrapped2'); },
    hideActivity: true,
  },
]);

/** Used Tire Management: removed tires awaiting inspection, Retread and Scrap (replaces two menu aliases). */
export function UsedTireManagementPage() {
  return (
    <TireWorkflowTabs
      title={t('tire.sections.usedTireManagement')}
      intro="Tires removed from service: removed tires wait here for inspection before they return to stock as Used; retread or scrap them. Tires removed with disposition Retread or Repair appear under Retread; each action opens the tire."
      tabs={TABS}
    />
  );
}
