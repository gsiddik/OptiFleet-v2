import type {
  WorkOrderItem,
  WorkspaceAssignmentStatus,
  WorkspaceReservationItem,
} from "../../../../types";
import { formatDateTime } from "../../../../utils/date";

/** Mirrors WorkspaceReservationService::SCHEDULABLE_WORK_ORDER_STATUSES (backend re-checks). */
export const SCHEDULABLE_WORK_ORDER_STATUSES = [
  "DRAFT",
  "SUBMITTED",
  "APPROVED",
  "ASSIGNED",
  "SCHEDULED",
  "QC_PENDING",
];

/** Mirrors WorkspaceReservationService::TRANSFERABLE_WORK_ORDER_STATUSES. */
export const TRANSFERABLE_WORK_ORDER_STATUSES = [
  "DRAFT",
  "SUBMITTED",
  "APPROVED",
  "ASSIGNED",
  "SCHEDULED",
  "IN_PROGRESS",
  "ON_HOLD",
  "WAITING_PART",
  "REWORK",
  "QC_PENDING",
];

/** Legacy ACTIVE assignments count as approved. */
export const APPROVED_ASSIGNMENT_STATUSES: WorkspaceAssignmentStatus[] = [
  "APPROVED",
  "ACTIVE",
];

export const CURRENT_ASSIGNMENT_STATUSES: WorkspaceAssignmentStatus[] = [
  "RESERVED",
  "APPROVED",
  "ACTIVE",
];

export const START_BLOCKED_REASON =
  "Cannot start this Work Order because no approved Workspace and scheduled work date are assigned.";

export const ASSIGNMENT_STATUS_LABELS: Record<
  WorkspaceAssignmentStatus,
  string
> = {
  RESERVED: "Requested",
  APPROVED: "Approved",
  ACTIVE: "Approved",
  TRANSFERRED: "Transferred",
  COMPLETED: "Completed",
  CANCELLED: "Cancelled",
};

/** The Work Order's current assignment (at most one — the backend enforces it). */
export function currentAssignment(
  wo: WorkOrderItem,
): WorkspaceReservationItem | null {
  return (
    (wo.workspace_reservations ?? []).find((r) =>
      CURRENT_ASSIGNMENT_STATUSES.includes(r.status),
    ) ?? null
  );
}

export function isApproved(
  r: WorkspaceReservationItem | null | undefined,
): boolean {
  return !!r && APPROVED_ASSIGNMENT_STATUSES.includes(r.status);
}

/** SCHEDULED → IN_PROGRESS needs an approved assignment with a valid window. */
export function canStart(wo: WorkOrderItem): boolean {
  const current = currentAssignment(wo);
  return (
    isApproved(current) &&
    !!current?.start_at &&
    !!current?.end_at &&
    current.end_at > current.start_at
  );
}

/** datetime-local value (local time) → ISO instant, so the backend stores the moment the user picked. */
export function toIso(local: string): string {
  return new Date(local).toISOString();
}

/** ISO instant → datetime-local value in the user's time zone. */
export function toLocalInput(iso: string | Date): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Default window: the next full hour, three hours long. */
export function defaultWindow(): { start: string; end: string } {
  const start = new Date();
  start.setMinutes(0, 0, 0);
  start.setHours(start.getHours() + 1);
  const end = new Date(start);
  end.setHours(end.getHours() + 3);
  return { start: toLocalInput(start), end: toLocalInput(end) };
}

export function formatWindow(
  r: Pick<WorkspaceReservationItem, "start_at" | "end_at">,
): string {
  return `${formatDateTime(r.start_at)} – ${formatDateTime(r.end_at)}`;
}
