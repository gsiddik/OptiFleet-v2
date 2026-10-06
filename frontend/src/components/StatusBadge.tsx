import { statusLabel, type StatusDomain } from '../i18n/statusRegistry';

const COLORS: Record<string, string> = {
  ACTIVE: '#15803d',
  RESERVED: '#b45309',
  TRANSFERRED: '#6366f1',
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
  // Purchase Order Return to Vendor
  REFUND_REQUESTED: '#a16207',
  REFUND_ACCEPTED: '#15803d',
  REDELIVERY_REQUESTED: '#a16207',
  REDELIVERY_READY: '#1d4ed8',
  REDELIVERY_PENDING: '#b45309',
  REDELIVERY_RECEIVED: '#15803d',
  // Used tire lifecycle (only REUSE is available stock)
  REMOVED: '#7c3aed',
  REUSE: '#15803d',
  HOLD: '#b45309',
  RETREAD: '#0f766e',
  SCRAPPED: '#6b7280',
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

/**
 * The badge shows the registry display label of the canonical code (never the reformatted code).
 * `domain` disambiguates codes such as ISSUED (document vs stock). The canonical value is unchanged.
 */
export function StatusBadge({ status, domain }: { status: string; domain?: StatusDomain }) {
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
      {statusLabel(status, domain)}
    </span>
  );
}
