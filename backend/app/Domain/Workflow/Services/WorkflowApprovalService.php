<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Models\WorkflowApprovalStep;
use Illuminate\Support\Facades\DB;

/**
 * Section 22: approval steps are a flat, ordered list — SINGLE is one step,
 * SEQUENTIAL/CONDITIONAL are N steps decided strictly in step_number order.
 * A step whose condition_set evaluates false against the resource context
 * at creation time is marked SKIPPED immediately and never blocks the
 * sequence (this is what makes cost-tiered approval — e.g. Maintenance
 * Manager -> Fleet Manager -> Management only above a cost threshold —
 * work without any branching). Every step row is written at most once
 * (decided_by/decided_at/note set exactly once), so this table pair is
 * itself the immutable Approval History.
 */
class WorkflowApprovalService
{
    public function __construct(
        private readonly ApprovalResolver $resolver,
        private readonly ConditionEvaluator $conditions,
    ) {}

    public function createRequest(
        string $tenantId,
        string $resourceType,
        string $resourceId,
        string $workflowConfigurationVersionId,
        string $actionCode,
        string $fromStatus,
        string $toStatus,
        array $approvalRule,
        array $context,
        ?string $requestedBy,
    ): WorkflowApprovalRequest {
        return DB::transaction(function () use ($tenantId, $resourceType, $resourceId, $workflowConfigurationVersionId, $actionCode, $fromStatus, $toStatus, $approvalRule, $context, $requestedBy) {
            $request = WorkflowApprovalRequest::query()->create([
                'tenant_id' => $tenantId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'workflow_configuration_version_id' => $workflowConfigurationVersionId,
                'transition_action_code' => $actionCode,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'requested_by' => $requestedBy,
                'status' => WorkflowApprovalRequest::STATUS_PENDING,
            ]);

            foreach ($approvalRule['steps'] as $step) {
                $applies = $this->conditions->evaluate($step['condition_set'] ?? null, $context);
                WorkflowApprovalStep::query()->create([
                    'approval_request_id' => $request->id,
                    'step_number' => $step['step_number'],
                    'approver_type' => $step['approver_type'],
                    'approver_identifier' => $step['approver_identifier'],
                    'status' => $applies ? WorkflowApprovalStep::STATUS_PENDING : WorkflowApprovalStep::STATUS_SKIPPED,
                ]);
            }

            $this->recomputeRequestStatus($request);

            return $request->fresh('steps');
        });
    }

    public function decide(WorkflowApprovalStep $step, string $decision, string $userId, ?string $note = null): WorkflowApprovalStep
    {
        if (! in_array($decision, [WorkflowApprovalStep::STATUS_APPROVED, WorkflowApprovalStep::STATUS_REJECTED], true)) {
            throw new WorkflowException("Invalid decision '{$decision}'.");
        }

        return DB::transaction(function () use ($step, $decision, $userId, $note) {
            $locked = WorkflowApprovalStep::query()->lockForUpdate()->findOrFail($step->id);
            if ($locked->status !== WorkflowApprovalStep::STATUS_PENDING) {
                throw new WorkflowException('This approval step has already been decided.');
            }

            $request = WorkflowApprovalRequest::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->approval_request_id);
            if ($request->status !== WorkflowApprovalRequest::STATUS_PENDING) {
                throw new WorkflowException('This approval request is no longer pending.');
            }

            $nextPendingStep = WorkflowApprovalStep::query()
                ->where('approval_request_id', $request->id)
                ->where('status', WorkflowApprovalStep::STATUS_PENDING)
                ->orderBy('step_number')
                ->first();
            if (! $nextPendingStep || $nextPendingStep->id !== $locked->id) {
                throw new WorkflowException('Earlier approval steps must be decided first.');
            }

            if (! $this->resolver->userMatchesStep($locked, $request->tenant_id, $userId)) {
                throw new WorkflowException('You are not an eligible approver for this step.');
            }

            $locked->update(['status' => $decision, 'decided_by' => $userId, 'decided_at' => now(), 'note' => $note]);

            if ($decision === WorkflowApprovalStep::STATUS_REJECTED) {
                WorkflowApprovalStep::query()
                    ->where('approval_request_id', $request->id)
                    ->where('status', WorkflowApprovalStep::STATUS_PENDING)
                    ->update(['status' => WorkflowApprovalStep::STATUS_SKIPPED]);
                $request->update(['status' => WorkflowApprovalRequest::STATUS_REJECTED, 'decided_at' => now()]);
            } else {
                $this->recomputeRequestStatus($request);
            }

            return $locked->fresh();
        });
    }

    public function isFullyApproved(WorkflowApprovalRequest $request): bool
    {
        return $request->fresh()->status === WorkflowApprovalRequest::STATUS_APPROVED;
    }

    private function recomputeRequestStatus(WorkflowApprovalRequest $request): void
    {
        $stillPending = WorkflowApprovalStep::query()
            ->where('approval_request_id', $request->id)
            ->where('status', WorkflowApprovalStep::STATUS_PENDING)
            ->exists();

        if (! $stillPending) {
            $request->update(['status' => WorkflowApprovalRequest::STATUS_APPROVED, 'decided_at' => now()]);
        }
    }
}
