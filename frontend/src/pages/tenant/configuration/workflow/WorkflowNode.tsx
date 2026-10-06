import { Handle, Position, type Node, type NodeProps } from "@xyflow/react";
import { t } from '../../../../i18n/i18n';

export interface WorkflowNodeData extends Record<string, unknown> {
  label: string;
  code: string;
  isStart: boolean;
  isEnd: boolean;
  errors: string[];
  warnings: string[];
}

export type WorkflowFlowNode = Node<WorkflowNodeData, "status">;

const handleStyle = {
  width: 12,
  height: 12,
  background: "#fff",
  border: "2px solid #2563eb",
};

/**
 * A status card. Drag the right dot to another card to create a transition (the left dot
 * receives arrows). Start / End markers and validation problems show on the card itself.
 */
export function WorkflowNode({ data, selected }: NodeProps<WorkflowFlowNode>) {
  const problem =
    data.errors.length > 0
      ? "error"
      : data.warnings.length > 0
        ? "warning"
        : null;
  const border =
    problem === "error"
      ? "#dc2626"
      : problem === "warning"
        ? "#d97706"
        : selected
          ? "#2563eb"
          : "#cbd5e1";
  const description = [
    data.isStart ? t('configuration.fields.startStatus2') : null,
    data.isEnd ? t('configuration.fields.endStatus') : null,
    ...data.errors,
    ...data.warnings,
  ]
    .filter(Boolean)
    .join(". ");
  return (
    <div
      aria-label={t('configuration.tooltips.statusLabelValue', { label: data.label, value: description ? ` — ${description}` : "" })}
      title={[...data.errors, ...data.warnings].join("\n") || undefined}
      data-status-node={data.code}
      data-node-problem={problem ?? undefined}
      style={{
        minWidth: 170,
        maxWidth: 220,
        padding: "8px 12px",
        background: "#fff",
        border: `2px solid ${border}`,
        borderRadius: 10,
        boxShadow: selected
          ? "0 0 0 3px rgba(37,99,235,0.2)"
          : "0 1px 3px rgba(0,0,0,0.08)",
        fontSize: 13,
      }}
    >
      <Handle type="target" position={Position.Left} style={handleStyle} />
      <div
        style={{ display: "flex", gap: 4, marginBottom: 2, flexWrap: "wrap" }}
      >
        {data.isStart && (
          <span style={badge("#dcfce7", "#166534")} data-start-marker>
            {t('common.fields.start')}
          </span>
        )}
        {data.isEnd && (
          <span style={badge("#f1f5f9", "#475569")} data-end-marker>
            {t('common.fields.end')}
          </span>
        )}
        {problem && (
          <span
            style={
              problem === "error"
                ? badge("#fee2e2", "#991b1b")
                : badge("#fef3c7", "#92400e")
            }
          >
            {problem === "error" ? t('configuration.fields.error') : t('configuration.warnings.warning')}
          </span>
        )}
      </div>
      <div
        style={{ fontWeight: 600, color: "#111827", overflowWrap: "anywhere" }}
      >
        {data.label}
      </div>
      <div
        style={{
          fontSize: 11,
          color: "#6b7280",
          fontFamily: "ui-monospace, monospace",
        }}
      >
        {data.code}
      </div>
      <Handle type="source" position={Position.Right} style={handleStyle} />
    </div>
  );
}

function badge(background: string, color: string) {
  return {
    background,
    color,
    fontSize: 10,
    fontWeight: 700,
    padding: "1px 6px",
    borderRadius: 8,
    textTransform: "uppercase" as const,
  };
}
