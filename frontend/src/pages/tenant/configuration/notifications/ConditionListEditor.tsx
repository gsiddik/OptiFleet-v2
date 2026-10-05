import { useState } from "react";
import { inputStyle } from "../../../../components/FormField";
import type {
  NotificationCondition,
  NotificationConditionSet,
  NotificationMetadata,
} from "../../../../types";
import { describeConditions, isGroup } from "./conditions";

const valueText = (value: unknown) =>
  Array.isArray(value)
    ? value.join(", ")
    : value === undefined || value === null
      ? ""
      : String(value);

/**
 * "Only when …" conditions: match All / Any, and rows of field · comparison · value, stored as
 * the existing {operator, rules: [{field, op, value}]}. Fields are the event's own information,
 * so a condition can only compare what the event carries. A condition with nested groups
 * (possible in the stored format, not built here) is shown as text and kept unchanged.
 */
export function ConditionListEditor({
  value,
  onChange,
  fields,
  operators,
  label,
  readOnly,
}: {
  value: NotificationConditionSet;
  onChange: (value: NotificationConditionSet) => void;
  fields: Array<{ key: string; label: string }>;
  operators: NotificationMetadata["operators"];
  label: string;
  readOnly?: boolean;
}) {
  // Stable row ids so a row's typed text survives removing another row.
  const [ids, setIds] = useState<number[]>(() => value.rules.map((_, i) => i));
  const [nextId, setNextId] = useState(value.rules.length);
  const [texts, setTexts] = useState<Record<number, string>>(() =>
    Object.fromEntries(
      value.rules.map((r, i) => [i, isGroup(r) ? "" : valueText(r.value)]),
    ),
  );

  if (value.rules.some(isGroup)) {
    return (
      <div
        style={{
          fontSize: 13,
          background: "#f9fafb",
          padding: 10,
          borderRadius: 6,
        }}
        data-advanced-condition
      >
        {describeConditions(value, fields, operators)}
        <div style={{ color: "#6b7280", fontSize: 12, marginTop: 4 }}>
          This condition has nested groups and is kept exactly as it is.
        </div>
      </div>
    );
  }

  const rules = value.rules as NotificationCondition[];
  const opOf = (op: string) => operators.find((o) => o.value === op);
  const setRule = (index: number, rule: NotificationCondition) =>
    onChange({
      ...value,
      rules: rules.map((r, i) => (i === index ? rule : r)),
    });
  const toValue = (text: string, op: string, previous: unknown): unknown => {
    const meta = opOf(op);
    if (!meta?.needs_value) return undefined;
    if (meta.multiple)
      return text
        .split(",")
        .map((t) => t.trim())
        .filter(Boolean);
    // A number stays a number (e.g. "Available Stock is less than 5").
    if (typeof previous === "number" && /^-?\d+(\.\d+)?$/.test(text.trim()))
      return Number(text.trim());
    return text;
  };

  return (
    <div data-condition-list={label}>
      {rules.length > 1 && (
        <label style={{ fontSize: 13, display: "block", marginBottom: 8 }}>
          Match{" "}
          <select
            value={(value.operator ?? "AND").toUpperCase()}
            onChange={(e) => onChange({ ...value, operator: e.target.value })}
            style={{ ...inputStyle, width: "auto", display: "inline-block" }}
            aria-label={`${label} match`}
            disabled={readOnly}
          >
            <option value="AND">all conditions</option>
            <option value="OR">any condition</option>
          </select>
        </label>
      )}
      {rules.map((rule, index) => {
        const id = ids[index];
        const op = opOf(rule.op);
        const text = texts[id] ?? valueText(rule.value);
        return (
          <div
            key={id}
            style={{
              display: "grid",
              gridTemplateColumns:
                "minmax(140px, 1.2fr) minmax(120px, 1fr) minmax(120px, 1.2fr) auto",
              gap: 8,
              alignItems: "center",
              marginBottom: 8,
            }}
            data-condition-row={index}
          >
            <select
              value={rule.field}
              onChange={(e) =>
                setRule(index, { ...rule, field: e.target.value })
              }
              style={inputStyle}
              aria-label={`${label} ${index + 1} field`}
              disabled={readOnly}
            >
              <option value="">— choose —</option>
              {rule.field && !fields.some((f) => f.key === rule.field) && (
                <option value={rule.field}>{rule.field}</option>
              )}
              {fields.map((f) => (
                <option key={f.key} value={f.key}>
                  {f.label}
                </option>
              ))}
            </select>
            <select
              value={rule.op}
              onChange={(e) =>
                setRule(index, {
                  field: rule.field,
                  op: e.target.value,
                  ...(opOf(e.target.value)?.needs_value
                    ? { value: toValue(text, e.target.value, rule.value) }
                    : {}),
                })
              }
              style={inputStyle}
              aria-label={`${label} ${index + 1} comparison`}
              disabled={readOnly}
            >
              {operators.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
            {op?.needs_value !== false ? (
              <input
                value={text}
                onChange={(e) => {
                  setTexts({ ...texts, [id]: e.target.value });
                  setRule(index, {
                    ...rule,
                    value: toValue(e.target.value, rule.op, rule.value),
                  });
                }}
                placeholder={
                  op?.multiple ? "e.g. CRITICAL, IMMOBILIZED" : "value"
                }
                title={op?.multiple ? "Separate values with commas" : undefined}
                style={inputStyle}
                aria-label={`${label} ${index + 1} value`}
                disabled={readOnly}
              />
            ) : (
              <span />
            )}
            {!readOnly && (
              <button
                type="button"
                className="btn-secondary"
                onClick={() => {
                  onChange({
                    ...value,
                    rules: rules.filter((_, i) => i !== index),
                  });
                  setIds(ids.filter((_, i) => i !== index));
                }}
                aria-label={`Remove ${label.toLowerCase()} ${index + 1}`}
              >
                ×
              </button>
            )}
          </div>
        );
      })}
      {!readOnly && (
        <button
          type="button"
          className="btn-secondary"
          onClick={() => {
            onChange({
              ...value,
              rules: [
                ...rules,
                { field: fields[0]?.key ?? "", op: "=", value: "" },
              ],
            });
            setIds([...ids, nextId]);
            setNextId(nextId + 1);
          }}
        >
          + Add condition
        </button>
      )}
    </div>
  );
}
