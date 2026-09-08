const COLORS: Record<string, string> = {
  HEALTHY: '#15803d',
  GOOD: '#15803d',
  LOW: '#15803d',
  WATCH: '#a16207',
  MEDIUM: '#a16207',
  AT_RISK: '#c2410c',
  HIGH: '#c2410c',
  CRITICAL: '#b91c1c',
  URGENT: '#b91c1c',
  DATA_QUALITY: '#6b7280',
  UNKNOWN: '#6b7280',
};

// Phase 7 Section 18/22/56: risk/health/urgency levels share one visual
// vocabulary across the whole Intelligence UI.
export function RiskBadge({ level }: { level: string | null | undefined }) {
  if (!level) return <span style={{ color: '#9ca3af', fontSize: 12 }}>—</span>;
  const color = COLORS[level] ?? '#374151';
  return (
    <span
      style={{
        display: 'inline-block',
        padding: '2px 10px',
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 600,
        color: '#fff',
        background: color,
      }}
    >
      {level}
    </span>
  );
}
