import type { WorkflowIssue } from "../../../../types";

/** Validation results; each problem selects the status or transition it concerns. */
export function WorkflowValidationPanel({
  result,
  transitionIdAt,
  onSelectStatus,
  onSelectTransition,
}: {
  result: { errors: WorkflowIssue[]; warnings: WorkflowIssue[] } | null;
  transitionIdAt: (index: number) => string | undefined;
  onSelectStatus: (code: string) => void;
  onSelectTransition: (id: string) => void;
}) {
  if (!result) return null;
  const item = (
    issue: WorkflowIssue,
    kind: "error" | "warning",
    key: number,
  ) => {
    const id =
      issue.transition !== undefined
        ? transitionIdAt(issue.transition)
        : undefined;
    const target = issue.status ?? (id ? "transition" : null);
    return (
      <li key={`${kind}${key}`} style={{ marginBottom: 4 }}>
        <span
          style={{
            color: kind === "error" ? "#b91c1c" : "#b45309",
            fontWeight: 700,
            marginRight: 6,
          }}
        >
          {kind === "error" ? "Error" : "Warning"}
        </span>
        {issue.message}{" "}
        {target && (
          <button
            type="button"
            onClick={() =>
              issue.status
                ? onSelectStatus(issue.status)
                : id && onSelectTransition(id)
            }
            style={{
              background: "none",
              border: "none",
              color: "#2563eb",
              cursor: "pointer",
              padding: 0,
              fontSize: 12,
            }}
          >
            Show
          </button>
        )}
      </li>
    );
  };
  const total = result.errors.length + result.warnings.length;
  return (
    <div
      role="status"
      aria-live="polite"
      data-validation-panel
      style={{ fontSize: 12 }}
    >
      {total === 0 ? (
        <span style={{ color: "#15803d", fontWeight: 600 }} data-validation-ok>
          The workflow is valid.
        </span>
      ) : (
        <ul style={{ margin: 0, paddingLeft: 16 }}>
          {result.errors.map((e, i) => item(e, "error", i))}
          {result.warnings.map((w, i) => item(w, "warning", i))}
        </ul>
      )}
    </div>
  );
}
