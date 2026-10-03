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
  SOLD: '#6b7280',
  SUBMITTED: '#1d4ed8',
  UNDER_REVIEW: '#7c3aed',
  APPROVED: '#15803d',
  WORK_ORDER_CREATED: '#0f766e',
  REJECTED: '#b91c1c',
  CANCELLED: '#6b7280',
  NEED_INFORMATION: '#a16207',
  // Part Requests / Return / Used Sparepart Processing
  REQUESTED: '#1d4ed8',
  ISSUED: '#0f766e',
  PENDING_PROCESSING: '#a16207',
  RESTOCKED: '#15803d',
  PENDING_RETURN: '#a16207',
  PENDING_INSPECTION: '#7c3aed',
  WARRANTY_CLAIM: '#1d4ed8',
  SCRAP: '#6b7280',
  // Vendor invoices
  NEW: '#1d4ed8',
  DUE_SOON: '#b45309',
  LATE: '#b91c1c',
  PAID: '#15803d',
  // Tire Operations / Work Orders
  IN_PROGRESS: '#b45309',
  COMPLETED: '#15803d',
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
