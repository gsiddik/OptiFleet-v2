import type {
  NotificationCondition,
  NotificationConditionSet,
  NotificationMetadata,
} from "../../../../types";
import { t } from '../../../../i18n/i18n';

export const isGroup = (
  rule: NotificationCondition | NotificationConditionSet,
): rule is NotificationConditionSet => "rules" in rule;

/** A readable sentence for a condition set ("Breakdown Severity is one of CRITICAL, IMMOBILIZED"). */
export function describeConditions(
  set: NotificationConditionSet | null | undefined,
  fields: Array<{ key: string; label: string }>,
  operators: NotificationMetadata["operators"],
): string {
  if (!set || !set.rules?.length) return "";
  const join =
    (set.operator ?? "AND").toUpperCase() === "OR" ? ` ${t("common.fields.conditionOr")} ` : ` ${t("common.fields.conditionAnd")} `;
  return set.rules
    .map((rule) => {
      if (isGroup(rule))
        return `(${describeConditions(rule, fields, operators)})`;
      const field =
        fields.find((f) => f.key === rule.field)?.label ?? rule.field;
      const op = operators.find((o) => o.value === rule.op);
      const value = Array.isArray(rule.value)
        ? rule.value.join(", ")
        : rule.value === undefined || rule.value === null
          ? ""
          : String(rule.value);
      return `${field} ${op?.label ?? rule.op}${op && !op.needs_value ? "" : ` ${value}`}`;
    })
    .join(join);
}
