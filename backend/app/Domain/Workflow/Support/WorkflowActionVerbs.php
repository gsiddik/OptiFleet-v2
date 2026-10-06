<?php

namespace App\Domain\Workflow\Support;

/**
 * Workflow action (button) labels in verb form, keyed by the transition's target status code
 * (owner decision: an action is "Approve", the status it leads to is "Approved"). Backend mirror of
 * frontend/src/i18n/workflowActionVerbs.ts. Canonical status and action codes are unchanged; tenants may still
 * rename an action in the Workflow Builder.
 */
final class WorkflowActionVerbs
{
    /** @var array<string, array{key: string, en: string}> */
    public const BY_TARGET_STATUS = [
        'APPROVED' => ['key' => 'workflow.actionVerb.approved', 'en' => 'Approve'],
        'ASSESSED' => ['key' => 'workflow.actionVerb.assessed', 'en' => 'Assess'],
        'ASSIGNED' => ['key' => 'workflow.actionVerb.assigned', 'en' => 'Assign'],
        'CANCELLED' => ['key' => 'workflow.actionVerb.cancelled', 'en' => 'Cancel'],
        'CLOSED' => ['key' => 'workflow.actionVerb.closed', 'en' => 'Close'],
        'COMPLETED' => ['key' => 'workflow.actionVerb.completed', 'en' => 'Complete'],
        'DRAFT' => ['key' => 'workflow.actionVerb.draft', 'en' => 'Return to Draft'],
        'EXTERNAL' => ['key' => 'workflow.actionVerb.external', 'en' => 'Send to External Workshop'],
        'FINALIZED' => ['key' => 'workflow.actionVerb.finalized', 'en' => 'Finalize'],
        'INSPECTED' => ['key' => 'workflow.actionVerb.inspected', 'en' => 'Inspect'],
        'IN_PROGRESS' => ['key' => 'workflow.actionVerb.inProgress', 'en' => 'Start Work'],
        'IN_TRANSIT' => ['key' => 'workflow.actionVerb.inTransit', 'en' => 'Dispatch'],
        'ISSUED' => ['key' => 'workflow.actionVerb.issued', 'en' => 'Issue'],
        'ON_HOLD' => ['key' => 'workflow.actionVerb.onHold', 'en' => 'Hold'],
        'PENDING_APPROVAL' => ['key' => 'workflow.actionVerb.pendingApproval', 'en' => 'Submit for Approval'],
        'PREPARED' => ['key' => 'workflow.actionVerb.prepared', 'en' => 'Mark Prepared'],
        'PROCUREMENT' => ['key' => 'workflow.actionVerb.procurement', 'en' => 'Send to Procurement'],
        'QC_PENDING' => ['key' => 'workflow.actionVerb.qcPending', 'en' => 'Send to QC'],
        'RECEIVED' => ['key' => 'workflow.actionVerb.received', 'en' => 'Receive'],
        'REJECTED' => ['key' => 'workflow.actionVerb.rejected', 'en' => 'Reject'],
        'REPAIR' => ['key' => 'workflow.actionVerb.repair', 'en' => 'Repair'],
        'REPAIR_REQUIRED' => ['key' => 'workflow.actionVerb.repairRequired', 'en' => 'Mark Repair Required'],
        'REPLACEMENT' => ['key' => 'workflow.actionVerb.replacement', 'en' => 'Replace'],
        'REQUESTED' => ['key' => 'workflow.actionVerb.requested', 'en' => 'Request'],
        'RESOLVED' => ['key' => 'workflow.actionVerb.resolved', 'en' => 'Resolve'],
        'REWORK' => ['key' => 'workflow.actionVerb.rework', 'en' => 'Send to Rework'],
        'SCHEDULED' => ['key' => 'workflow.actionVerb.scheduled', 'en' => 'Schedule'],
        'SETTLED' => ['key' => 'workflow.actionVerb.settled', 'en' => 'Settle'],
        'SUBMITTED' => ['key' => 'workflow.actionVerb.submitted', 'en' => 'Submit'],
        'UNDER_REVIEW' => ['key' => 'workflow.actionVerb.underReview', 'en' => 'Start Review'],
        'VERIFIED' => ['key' => 'workflow.actionVerb.verified', 'en' => 'Verify'],
        'WAITING_PART' => ['key' => 'workflow.actionVerb.waitingPart', 'en' => 'Wait for Part'],
        'WORK_ORDER_CREATED' => ['key' => 'workflow.actionVerb.workOrderCreated', 'en' => 'Create Work Order'],
    ];

    public static function key(string $targetStatus): ?string
    {
        return self::BY_TARGET_STATUS[$targetStatus]['key'] ?? null;
    }

    /** Default verb label for a transition into $targetStatus, or null when none is registered. */
    public static function label(string $targetStatus): ?string
    {
        return self::BY_TARGET_STATUS[$targetStatus]['en'] ?? null;
    }
}
