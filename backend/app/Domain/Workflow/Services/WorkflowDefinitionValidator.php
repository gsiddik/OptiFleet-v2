<?php

namespace App\Domain\Workflow\Services;

use App\Domain\AccessControl\Models\Permission;

/**
 * Section 27: the publish-time gate for a workflow definition payload —
 * {statuses: [...], transitions: [...]}. Rejects duplicate status codes,
 * transitions referencing unknown statuses/permissions/actions, a missing
 * start status, and any status no transition can ever reach. Status-graph
 * cycles (e.g. QC_PENDING -> REWORK -> IN_PROGRESS) are explicitly NOT an
 * error — only reachability is checked, never acyclicity. Approval rules
 * are a flat, ordered step list with no cross-step references, so a
 * "circular approval chain" cannot occur by construction; the one
 * structural defect that shape can have — duplicate step numbers — is
 * still rejected.
 */
class WorkflowDefinitionValidator
{
    public function __construct(private readonly WorkflowActionCatalog $actions = new WorkflowActionCatalog) {}

    public function validate(array $payload): void
    {
        $statuses = $payload['statuses'] ?? [];
        $transitions = $payload['transitions'] ?? [];

        if (empty($statuses)) {
            throw new WorkflowValidationException('A workflow must declare at least one status.');
        }

        $codes = array_map(fn ($s) => $s['code'] ?? null, $statuses);
        if (in_array(null, $codes, true) || count($codes) !== count(array_unique($codes))) {
            throw new WorkflowValidationException('Every status must have a unique, non-empty code.');
        }

        $startCodes = array_map(fn ($s) => $s['code'], array_filter($statuses, fn ($s) => ! empty($s['is_start'])));
        if (empty($startCodes)) {
            throw new WorkflowValidationException('The workflow has no start status.');
        }

        $validCodes = array_flip($codes);
        $reachable = array_flip($startCodes);

        foreach ($transitions as $t) {
            foreach (['from_status', 'to_status', 'action_code'] as $field) {
                if (empty($t[$field])) {
                    throw new WorkflowValidationException("Transition is missing required field '{$field}'.");
                }
            }
            if (! isset($validCodes[$t['from_status']])) {
                throw new WorkflowValidationException("Transition references unknown from_status '{$t['from_status']}'.");
            }
            if (! isset($validCodes[$t['to_status']])) {
                throw new WorkflowValidationException("Transition references unknown to_status '{$t['to_status']}'.");
            }
            if (! empty($t['required_permission']) && ! Permission::query()->where('name', $t['required_permission'])->exists()) {
                throw new WorkflowValidationException("Transition references unknown permission '{$t['required_permission']}'.");
            }

            foreach ($t['automated_actions'] ?? [] as $action) {
                $code = is_array($action) ? ($action['action_code'] ?? null) : $action;
                if (! $code || ! $this->actions->isValid($code)) {
                    throw new WorkflowValidationException("Transition references unknown action '{$code}'.");
                }
            }

            $this->validateConditionSet($t['condition_set'] ?? null);
            $this->validateApprovalRule($t['approval_rule'] ?? null);

            $reachable[$t['to_status']] = true;
        }

        foreach ($statuses as $s) {
            if (empty($s['is_start']) && ! isset($reachable[$s['code']])) {
                throw new WorkflowValidationException("Status '{$s['code']}' is unreachable — no transition leads to it.");
            }
        }
    }

    private function validateConditionSet(?array $conditionSet): void
    {
        if ($conditionSet === null) {
            return;
        }

        foreach ($conditionSet['rules'] ?? [] as $rule) {
            if (isset($rule['rules'])) {
                $this->validateConditionSet($rule);

                continue;
            }
            if (empty($rule['field']) || ! in_array($rule['op'] ?? null, ConditionEvaluator::OPERATORS, true)) {
                throw new WorkflowValidationException('Invalid condition rule: each rule needs a field and a recognised operator.');
            }
        }
    }

    private function validateApprovalRule(?array $rule): void
    {
        if ($rule === null) {
            return;
        }

        if (! in_array($rule['type'] ?? null, ['SINGLE', 'SEQUENTIAL', 'CONDITIONAL'], true)) {
            throw new WorkflowValidationException('Invalid approval rule type.');
        }

        $steps = $rule['steps'] ?? [];
        if (empty($steps)) {
            throw new WorkflowValidationException('An approval rule must declare at least one step.');
        }

        $stepNumbers = [];
        foreach ($steps as $step) {
            if (! in_array($step['approver_type'] ?? null, ['PERMISSION', 'ROLE', 'EXPLICIT_USER'], true)) {
                throw new WorkflowValidationException('Invalid approval step approver_type.');
            }
            if (empty($step['approver_identifier'])) {
                throw new WorkflowValidationException('Approval step is missing approver_identifier.');
            }
            if ($step['approver_type'] === 'PERMISSION' && ! Permission::query()->where('name', $step['approver_identifier'])->exists()) {
                throw new WorkflowValidationException("Approval step references unknown permission '{$step['approver_identifier']}'.");
            }
            $this->validateConditionSet($step['condition_set'] ?? null);
            $stepNumbers[] = $step['step_number'] ?? null;
        }

        if (in_array(null, $stepNumbers, true) || count($stepNumbers) !== count(array_unique($stepNumbers))) {
            throw new WorkflowValidationException('Approval steps must have unique step_number values.');
        }
    }
}
