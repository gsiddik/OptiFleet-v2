/** Usage-restriction warnings: shown, never blocking (enforced once position codes are standardized). */
export function UsageRestrictionWarnings({ warnings }: { warnings: string[] }) {
  if (warnings.length === 0) return null;
  return (
    <div
      role="alert"
      data-usage-warnings
      style={{
        border: "1px solid #f59e0b",
        background: "#fffbeb",
        color: "#92400e",
        borderRadius: 8,
        padding: "8px 12px",
        fontSize: 13,
      }}
    >
      <strong>Usage restriction warning</strong>
      <ul style={{ margin: "4px 0 0", paddingLeft: 18 }}>
        {warnings.map((w) => (
          <li key={w}>{w}</li>
        ))}
      </ul>
    </div>
  );
}
