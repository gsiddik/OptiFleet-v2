import { useState } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { useAuth } from "../../../../auth/AuthContext";
import { EmptyState, ErrorState } from "../../../../components/States";
import { StatusBadge } from "../../../../components/StatusBadge";
import type {
  WorkOrderItem,
  WorkspaceReservationItem,
} from "../../../../types";
import { TransferWorkspaceModal } from "./ScheduleWorkspaceModal";
import {
  ASSIGNMENT_STATUS_LABELS,
  TRANSFERABLE_WORK_ORDER_STATUSES,
  currentAssignment,
  formatWindow,
  isApproved,
} from "./workspaceAssignment";

const cell = { padding: "6px 4px" } as const;

/**
 * Work Order → Workspace: the approved assignment (source of truth for where and when the work
 * runs), a pending request, and the full assignment history (transfers, completed, cancelled).
 */
export function WorkOrderWorkspaceTab({
  wo,
  onChanged,
}: {
  wo: WorkOrderItem;
  onChanged: () => void;
}) {
  const { hasPermission } = useAuth();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [transferring, setTransferring] = useState(false);
  const reservations: WorkspaceReservationItem[] =
    wo.workspace_reservations ?? [];
  const current = currentAssignment(wo);
  const approved = isApproved(current) ? current : null;
  const pending = current && !approved ? current : null;
  const history = reservations.filter((r) => r.id !== current?.id);
  const canTransfer =
    !!approved &&
    hasPermission("workspace.approve") &&
    TRANSFERABLE_WORK_ORDER_STATUSES.includes(wo.status);

  async function act(id: string, action: "approve" | "cancel") {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/workspace-reservations/${id}/${action}`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  const ws = approved?.workspace;
  const field = (label: string, value: React.ReactNode) => (
    <div style={{ fontSize: 13 }}>
      <div style={{ color: "#6b7280", fontSize: 12 }}>{label}</div>
      <div>{value ?? "—"}</div>
    </div>
  );

  return (
    <div>
      {error && <ErrorState message={error} />}
      <div
        className="card"
        style={{ marginBottom: 16 }}
        data-approved-workspace
      >
        <div
          style={{
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            gap: 8,
            flexWrap: "wrap",
            marginBottom: 10,
          }}
        >
          <h3 style={{ margin: 0, fontSize: 15 }}>Assigned Workspace</h3>
          {canTransfer && (
            <button
              className="btn-secondary"
              onClick={() => setTransferring(true)}
            >
              Transfer to Another Workspace
            </button>
          )}
        </div>
        {approved ? (
          <div
            style={{
              display: "grid",
              gridTemplateColumns: "repeat(auto-fill, minmax(180px, 1fr))",
              gap: 12,
            }}
          >
            {field(
              "Workspace",
              `${ws?.name ?? approved.workspace_id}${ws?.code ? ` (${ws.code})` : ""}`,
            )}
            {field("Workshop", ws?.workshop?.name)}
            {field("Type", ws?.workspace_type?.replaceAll("_", " "))}
            {field(
              "Capacity",
              ws?.capacity != null
                ? `${ws.capacity}${ws.capacity_unit ? ` ${ws.capacity_unit}` : ""}`
                : "1",
            )}
            {field("Scheduled", formatWindow(approved))}
            {field(
              "Assignment Status",
              <StatusBadge status={approved.status} />,
            )}
            {field(
              "Approved At",
              approved.approved_at
                ? new Date(approved.approved_at).toLocaleString()
                : "—",
            )}
            {field("Approved By", approved.approver?.name)}
          </div>
        ) : (
          <EmptyState
            label={
              pending
                ? "The workspace request is awaiting approval."
                : "No approved workspace yet. Use Schedule Workspace to request one."
            }
          />
        )}
      </div>

      {pending && (
        <div
          className="card"
          style={{ marginBottom: 16 }}
          data-pending-workspace
        >
          <h3 style={{ marginTop: 0, fontSize: 15 }}>
            Requested Workspace (awaiting approval)
          </h3>
          <p style={{ fontSize: 13 }}>
            {pending.workspace?.name ?? pending.workspace_id}{" "}
            {pending.workspace?.code ? `(${pending.workspace.code})` : ""} —{" "}
            {formatWindow(pending)}
          </p>
          <div style={{ display: "flex", gap: 8 }}>
            {hasPermission("workspace.approve") && (
              <button
                className="btn-primary"
                disabled={busy}
                onClick={() => act(pending.id, "approve")}
              >
                Approve
              </button>
            )}
            {hasPermission("workspace.reserve") && (
              <button
                className="btn-secondary"
                disabled={busy}
                onClick={() => act(pending.id, "cancel")}
              >
                Cancel Request
              </button>
            )}
          </div>
        </div>
      )}

      <div className="card" data-workspace-history>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Assignment History</h3>
        {history.length === 0 ? (
          <EmptyState label="No earlier workspace assignments." />
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table
              style={{
                width: "100%",
                borderCollapse: "collapse",
                fontSize: 13,
              }}
            >
              <thead>
                <tr
                  style={{
                    textAlign: "left",
                    borderBottom: "1px solid #e5e7eb",
                  }}
                >
                  <th style={cell}>Workspace</th>
                  <th style={cell}>Scheduled</th>
                  <th style={cell}>Status</th>
                  <th style={cell}>Approved</th>
                  <th style={cell}>Closed</th>
                </tr>
              </thead>
              <tbody>
                {history.map((r) => (
                  <tr
                    key={r.id}
                    style={{ borderBottom: "1px solid #f3f4f6" }}
                    data-history-row={r.status}
                  >
                    <td style={cell}>
                      {r.workspace?.name ?? r.workspace_id}{" "}
                      {r.workspace?.code ? `(${r.workspace.code})` : ""}
                    </td>
                    <td style={cell}>{formatWindow(r)}</td>
                    <td style={cell} title={ASSIGNMENT_STATUS_LABELS[r.status]}>
                      <StatusBadge status={r.status} />
                    </td>
                    <td style={cell}>
                      {r.approved_at
                        ? `${new Date(r.approved_at).toLocaleString()}${r.approver ? ` · ${r.approver.name}` : ""}`
                        : "—"}
                    </td>
                    <td style={cell}>
                      {r.transferred_at
                        ? `Transferred ${new Date(r.transferred_at).toLocaleString()}${r.transferrer ? ` · ${r.transferrer.name}` : ""}`
                        : r.completed_at
                          ? `Completed ${new Date(r.completed_at).toLocaleString()}`
                          : r.cancelled_at
                            ? `Cancelled ${new Date(r.cancelled_at).toLocaleString()}`
                            : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {approved && (
        <TransferWorkspaceModal
          open={transferring}
          workOrderId={wo.id}
          current={approved}
          onClose={() => setTransferring(false)}
          onDone={onChanged}
        />
      )}
    </div>
  );
}
