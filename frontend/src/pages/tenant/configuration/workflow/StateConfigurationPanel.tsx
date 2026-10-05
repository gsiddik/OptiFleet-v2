import { useState } from "react";
import { inputStyle } from "../../../../components/FormField";
import { InfoTip } from "../../../../components/InfoTip";
import type { WorkflowStatusDef } from "../../../../types";
import { labelOf, type EditableTransition } from "./workflowGraph";

/**
 * The selected status: its name, whether documents may start in it, and its transitions —
 * including "Add transition to…", the keyboard alternative to dragging an arrow.
 */
export function StateConfigurationPanel({
  status,
  statuses,
  transitions,
  targets,
  readOnly,
  onChange,
  onAddTransition,
  onSelectTransition,
  onRemove,
}: {
  status: WorkflowStatusDef;
  statuses: WorkflowStatusDef[];
  transitions: EditableTransition[];
  /** Statuses a transition may lead into (null: any). */
  targets: string[] | null;
  readOnly: boolean;
  onChange: (status: WorkflowStatusDef) => void;
  onAddTransition: (to: string) => void;
  onSelectTransition: (id: string) => void;
  onRemove: () => void;
}) {
  const [target, setTarget] = useState("");
  const outgoing = transitions.filter((t) => t.from_status === status.code);
  const incoming = transitions.filter((t) => t.to_status === status.code);
  const name = (code: string) => {
    const s = statuses.find((x) => x.code === code);
    return s ? labelOf(s) : code;
  };
  const options = statuses.filter(
    (s) =>
      s.code !== status.code &&
      (targets === null || targets.includes(s.code)) &&
      !outgoing.some((t) => t.to_status === s.code),
  );

  return (
    <div data-state-panel={status.code}>
      <h3 style={{ fontSize: 15, margin: "0 0 10px" }}>Status</h3>
      <label style={labelStyle}>
        Code
        <input
          value={status.code}
          readOnly
          style={{ ...inputStyle, background: "#f3f4f6" }}
          aria-label="Status code"
        />
      </label>
      <label style={labelStyle}>
        Name
        <input
          value={status.display_name ?? ""}
          onChange={(e) =>
            onChange({ ...status, display_name: e.target.value })
          }
          style={inputStyle}
          disabled={readOnly}
          aria-label="Status name"
        />
      </label>
      <div
        style={{
          display: "flex",
          alignItems: "center",
          fontSize: 13,
          margin: "6px 0 12px",
        }}
      >
        <label>
          <input
            type="checkbox"
            checked={!!status.is_start}
            onChange={(e) =>
              onChange({ ...status, is_start: e.target.checked })
            }
            disabled={readOnly}
          />{" "}
          Start status
        </label>
        <InfoTip label="Start status">
          A document can begin in (or be put into by the system) a start status
          without a transition leading to it.
        </InfoTip>
      </div>
      {outgoing.length === 0 && (
        <p style={{ fontSize: 12, color: "#475569", margin: "0 0 10px" }}>
          End status: no transition leads out of it.
        </p>
      )}

      <div style={sectionStyle}>Transitions out ({outgoing.length})</div>
      {outgoing.map((t) => (
        <button
          key={t._id}
          type="button"
          className="btn-secondary"
          style={listButton}
          onClick={() => onSelectTransition(t._id)}
        >
          {t.action_label || t.action_code} → {name(t.to_status)}
        </button>
      ))}
      {!readOnly && (
        <div style={{ display: "flex", gap: 6, marginTop: 6 }}>
          <select
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            style={inputStyle}
            aria-label="Add transition to"
          >
            <option value="">Add transition to…</option>
            {options.map((s) => (
              <option key={s.code} value={s.code}>
                {labelOf(s)}
              </option>
            ))}
          </select>
          <button
            type="button"
            className="btn-secondary"
            disabled={!target}
            onClick={() => {
              onAddTransition(target);
              setTarget("");
            }}
          >
            Add
          </button>
        </div>
      )}

      <div style={sectionStyle}>Transitions in ({incoming.length})</div>
      {incoming.map((t) => (
        <button
          key={t._id}
          type="button"
          className="btn-secondary"
          style={listButton}
          onClick={() => onSelectTransition(t._id)}
        >
          {name(t.from_status)} → {t.action_label || t.action_code}
        </button>
      ))}

      {!readOnly && (
        <button
          type="button"
          className="btn-secondary"
          style={{ marginTop: 16, color: "#b91c1c" }}
          onClick={onRemove}
        >
          Remove status from workflow
        </button>
      )}
    </div>
  );
}

const labelStyle = {
  display: "block",
  fontSize: 12,
  fontWeight: 600,
  color: "#374151",
  marginBottom: 8,
} as const;
const sectionStyle = {
  fontSize: 12,
  fontWeight: 700,
  color: "#6b7280",
  margin: "14px 0 6px",
  textTransform: "uppercase",
} as const;
const listButton = {
  display: "block",
  width: "100%",
  textAlign: "left",
  marginBottom: 4,
  fontSize: 12,
} as const;
