<?php

namespace App\Domain\Workflow\Services;

/**
 * Section 18: deterministic, server-side evaluation of a declarative
 * condition tree against a plain context array — {operator: AND|OR, rules:
 * [...]} where a leaf rule is {field, op, value}. There is no expression
 * language and no callable — `field` is always a dot-path array lookup on
 * the context the caller built, so a condition can never do more than
 * compare a value already present there ("no arbitrary executable
 * expressions").
 */
class ConditionEvaluator
{
    public const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT_IN', 'IS_NULL', 'IS_NOT_NULL'];

    public function evaluate(?array $conditionSet, array $context): bool
    {
        if (empty($conditionSet)) {
            return true;
        }

        return $this->evaluateGroup($conditionSet, $context);
    }

    private function evaluateGroup(array $group, array $context): bool
    {
        $operator = strtoupper($group['operator'] ?? 'AND');
        $rules = $group['rules'] ?? [];
        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            $result = array_key_exists('rules', $rule)
                ? $this->evaluateGroup($rule, $context)
                : $this->evaluateRule($rule, $context);

            if ($operator === 'AND' && ! $result) {
                return false;
            }
            if ($operator === 'OR' && $result) {
                return true;
            }
        }

        return $operator === 'AND';
    }

    private function evaluateRule(array $rule, array $context): bool
    {
        $value = $this->lookup($rule['field'] ?? '', $context);
        $target = $rule['value'] ?? null;

        return match ($rule['op'] ?? '=') {
            '=' => $value == $target,
            '!=' => $value != $target,
            '>' => $value > $target,
            '>=' => $value >= $target,
            '<' => $value < $target,
            '<=' => $value <= $target,
            'IN' => is_array($target) && in_array($value, $target, false),
            'NOT_IN' => is_array($target) && ! in_array($value, $target, false),
            'IS_NULL' => $value === null,
            'IS_NOT_NULL' => $value !== null,
            default => false,
        };
    }

    private function lookup(string $path, array $context): mixed
    {
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
