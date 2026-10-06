import { translated } from './i18n';

/**
 * Default workflow action (button) labels in verb form, keyed by the transition's target status
 * (owner decision: action "Approve" → status "Approved"). Mirror of backend
 * App\Domain\Workflow\Support\WorkflowActionVerbs. Canonical codes are unchanged.
 */
export interface ActionVerbEntry {
  readonly key: string;
  readonly en: string;
}

export const ACTION_VERBS: Readonly<Record<string, ActionVerbEntry>> = {
  APPROVED: { key: 'workflow.actionVerb.approved', en: 'Approve' },
  ASSESSED: { key: 'workflow.actionVerb.assessed', en: 'Assess' },
  ASSIGNED: { key: 'workflow.actionVerb.assigned', en: 'Assign' },
  CANCELLED: { key: 'workflow.actionVerb.cancelled', en: 'Cancel' },
  CLOSED: { key: 'workflow.actionVerb.closed', en: 'Close' },
  COMPLETED: { key: 'workflow.actionVerb.completed', en: 'Complete' },
  DRAFT: { key: 'workflow.actionVerb.draft', en: 'Return to Draft' },
  EXTERNAL: { key: 'workflow.actionVerb.external', en: 'Send to External Workshop' },
  FINALIZED: { key: 'workflow.actionVerb.finalized', en: 'Finalize' },
  INSPECTED: { key: 'workflow.actionVerb.inspected', en: 'Inspect' },
  IN_PROGRESS: { key: 'workflow.actionVerb.inProgress', en: 'Start Work' },
  IN_TRANSIT: { key: 'workflow.actionVerb.inTransit', en: 'Dispatch' },
  ISSUED: { key: 'workflow.actionVerb.issued', en: 'Issue' },
  ON_HOLD: { key: 'workflow.actionVerb.onHold', en: 'Hold' },
  PENDING_APPROVAL: { key: 'workflow.actionVerb.pendingApproval', en: 'Submit for Approval' },
  PREPARED: { key: 'workflow.actionVerb.prepared', en: 'Mark Prepared' },
  PROCUREMENT: { key: 'workflow.actionVerb.procurement', en: 'Send to Procurement' },
  QC_PENDING: { key: 'workflow.actionVerb.qcPending', en: 'Send to QC' },
  RECEIVED: { key: 'workflow.actionVerb.received', en: 'Receive' },
  REJECTED: { key: 'workflow.actionVerb.rejected', en: 'Reject' },
  REPAIR: { key: 'workflow.actionVerb.repair', en: 'Repair' },
  REPAIR_REQUIRED: { key: 'workflow.actionVerb.repairRequired', en: 'Mark Repair Required' },
  REPLACEMENT: { key: 'workflow.actionVerb.replacement', en: 'Replace' },
  REQUESTED: { key: 'workflow.actionVerb.requested', en: 'Request' },
  RESOLVED: { key: 'workflow.actionVerb.resolved', en: 'Resolve' },
  REWORK: { key: 'workflow.actionVerb.rework', en: 'Send to Rework' },
  SCHEDULED: { key: 'workflow.actionVerb.scheduled', en: 'Schedule' },
  SETTLED: { key: 'workflow.actionVerb.settled', en: 'Settle' },
  SUBMITTED: { key: 'workflow.actionVerb.submitted', en: 'Submit' },
  UNDER_REVIEW: { key: 'workflow.actionVerb.underReview', en: 'Start Review' },
  VERIFIED: { key: 'workflow.actionVerb.verified', en: 'Verify' },
  WAITING_PART: { key: 'workflow.actionVerb.waitingPart', en: 'Wait for Part' },
  WORK_ORDER_CREATED: { key: 'workflow.actionVerb.workOrderCreated', en: 'Create Work Order' },
};

/** Default verb label for a transition into `targetStatus`. */
export function actionVerbLabel(targetStatus: string): string | undefined {
  const entry = ACTION_VERBS[targetStatus];
  return entry ? translated(entry.key, entry.en) : undefined;
}

/** Compares labels ignoring case, spaces and punctuation (e.g. "Qc Pending" vs "QC_PENDING"). */
const normalizeLabel = (value: string) => value.toLowerCase().replace(/[^a-z0-9]/g, '');

/**
 * True when a transition still carries a platform default label: the legacy status-form label
 * ("Approved" for a transition into APPROVED), the owner-approved default verb ("Approve"), or the
 * action code itself. Only a label a tenant actually changed counts as a rename.
 */
export function isDefaultActionLabel(t: { action_label?: string | null; action_code: string; to_status: string }): boolean {
  if (!t.action_label) return true;
  const label = normalizeLabel(t.action_label);
  // Stored labels are English: compare with the English default verb, whatever the active locale.
  const defaults = [t.to_status, t.action_code, ACTION_VERBS[t.to_status]?.en ?? ''].map(normalizeLabel);
  return defaults.includes(label);
}
