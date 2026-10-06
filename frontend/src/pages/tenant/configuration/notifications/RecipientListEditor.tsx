import { inputStyle } from "../../../../components/FormField";
import type {
  NotificationMetadata,
  NotificationRecipientRule,
} from "../../../../types";
import { t as tt } from '../../../../i18n/i18n';

/** Choices for recipients that name someone: tenant users, roles and permissions. */
export interface RecipientLookups {
  users: Array<{ user_id: string; name: string; email: string }> | null;
  roles: Array<{ name: string }> | null;
  permissions: Array<{
    name: string;
    description: string | null;
    module_name?: string;
  }> | null;
}

/**
 * Who receives a notification: one row per recipient — a type (Specific User, Everyone with a
 * Role, Branch Manager, Requester, …) and, for the types that need one, the user / role /
 * permission / email it means. Stored as the existing recipient_rules [{type, identifier}].
 */
export function RecipientListEditor({
  value,
  onChange,
  types,
  lookups,
  label,
  readOnly,
}: {
  value: NotificationRecipientRule[];
  onChange: (value: NotificationRecipientRule[]) => void;
  types: NotificationMetadata["recipient_types"];
  lookups: RecipientLookups;
  label: string;
  readOnly?: boolean;
}) {
  const kindOf = (type: string) =>
    types.find((t) => t.value === type)?.identifier ?? null;
  const update = (index: number, rule: NotificationRecipientRule) =>
    onChange(value.map((r, i) => (i === index ? rule : r)));

  const identifierControl = (
    rule: NotificationRecipientRule,
    index: number,
  ) => {
    const kind = kindOf(rule.type);
    const current = rule.identifier ?? "";
    const aria = `${label} ${index + 1} — ${kind ?? ""}`;
    const set = (identifier: string) => update(index, { ...rule, identifier });
    const select = (options: Array<{ value: string; label: string }>) => (
      <select
        value={current}
        onChange={(e) => set(e.target.value)}
        style={inputStyle}
        aria-label={aria}
        disabled={readOnly}
      >
        <option value="">{tt('configuration.fields.choose')}</option>
        {/* A saved value that is no longer offered stays selectable, so nothing is lost. */}
        {current && !options.some((o) => o.value === current) && (
          <option value={current}>{current}</option>
        )}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    );
    if (kind === "user" && lookups.users)
      return select(
        lookups.users.map((u) => ({
          value: u.user_id,
          label: `${u.name} (${u.email})`,
        })),
      );
    if (kind === "role" && lookups.roles)
      return select(
        lookups.roles.map((r) => ({ value: r.name, label: r.name })),
      );
    if (kind === "permission" && lookups.permissions)
      return select(
        lookups.permissions.map((p) => ({
          value: p.name,
          label: `${p.description || p.name}${p.module_name ? ` — ${p.module_name}` : ""}`,
        })),
      );
    if (kind)
      return (
        <input
          value={current}
          onChange={(e) => set(e.target.value)}
          style={inputStyle}
          aria-label={aria}
          type={kind === "email" ? "email" : "text"}
          placeholder={kind === "email" ? tt('configuration.placeholders.nameCompanyCom') : ""}
          disabled={readOnly}
        />
      );
    return (
      <span style={{ fontSize: 12, color: "#6b7280" }}>
        {types.find((t) => t.value === rule.type)?.description}
      </span>
    );
  };

  return (
    <div data-recipient-list={label}>
      {value.map((rule, index) => (
        <div
          key={index}
          style={{
            display: "grid",
            gridTemplateColumns: "minmax(150px, 1fr) minmax(160px, 1.4fr) auto",
            gap: 8,
            alignItems: "center",
            marginBottom: 8,
          }}
          data-recipient-row={index}
        >
          <select
            value={rule.type}
            onChange={(e) => update(index, { type: e.target.value })}
            style={inputStyle}
            aria-label={tt('configuration.fields.labelValueType', { label: label, value: index + 1 })}
            disabled={readOnly}
          >
            {!types.some((t) => t.value === rule.type) && (
              <option value={rule.type}>{rule.type}</option>
            )}
            {types.map((t) => (
              <option key={t.value} value={t.value} title={t.description}>
                {t.label}
              </option>
            ))}
          </select>
          {identifierControl(rule, index)}
          {!readOnly && (
            <button
              type="button"
              className="btn-secondary"
              onClick={() => onChange(value.filter((_, i) => i !== index))}
              aria-label={tt('configuration.actions.removeToLowerCaseValue', { toLowerCase: label.toLowerCase(), value: index + 1 })}
              disabled={value.length === 1}
            >
              ×
            </button>
          )}
        </div>
      ))}
      {!readOnly && (
        <button
          type="button"
          className="btn-secondary"
          onClick={() =>
            onChange([...value, { type: types[0]?.value ?? "ROLE" }])
          }
        >
          {tt('configuration.actions.addToLowerCase', { toLowerCase: label.toLowerCase() })}</button>
      )}
    </div>
  );
}
