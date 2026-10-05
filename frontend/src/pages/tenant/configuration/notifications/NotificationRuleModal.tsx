import { useState } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { FormField, inputStyle } from "../../../../components/FormField";
import { InfoTip } from "../../../../components/InfoTip";
import { Modal } from "../../../../components/Modal";
import type {
  NotificationConditionSet,
  NotificationMetadata,
  NotificationRecipientRule,
  NotificationRuleItem,
} from "../../../../types";
import { ConditionListEditor } from "./ConditionListEditor";
import { isGroup } from "./conditions";
import {
  RecipientListEditor,
  type RecipientLookups,
} from "./RecipientListEditor";

const emptyConditions = (): NotificationConditionSet => ({
  operator: "AND",
  rules: [],
});

/** Problems the form can tell before saving (the server checks the same and more). */
function recipientProblem(
  rules: NotificationRecipientRule[],
  types: NotificationMetadata["recipient_types"],
  what: string,
): string | null {
  if (rules.length === 0) return `Add at least one ${what}.`;
  for (const rule of rules) {
    const kind = types.find((t) => t.value === rule.type)?.identifier;
    if (kind && !(rule.identifier ?? "").trim())
      return `Choose the ${kind} for each ${what}.`;
  }
  return null;
}

function conditionProblem(set: NotificationConditionSet): string | null {
  for (const rule of set.rules)
    if (!isGroup(rule) && !rule.field)
      return "Choose a field for each condition.";
  return null;
}

/**
 * Create / edit / view a notification rule with a form: event, name, channels, recipients,
 * an optional "only when" condition and an optional escalation. The form writes the existing
 * NotificationRule fields only; System Default rules open read-only.
 */
export function NotificationRuleModal({
  meta,
  lookups,
  rule,
  onClose,
  onSaved,
}: {
  meta: NotificationMetadata;
  lookups: RecipientLookups;
  rule: NotificationRuleItem | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const readOnly = !!rule?.is_system;
  const configurable = meta.events.filter((e) => !e.platform_locked);
  const [eventCode, setEventCode] = useState(
    rule?.event_code ?? configurable[0]?.code ?? "",
  );
  const [name, setName] = useState(rule?.name ?? "");
  const [channels, setChannels] = useState<string[]>(
    rule?.channels ?? ["IN_APP"],
  );
  const [recipients, setRecipients] = useState<NotificationRecipientRule[]>(
    rule?.recipient_rules?.length
      ? rule.recipient_rules
      : [{ type: "ROLE", identifier: "" }],
  );
  const [useConditions, setUseConditions] = useState(
    !!rule?.condition_set?.rules?.length,
  );
  const [conditions, setConditions] = useState<NotificationConditionSet>(
    rule?.condition_set?.rules?.length ? rule.condition_set : emptyConditions(),
  );
  const [useEscalation, setUseEscalation] = useState(!!rule?.escalation);
  const [minutes, setMinutes] = useState(
    rule?.escalation ? String(rule.escalation.after_minutes ?? "") : "120",
  );
  const [escalationRecipients, setEscalationRecipients] = useState<
    NotificationRecipientRule[]
  >(
    rule?.escalation?.recipient_rules?.length
      ? rule.escalation.recipient_rules
      : [{ type: "ROLE", identifier: "" }],
  );
  const [useUnresolved, setUseUnresolved] = useState(
    !!rule?.escalation?.unresolved_condition_set?.rules?.length,
  );
  const [unresolved, setUnresolved] = useState<NotificationConditionSet>(
    rule?.escalation?.unresolved_condition_set?.rules?.length
      ? rule.escalation.unresolved_condition_set
      : {
          operator: "AND",
          rules: [{ field: "status", op: "!=", value: "RESOLVED" }],
        },
  );
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const event = meta.events.find((e) => e.code === eventCode);
  const fields = event?.variables ?? [];
  const minutesNumber = Number(minutes);

  const problem = (): string | null => {
    if (!eventCode) return "Choose the event.";
    if (!name.trim()) return "Enter a name.";
    if (channels.length === 0) return "Choose at least one channel.";
    const r = recipientProblem(recipients, meta.recipient_types, "recipient");
    if (r) return r;
    if (useConditions) {
      if (conditions.rules.length === 0)
        return "Add a condition or untick “Only send when”.";
      const c = conditionProblem(conditions);
      if (c) return c;
    }
    if (useEscalation) {
      if (!/^\d+$/.test(minutes) || minutesNumber < 1)
        return "Enter the escalation waiting time in whole minutes (at least 1).";
      const e = recipientProblem(
        escalationRecipients,
        meta.recipient_types,
        "escalation recipient",
      );
      if (e) return e;
      if (useUnresolved && unresolved.rules.length === 0)
        return "Add a status condition or untick “Only escalate while”.";
    }
    return null;
  };

  async function save() {
    const p = problem();
    if (p) return setError(p);
    setError(null);
    setSaving(true);
    const body = {
      name: name.trim(),
      channels,
      recipient_rules: recipients.map((r) =>
        r.identifier !== undefined
          ? { type: r.type, identifier: r.identifier.trim() }
          : { type: r.type },
      ),
      condition_set: useConditions ? conditions : null,
      escalation: useEscalation
        ? {
            after_minutes: minutesNumber,
            recipient_rules: escalationRecipients,
            unresolved_condition_set: useUnresolved ? unresolved : null,
          }
        : null,
    };
    try {
      if (rule) await apiClient.put(`/app/notification-rules/${rule.id}`, body);
      else
        await apiClient.post("/app/notification-rules", {
          event_code: eventCode,
          ...body,
        });
      onSaved();
    } catch (e) {
      setError(extractApiError(e).message);
    } finally {
      setSaving(false);
    }
  }

  const section = (title: string, tip: string) => (
    <div
      style={{
        fontWeight: 600,
        fontSize: 14,
        margin: "18px 0 8px",
        display: "flex",
        alignItems: "center",
      }}
    >
      {title}
      <InfoTip label={title}>{tip}</InfoTip>
    </div>
  );

  const duration =
    minutesNumber >= 60
      ? `= ${Math.floor(minutesNumber / 60)} h${minutesNumber % 60 ? ` ${minutesNumber % 60} min` : ""}`
      : "";

  return (
    <Modal
      open
      title={
        readOnly
          ? `System Default Rule — ${rule!.name}`
          : rule
            ? `Edit Notification Rule — ${rule.name}`
            : "New Notification Rule"
      }
      onClose={onClose}
      width={760}
    >
      <div data-notification-rule-form>
        {readOnly && (
          <p style={{ fontSize: 13, color: "#a16207", marginTop: 0 }}>
            Provided by the system and protected — it cannot be changed. Create
            your own rule for the same event to notify other people.
          </p>
        )}
        <FormField
          label="Event"
          required
          hint="What happens in the system that sends this notification."
        >
          <select
            value={eventCode}
            onChange={(e) => {
              setEventCode(e.target.value);
              setConditions(emptyConditions());
              setUseConditions(false);
            }}
            style={inputStyle}
            disabled={!!rule}
            aria-label="Event"
          >
            {(rule ? meta.events : configurable).map((e) => (
              <option key={e.code} value={e.code}>
                {e.label}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Name" required>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            style={inputStyle}
            placeholder="e.g. Critical breakdown alert"
            disabled={readOnly}
            aria-label="Name"
          />
        </FormField>
        <FormField
          label="Send by"
          required
          hint="In-App shows in the bell menu; Email is sent to the recipient's email address."
        >
          <div style={{ display: "flex", gap: 16 }}>
            {meta.channels.map((c) => (
              <label key={c.value} style={{ fontSize: 14 }}>
                <input
                  type="checkbox"
                  checked={channels.includes(c.value)}
                  disabled={readOnly}
                  onChange={(e) =>
                    setChannels(
                      e.target.checked
                        ? [...channels, c.value]
                        : channels.filter((x) => x !== c.value),
                    )
                  }
                />{" "}
                {c.label}
              </label>
            ))}
          </div>
        </FormField>

        {section(
          "Recipients",
          "Who receives the notification. Several rows are combined; each person receives it once.",
        )}
        <RecipientListEditor
          value={recipients}
          onChange={setRecipients}
          types={meta.recipient_types}
          lookups={lookups}
          label="Recipient"
          readOnly={readOnly}
        />

        {section(
          "Conditions",
          "Without conditions the notification is sent every time the event happens.",
        )}
        <label style={{ fontSize: 14, display: "block", marginBottom: 8 }}>
          <input
            type="checkbox"
            checked={useConditions}
            disabled={readOnly}
            onChange={(e) => {
              setUseConditions(e.target.checked);
              if (e.target.checked && conditions.rules.length === 0)
                setConditions({
                  operator: "AND",
                  rules: [{ field: fields[0]?.key ?? "", op: "=", value: "" }],
                });
            }}
          />{" "}
          Only send when…
        </label>
        {useConditions && (
          <ConditionListEditor
            key={eventCode}
            value={conditions}
            onChange={setConditions}
            fields={fields}
            operators={meta.operators}
            label="Condition"
            readOnly={readOnly}
          />
        )}

        {section(
          "Escalation",
          "If the record is still open after the waiting time, the escalation recipients are notified once.",
        )}
        <label style={{ fontSize: 14, display: "block", marginBottom: 8 }}>
          <input
            type="checkbox"
            checked={useEscalation}
            disabled={readOnly}
            onChange={(e) => setUseEscalation(e.target.checked)}
          />{" "}
          Escalate if not handled
        </label>
        {useEscalation && (
          <div
            style={{ borderLeft: "3px solid #e5e7eb", paddingLeft: 12 }}
            data-escalation
          >
            <FormField label="Wait (minutes)" required>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <input
                  value={minutes}
                  onChange={(e) =>
                    setMinutes(
                      e.target.value.replace(/[^0-9]/g, "").slice(0, 6),
                    )
                  }
                  inputMode="numeric"
                  aria-label="Wait (minutes)"
                  style={{ ...inputStyle, width: 120 }}
                  disabled={readOnly}
                />
                <span style={{ fontSize: 12, color: "#6b7280" }}>
                  {duration}
                </span>
              </div>
            </FormField>
            <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 6 }}>
              Escalate to
            </div>
            <RecipientListEditor
              value={escalationRecipients}
              onChange={setEscalationRecipients}
              types={meta.recipient_types}
              lookups={lookups}
              label="Escalation recipient"
              readOnly={readOnly}
            />
            <div
              style={{
                fontSize: 14,
                margin: "12px 0 8px",
                display: "flex",
                alignItems: "center",
              }}
            >
              <label>
                <input
                  type="checkbox"
                  checked={useUnresolved}
                  disabled={readOnly}
                  onChange={(e) => setUseUnresolved(e.target.checked)}
                />{" "}
                Only escalate while…
              </label>
              <InfoTip label="Only escalate while">
                Checked when the waiting time is over, against the record's
                current status (e.g. Status is not RESOLVED).
              </InfoTip>
            </div>
            {useUnresolved && (
              <ConditionListEditor
                value={unresolved}
                onChange={setUnresolved}
                fields={meta.unresolved_fields}
                operators={meta.operators}
                label="Escalation condition"
                readOnly={readOnly}
              />
            )}
          </div>
        )}

        {error && (
          <div
            role="alert"
            style={{ color: "#b91c1c", fontSize: 13, marginTop: 12 }}
            data-rule-error
          >
            {error}
          </div>
        )}
        <div
          style={{
            display: "flex",
            justifyContent: "flex-end",
            gap: 8,
            marginTop: 16,
          }}
        >
          <button className="btn-secondary" onClick={onClose}>
            {readOnly ? "Close" : "Cancel"}
          </button>
          {!readOnly && (
            <button className="btn-primary" disabled={saving} onClick={save}>
              {rule ? "Save Changes" : "Create Rule"}
            </button>
          )}
        </div>
      </div>
    </Modal>
  );
}
