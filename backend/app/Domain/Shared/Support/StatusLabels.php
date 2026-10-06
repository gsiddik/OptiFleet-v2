<?php

namespace App\Domain\Shared\Support;

/**
 * Status display label registry (i18n structural preparation) — backend mirror of
 * frontend/src/i18n/statusRegistry.ts (parity is enforced by StatusLabelsTest).
 *
 * Canonical status codes never change. Display text is looked up by code (and, for codes that mean
 * different things in different domains, by domain); it is never derived by reformatting the code.
 * Indonesian text for every key is in docs/i18n/12-en-id-translation-dataset-final.csv.
 */
final class StatusLabels
{
    /** @var array<string, array{key: string, en: string}> */
    public const STATUSES = [
        'ACCEPTED' => ['key' => 'status.accepted', 'en' => 'Accepted'],
        'ACKNOWLEDGED' => ['key' => 'status.acknowledged', 'en' => 'Acknowledged'],
        'ACTIVE' => ['key' => 'status.active', 'en' => 'Active'],
        'APPLIED' => ['key' => 'status.applied', 'en' => 'Applied'],
        'APPROVED' => ['key' => 'status.approved', 'en' => 'Approved'],
        'ARCHIVED' => ['key' => 'status.archived', 'en' => 'Archived'],
        'ASSESSED' => ['key' => 'status.assessed', 'en' => 'Assessed'],
        'ASSIGNED' => ['key' => 'status.assigned', 'en' => 'Assigned'],
        'ATTENTION' => ['key' => 'status.attention', 'en' => 'Attention'],
        'AVAILABLE' => ['key' => 'status.available', 'en' => 'Available'],
        'BILLED' => ['key' => 'status.billed', 'en' => 'Billed'],
        'BLOCKED' => ['key' => 'status.blocked', 'en' => 'Blocked'],
        'BREAKDOWN' => ['key' => 'status.breakdown', 'en' => 'Breakdown'],
        'CANCELLATION_REQUESTED' => ['key' => 'status.cancellationRequested', 'en' => 'Cancellation Requested'],
        'CANCELLED' => ['key' => 'status.cancelled', 'en' => 'Cancelled'],
        'CLOSED' => ['key' => 'status.closed', 'en' => 'Closed'],
        'COMPLETED' => ['key' => 'status.completed', 'en' => 'Completed'],
        'CONSUMED' => ['key' => 'status.consumed', 'en' => 'Consumed'],
        'CONVERTED_TO_ACTION' => ['key' => 'status.convertedToAction', 'en' => 'Converted to Action'],
        'CORRECTION_REQUESTED' => ['key' => 'status.correctionRequested', 'en' => 'Correction Requested'],
        'COUNTING' => ['key' => 'status.counting', 'en' => 'Counting'],
        'CREATED' => ['key' => 'status.created', 'en' => 'Created'],
        'CRITICAL_UNSAFE' => ['key' => 'status.criticalUnsafe', 'en' => 'Critical / Unsafe'],
        'DELIVERED' => ['key' => 'status.delivered', 'en' => 'Delivered'],
        'DISPATCHED' => ['key' => 'status.dispatched', 'en' => 'Dispatched'],
        'DISPOSED' => ['key' => 'status.disposed', 'en' => 'Disposed'],
        'DISPUTED' => ['key' => 'status.disputed', 'en' => 'Disputed'],
        'DONE' => ['key' => 'status.done', 'en' => 'Done'],
        'DRAFT' => ['key' => 'status.draft', 'en' => 'Draft'],
        'DUE' => ['key' => 'status.due', 'en' => 'Due'],
        'DUE_SOON' => ['key' => 'status.dueSoon', 'en' => 'Due Soon'],
        'ENDED' => ['key' => 'status.ended', 'en' => 'Ended'],
        'EVALUATED' => ['key' => 'status.evaluated', 'en' => 'Evaluated'],
        'EXPIRED' => ['key' => 'status.expired', 'en' => 'Expired'],
        'EXPIRING' => ['key' => 'status.expiring', 'en' => 'Expiring'],
        'EXTERNAL' => ['key' => 'status.external', 'en' => 'External'],
        'FAIL' => ['key' => 'status.fail', 'en' => 'Fail'],
        'FAILED' => ['key' => 'status.failed', 'en' => 'Failed'],
        'FINALIZED' => ['key' => 'status.finalized', 'en' => 'Finalized'],
        'FINAL_INSPECTED' => ['key' => 'status.finalInspected', 'en' => 'Final Inspected'],
        'FINISHED' => ['key' => 'status.finished', 'en' => 'Finished'],
        'GENERATED' => ['key' => 'status.generated', 'en' => 'Generated'],
        'GOOD' => ['key' => 'status.good', 'en' => 'Good'],
        'GRACE_PERIOD' => ['key' => 'status.gracePeriod', 'en' => 'Grace Period'],
        'HEALTHY' => ['key' => 'status.healthy', 'en' => 'Healthy'],
        'HOLD' => ['key' => 'status.hold', 'en' => 'Hold'],
        'INACTIVE' => ['key' => 'status.inactive', 'en' => 'Inactive'],
        'INSPECTED' => ['key' => 'status.inspected', 'en' => 'Inspected'],
        'INSTALLED' => ['key' => 'status.installed', 'en' => 'Installed'],
        'INVOICED' => ['key' => 'status.invoiced', 'en' => 'Invoiced'],
        'IN_MAINTENANCE' => ['key' => 'status.inMaintenance', 'en' => 'In Maintenance'],
        'IN_PROGRESS' => ['key' => 'status.inProgress', 'en' => 'In Progress'],
        'IN_STOCK' => ['key' => 'status.inStock', 'en' => 'In Stock'],
        'IN_TRANSIT' => ['key' => 'status.inTransit', 'en' => 'In Transit'],
        // Without a domain, ISSUED means a document was issued (status.issued was split into document / stock).
        'ISSUED' => ['key' => 'status.document.issued', 'en' => 'Issued'],
        'LATE' => ['key' => 'status.late', 'en' => 'Late'],
        'LOW_STOCK' => ['key' => 'status.lowStock', 'en' => 'Low Stock'],
        'NEED_INFORMATION' => ['key' => 'status.needInformation', 'en' => 'Need Information'],
        'NEW' => ['key' => 'status.new', 'en' => 'New'],
        'NEW_EXTERNAL_WO' => ['key' => 'status.newExternalWo', 'en' => 'New External WO'],
        'NOT_APPLICABLE' => ['key' => 'status.notApplicable', 'en' => 'Not Applicable'],
        'NOT_GENERATED' => ['key' => 'status.notGenerated', 'en' => 'Not Generated'],
        'OCCUPIED' => ['key' => 'status.occupied', 'en' => 'Occupied'],
        'ON_HOLD' => ['key' => 'status.onHold', 'en' => 'On Hold'],
        'OPEN' => ['key' => 'status.open', 'en' => 'Open'],
        'OUTSTANDING' => ['key' => 'status.outstanding', 'en' => 'Outstanding'],
        'OUT_OF_SERVICE' => ['key' => 'status.outOfService', 'en' => 'Out of Service'],
        'OUT_OF_STOCK' => ['key' => 'status.outOfStock', 'en' => 'Out of Stock'],
        'OVERDUE' => ['key' => 'status.overdue', 'en' => 'Overdue'],
        'PAID' => ['key' => 'status.paid', 'en' => 'Paid'],
        'PARTIAL' => ['key' => 'status.partial', 'en' => 'Partial'],
        'PARTIALLY_ISSUED' => ['key' => 'status.partiallyIssued', 'en' => 'Partially Issued'],
        'PARTIALLY_PAID' => ['key' => 'status.partiallyPaid', 'en' => 'Partially Paid'],
        'PARTIALLY_RECEIVED' => ['key' => 'status.partiallyReceived', 'en' => 'Partially Received'],
        'PARTIALLY_RESERVED' => ['key' => 'status.partiallyReserved', 'en' => 'Partially Reserved'],
        'PASS' => ['key' => 'status.pass', 'en' => 'Pass'],
        'PASSED' => ['key' => 'status.passed', 'en' => 'Passed'],
        'PAST_DUE' => ['key' => 'status.pastDue', 'en' => 'Past Due'],
        'PAUSED' => ['key' => 'status.paused', 'en' => 'Paused'],
        'PENDING' => ['key' => 'status.pending', 'en' => 'Pending'],
        'PENDING_APPROVAL' => ['key' => 'status.pendingApproval', 'en' => 'Pending Approval'],
        'PENDING_INSPECTION' => ['key' => 'status.pendingInspection', 'en' => 'Pending Inspection'],
        'PENDING_PROCESSING' => ['key' => 'status.pendingProcessing', 'en' => 'Pending Processing'],
        'PENDING_RETURN' => ['key' => 'status.pendingReturn', 'en' => 'Pending Return'],
        'PLANNED' => ['key' => 'status.planned', 'en' => 'Planned'],
        'POSTED' => ['key' => 'status.posted', 'en' => 'Posted'],
        'PREPARED' => ['key' => 'status.prepared', 'en' => 'Prepared'],
        'PROCUREMENT' => ['key' => 'status.procurement', 'en' => 'Procurement'],
        'PUBLISHED' => ['key' => 'status.published', 'en' => 'Published'],
        'QC_PENDING' => ['key' => 'status.qcPending', 'en' => 'QC Pending'],
        'QC_STARTED' => ['key' => 'status.qcStarted', 'en' => 'QC Started'],
        'QUARANTINED' => ['key' => 'status.quarantined', 'en' => 'Quarantined'],
        'QUEUED' => ['key' => 'status.queued', 'en' => 'Queued'],
        'RECEIVED' => ['key' => 'status.received', 'en' => 'Received'],
        'RECONDITIONED' => ['key' => 'status.reconditioned', 'en' => 'Reconditioned'],
        'RECORDED' => ['key' => 'status.recorded', 'en' => 'Recorded'],
        'REDELIVERY_PENDING' => ['key' => 'status.redeliveryPending', 'en' => 'Redelivery Pending'],
        'REDELIVERY_READY' => ['key' => 'status.redeliveryReady', 'en' => 'Redelivery Ready'],
        'REDELIVERY_RECEIVED' => ['key' => 'status.redeliveryReceived', 'en' => 'Redelivery Received'],
        'REDELIVERY_REQUESTED' => ['key' => 'status.redeliveryRequested', 'en' => 'Redelivery Requested'],
        'REFUND_ACCEPTED' => ['key' => 'status.refundAccepted', 'en' => 'Refund Accepted'],
        'REFUND_REQUESTED' => ['key' => 'status.refundRequested', 'en' => 'Refund Requested'],
        'REJECTED' => ['key' => 'status.rejected', 'en' => 'Rejected'],
        'RELEASED' => ['key' => 'status.released', 'en' => 'Released'],
        'REMOVED' => ['key' => 'status.removed', 'en' => 'Removed'],
        'REORDER_REQUIRED' => ['key' => 'status.reorderRequired', 'en' => 'Reorder Required'],
        'REPAIR' => ['key' => 'status.repair', 'en' => 'Repair'],
        'REPAIR_REQUIRED' => ['key' => 'status.repairRequired', 'en' => 'Repair Required'],
        'REPLACEMENT' => ['key' => 'status.replacement', 'en' => 'Replacement'],
        'REPORTED' => ['key' => 'status.reported', 'en' => 'Reported'],
        'REQUESTED' => ['key' => 'status.requested', 'en' => 'Requested'],
        'RESERVED' => ['key' => 'status.reserved', 'en' => 'Reserved'],
        'RESOLVED' => ['key' => 'status.resolved', 'en' => 'Resolved'],
        'RESTOCKED' => ['key' => 'status.restocked', 'en' => 'Restocked'],
        'RETIRED' => ['key' => 'status.retired', 'en' => 'Retired'],
        'RETREAD' => ['key' => 'status.retread', 'en' => 'Retread'],
        'RETURNED' => ['key' => 'status.returned', 'en' => 'Returned'],
        'RETURNED_TO_VENDOR' => ['key' => 'status.returnedToVendor', 'en' => 'Returned to Vendor'],
        'REUSE' => ['key' => 'status.reuse', 'en' => 'Reuse'],
        'REVERSED' => ['key' => 'status.reversed', 'en' => 'Reversed'],
        'REVIEWED' => ['key' => 'status.reviewed', 'en' => 'Reviewed'],
        'REWORK' => ['key' => 'status.rework', 'en' => 'Rework'],
        'RUNNING' => ['key' => 'status.running', 'en' => 'Running'],
        'SCHEDULED' => ['key' => 'status.scheduled', 'en' => 'Scheduled'],
        'SCRAP' => ['key' => 'status.scrap', 'en' => 'Scrap'],
        'SCRAPPED' => ['key' => 'status.scrapped', 'en' => 'Scrap'],
        'SELECTED' => ['key' => 'status.selected', 'en' => 'Selected'],
        'SENT' => ['key' => 'status.sent', 'en' => 'Sent'],
        'SETTLED' => ['key' => 'status.settled', 'en' => 'Settled'],
        'SKIPPED' => ['key' => 'status.skipped', 'en' => 'Skipped'],
        'SOLD' => ['key' => 'status.sold', 'en' => 'Sold'],
        'STARTED' => ['key' => 'status.started', 'en' => 'Started'],
        'SUBMITTED' => ['key' => 'status.submitted', 'en' => 'Submitted'],
        'SUSPENDED' => ['key' => 'status.suspended', 'en' => 'Suspended'],
        'TERMINATED' => ['key' => 'status.terminated', 'en' => 'Terminated'],
        'TRAINING' => ['key' => 'status.training', 'en' => 'Training'],
        'TRANSFERRED' => ['key' => 'status.transferred', 'en' => 'Transferred'],
        'UNDER_INSPECTION' => ['key' => 'status.underInspection', 'en' => 'Under Inspection'],
        'UNDER_MAINTENANCE' => ['key' => 'status.underMaintenance', 'en' => 'Under Maintenance'],
        'UNDER_REPAIR' => ['key' => 'status.underRepair', 'en' => 'Under Repair'],
        'UNDER_REVIEW' => ['key' => 'status.underReview', 'en' => 'Under Review'],
        'UPCOMING' => ['key' => 'status.upcoming', 'en' => 'Upcoming'],
        'VERIFIED' => ['key' => 'status.verified', 'en' => 'Verified'],
        'VOID' => ['key' => 'status.void', 'en' => 'Void'],
        'WAITING_PART' => ['key' => 'status.waitingPart', 'en' => 'Waiting for Part'],
        'WARNING' => ['key' => 'status.warning', 'en' => 'Warning'],
        'WARRANTY_CLAIM' => ['key' => 'status.warrantyClaim', 'en' => 'Warranty Claim'],
        'WORK_ORDER_CREATED' => ['key' => 'status.workOrderCreated', 'en' => 'Work Order Created'],
    ];

    /** ISSUED: "document issued" (PO / RFQ / invoice) vs "stock issued" (part request / issuance). */
    /** @var array<string, array<string, array{key: string, en: string}>> */
    public const DOMAIN_STATUSES = [
        'document' => ['ISSUED' => ['key' => 'status.document.issued', 'en' => 'Issued']],
        'stock' => ['ISSUED' => ['key' => 'status.stock.issued', 'en' => 'Issued']],
    ];

    /** @return array{key: string, en: string}|null */
    public static function entry(?string $code, ?string $domain = null): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }
        $upper = strtoupper($code);

        return ($domain !== null ? (self::DOMAIN_STATUSES[$domain][$upper] ?? null) : null)
            ?? self::STATUSES[$code] ?? self::STATUSES[$upper] ?? null;
    }

    public static function key(?string $code, ?string $domain = null): ?string
    {
        return self::entry($code, $domain)['key'] ?? null;
    }

    /** English display label; an unknown code is returned unchanged (never reformatted). */
    public static function label(?string $code, ?string $domain = null): string
    {
        return self::entry($code, $domain)['en'] ?? (string) $code;
    }
}
