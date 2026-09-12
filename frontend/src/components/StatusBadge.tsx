const COLORS: Record<string, string> = {
  ACTIVE: '#15803d',
  active: '#15803d',
  DRAFT: '#a16207',
  INACTIVE: '#6b7280',
  inactive: '#6b7280',
  CLOSED: '#b91c1c',
  SUSPENDED: '#b91c1c',
  PUBLISHED: '#15803d',
  ARCHIVED: '#6b7280',
  QUARANTINED: '#b91c1c',
  REPAIR: '#a16207',
};

export function StatusBadge({ status }: { status: string }) {
  const color = COLORS[status] ?? '#374151';
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
        textTransform: 'uppercase',
        letterSpacing: 0.3,
      }}
    >
      {status}
    </span>
  );
}
