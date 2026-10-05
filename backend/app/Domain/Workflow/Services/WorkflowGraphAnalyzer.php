<?php

namespace App\Domain\Workflow\Services;

use App\Domain\AccessControl\Models\Permission;

/**
 * Checks a workflow definition {statuses, transitions} as a graph and reports every problem at
 * once, each tied to the status or transition it concerns — for the visual builder's validation
 * panel and, through WorkflowDefinitionValidator, as the publish gate (errors block publishing,
 * warnings do not).
 *
 * Cycles and backward transitions (QC_PENDING → REWORK → IN_PROGRESS, SUBMITTED → DRAFT) are
 * valid: the graph is never required to be a DAG. Reachability is checked from every start status.
 */
class WorkflowGraphAnalyzer
{
    private const APPROVAL_TYPES = ['SINGLE', 'SEQUENTIAL', 'CONDITIONAL'];

    private const APPROVER_TYPES = ['PERMISSION', 'ROLE', 'EXPLICIT_USER'];

    public function __construct(private readonly WorkflowActionCatalog $actions = new WorkflowActionCatalog) {}

    /**
     * @param  array{statuses: list<array{code: string, display_name: string}>, targets: list<string>, entry_statuses: list<string>}|null  $catalog
     * @return array{errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function analyze(array $payload, ?array $catalog = null): array
    {
        $errors = [];
        $warnings = [];
        $statuses = array_values((array) ($payload['statuses'] ?? []));
        $transitions = array_values((array) ($payload['transitions'] ?? []));

        if ($statuses === []) {
            return ['errors' => [$this->issue('NO_STATUSES', 'A workflow must declare at least one status.')], 'warnings' => []];
        }

        $codes = array_map(fn ($s) => is_array($s) && isset($s['code']) && $s['code'] !== '' ? (string) $s['code'] : null, $statuses);
        if (in_array(null, $codes, true) || count($codes) !== count(array_unique($codes))) {
            $duplicates = array_keys(array_filter(array_count_values(array_filter($codes, fn ($c) => $c !== null)), fn ($n) => $n > 1));
            $errors[] = $this->issue('DUPLICATE_STATUS', 'Every status must have a unique, non-empty code.', ['status' => $duplicates[0] ?? null]);
        }
        $valid = array_flip(array_filter($codes, fn ($c) => $c !== null));
        $starts = array_values(array_filter(array_map(fn ($s) => ! empty($s['is_start']) ? ($s['code'] ?? null) : null, $statuses)));
        if ($starts === []) {
            $errors[] = $this->issue('NO_START', 'The workflow has no start status.');
        }

        if ($catalog !== null) {
            $allowed = array_flip(array_column($catalog['statuses'], 'code'));
            foreach (array_keys($valid) as $code) {
                if (! isset($allowed[$code])) {
                    $errors[] = $this->issue('UNKNOWN_STATUS', "'{$code}' is not a status this document can have.", ['status' => $code]);
                }
            }
            foreach ($catalog['statuses'] as $known) {
                if (! isset($valid[$known['code']])) {
                    $warnings[] = $this->issue('STATUS_NOT_IN_WORKFLOW', "{$known['display_name']} is not in this workflow: a document already in that status cannot move on.", ['status' => $known['code']]);
                }
            }
        }

        $edges = [];
        $actionsByFrom = [];
        $outgoing = [];
        $incoming = [];
        foreach ($transitions as $i => $t) {
            $t = is_array($t) ? $t : [];
            $ref = ['transition' => $i];
            $missing = false;
            foreach (['from_status', 'to_status', 'action_code'] as $field) {
                if (empty($t[$field])) {
                    $errors[] = $this->issue('MISSING_FIELD', "Transition is missing required field '{$field}'.", $ref);
                    $missing = true;
                }
            }
            if ($missing) {
                continue;
            }
            [$from, $to] = [(string) $t['from_status'], (string) $t['to_status']];
            if (! isset($valid[$from])) {
                $errors[] = $this->issue('UNKNOWN_FROM', "Transition references unknown from_status '{$from}'.", $ref);
            }
            if (! isset($valid[$to])) {
                $errors[] = $this->issue('UNKNOWN_TO', "Transition references unknown to_status '{$to}'.", $ref);
            }
            if ($from === $to) {
                $errors[] = $this->issue('SELF_TRANSITION', "A transition cannot start and end at the same status ({$from}).", $ref);
            }
            if (isset($edges["{$from}>{$to}"])) {
                $errors[] = $this->issue('DUPLICATE_TRANSITION', "There is already a transition from {$from} to {$to}.", $ref);
            }
            $edges["{$from}>{$to}"] = true;
            $action = (string) $t['action_code'];
            if (isset($actionsByFrom[$from][$action])) {
                $errors[] = $this->issue('DUPLICATE_ACTION', "Two transitions from {$from} use the same action '{$action}'.", $ref);
            }
            $actionsByFrom[$from][$action] = true;
            if ($catalog !== null && isset($valid[$to]) && ! in_array($to, $catalog['targets'], true)) {
                $errors[] = $this->issue('NOT_EXECUTABLE', "No action in OptiFleet moves this document into {$to}; the transition could never run.", $ref);
            }
            if (! empty($t['required_permission']) && ! Permission::query()->where('name', $t['required_permission'])->exists()) {
                $errors[] = $this->issue('UNKNOWN_PERMISSION', "Transition references unknown permission '{$t['required_permission']}'.", $ref);
            }
            foreach ((array) ($t['automated_actions'] ?? []) as $automated) {
                $code = is_array($automated) ? ($automated['action_code'] ?? null) : $automated;
                if (! $code || ! $this->actions->isValid((string) $code)) {
                    $errors[] = $this->issue('UNKNOWN_ACTION', "Transition references unknown action '{$code}'.", $ref);
                }
            }
            if (($message = $this->conditionProblem($t['condition_set'] ?? null)) !== null) {
                $errors[] = $this->issue('INVALID_CONDITION', $message, $ref);
            }
            if (($message = $this->approvalProblem($t['approval_rule'] ?? null)) !== null) {
                $errors[] = $this->issue('INVALID_APPROVAL', $message, $ref);
            }
            $outgoing[$from][] = $to;
            $incoming[$to] = true;
        }

        // Reachability from every start status (cycles are fine).
        $reached = array_flip($starts);
        $queue = $starts;
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($outgoing[$current] ?? [] as $next) {
                if (! isset($reached[$next])) {
                    $reached[$next] = true;
                    $queue[] = $next;
                }
            }
        }
        foreach ($statuses as $s) {
            $code = $s['code'] ?? null;
            if ($code === null || ! empty($s['is_start'])) {
                continue;
            }
            if (! isset($incoming[$code])) {
                $errors[] = $this->issue('UNREACHABLE', "Status '{$code}' is unreachable — no transition leads to it.", ['status' => $code]);
            } elseif (! isset($reached[$code]) && $starts !== []) {
                $errors[] = $this->issue('UNREACHABLE', "Status '{$code}' is unreachable — no path from a start status leads to it.", ['status' => $code]);
            }
        }
        foreach ($starts as $code) {
            if (empty($outgoing[$code]) && ! isset($incoming[$code]) && count($statuses) > 1) {
                $warnings[] = $this->issue('ORPHAN', "Status '{$code}' is not connected to any other status.", ['status' => $code]);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function issue(string $type, string $message, array $ref = []): array
    {
        return ['type' => $type, 'message' => $message] + array_filter($ref, fn ($v) => $v !== null);
    }

    private function conditionProblem(mixed $conditionSet): ?string
    {
        if ($conditionSet === null) {
            return null;
        }
        foreach ((array) ($conditionSet['rules'] ?? []) as $rule) {
            if (is_array($rule) && isset($rule['rules'])) {
                if (($nested = $this->conditionProblem($rule)) !== null) {
                    return $nested;
                }

                continue;
            }
            if (! is_array($rule) || empty($rule['field']) || ! in_array($rule['op'] ?? null, ConditionEvaluator::OPERATORS, true)) {
                return 'Invalid condition rule: each rule needs a field and a recognised operator.';
            }
        }

        return null;
    }

    private function approvalProblem(mixed $rule): ?string
    {
        if ($rule === null) {
            return null;
        }
        if (! in_array($rule['type'] ?? null, self::APPROVAL_TYPES, true)) {
            return 'Invalid approval rule type.';
        }
        $steps = (array) ($rule['steps'] ?? []);
        if ($steps === []) {
            return 'An approval rule must declare at least one step.';
        }
        $numbers = [];
        foreach ($steps as $step) {
            if (! in_array($step['approver_type'] ?? null, self::APPROVER_TYPES, true)) {
                return 'Invalid approval step approver_type.';
            }
            if (empty($step['approver_identifier'])) {
                return 'Approval step is missing approver_identifier.';
            }
            if ($step['approver_type'] === 'PERMISSION' && ! Permission::query()->where('name', $step['approver_identifier'])->exists()) {
                return "Approval step references unknown permission '{$step['approver_identifier']}'.";
            }
            if (($message = $this->conditionProblem($step['condition_set'] ?? null)) !== null) {
                return $message;
            }
            $numbers[] = $step['step_number'] ?? null;
        }
        if (in_array(null, $numbers, true) || count($numbers) !== count(array_unique($numbers))) {
            return 'Approval steps must have unique step_number values.';
        }

        return null;
    }
}
