import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { useAuth } from "../../../../auth/AuthContext";
import { FormField, inputStyle } from "../../../../components/FormField";
import { Modal } from "../../../../components/Modal";
import { ErrorState } from "../../../../components/States";
import { StatusBadge } from "../../../../components/StatusBadge";
import type {
  AvailableWorkspaceItem,
  WorkOrderItem,
  WorkspaceReservationItem,
} from "../../../../types";
import {
  currentAssignment,
  defaultWindow,
  formatWindow,
  isApproved,
  toIso,
  toLocalInput,
} from "./workspaceAssignment";

/** Workspaces with a free capacity slot in the window (the backend re-checks on save). */
function useAvailableWorkspaces(
  workOrderId: string,
  start: string,
  end: string,
  excludeWorkspaceId?: string,
) {
  const [rows, setRows] = useState<AvailableWorkspaceItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const valid = !!start && !!end && end > start;

  useEffect(() => {
    if (!valid) return;
    let cancelled = false;
    apiClient
      .get(`/app/work-orders/${workOrderId}/available-workspaces`, {
        params: {
          start_at: toIso(start),
          end_at: toIso(end),
          exclude_workspace_id: excludeWorkspaceId,
        },
      })
      .then((res) => {
        if (cancelled) return;
        setRows(res.data.data);
        setError(null);
      })
      .catch((err) => !cancelled && setError(extractApiError(err).message));
    return () => {
      cancelled = true;
    };
  }, [workOrderId, start, end, excludeWorkspaceId, valid]);

  return {
    rows: valid ? rows : null,
    error: valid ? error : "End must be after start.",
    valid,
  };
}

function WorkspaceSelect({
  rows,
  value,
  onChange,
  label,
}: {
  rows: AvailableWorkspaceItem[] | null;
  value: string;
  onChange: (id: string) => void;
  label: string;
}) {
  return (
    <FormField label={label}>
      <select
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        style={inputStyle}
        disabled={!rows || rows.length === 0}
      >
        <option value="">
          {rows === null
            ? "Loading available workspaces…"
            : rows.length === 0
              ? "No workspace available for this window"
              : "Select workspace…"}
        </option>
        {(rows ?? []).map((w) => (
          <option key={w.id} value={w.id}>
            {w.name} ({w.code}) — occupied {w.occupied} / {w.capacity}
          </option>
        ))}
      </select>
    </FormField>
  );
}

function WindowFields({
  start,
  end,
  onStart,
  onEnd,
}: {
  start: string;
  end: string;
  onStart: (v: string) => void;
  onEnd: (v: string) => void;
}) {
  return (
    <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
      <div style={{ flex: "1 1 200px" }}>
        <FormField label="Work Date — Start">
          <input
            aria-label="Work start"
            type="datetime-local"
            value={start}
            onChange={(e) => onStart(e.target.value)}
            style={inputStyle}
          />
        </FormField>
      </div>
      <div style={{ flex: "1 1 200px" }}>
        <FormField label="Work Date — End">
          <input
            aria-label="Work end"
            type="datetime-local"
            value={end}
            onChange={(e) => onEnd(e.target.value)}
            style={inputStyle}
          />
        </FormField>
      </div>
    </div>
  );
}

/**
 * Transfer to Another Workspace: the approved assignment becomes history (TRANSFERRED) and a new,
 * immediately approved assignment is created for the target (owner decision).
 */
export function TransferWorkspaceForm({
  workOrderId,
  current,
  onDone,
  onCancel,
}: {
  workOrderId: string;
  current: WorkspaceReservationItem;
  onDone: () => void;
  onCancel: () => void;
}) {
  const [start, setStart] = useState(toLocalInput(current.start_at));
  const [end, setEnd] = useState(toLocalInput(current.end_at));
  const [target, setTarget] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const available = useAvailableWorkspaces(
    workOrderId,
    start,
    end,
    current.workspace_id,
  );

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(
        `/app/workspace-reservations/${current.id}/transfer`,
        { workspace_id: target, start_at: toIso(start), end_at: toIso(end) },
      );
      onDone();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div data-transfer-workspace>
      {error && <ErrorState message={error} />}
      <div
        className="card"
        style={{ background: "#f9fafb", marginBottom: 12, fontSize: 13 }}
      >
        <div>
          <strong>Current Assigned Workspace:</strong>{" "}
          {current.workspace?.name ?? current.workspace_id}
          {current.workspace?.code ? ` (${current.workspace.code})` : ""}{" "}
          <StatusBadge status={current.status} />
        </div>
        <div style={{ color: "#6b7280", marginTop: 4 }}>
          <strong>Scheduled:</strong> {formatWindow(current)}
        </div>
      </div>
      <WindowFields start={start} end={end} onStart={setStart} onEnd={setEnd} />
      {available.error && (
        <p style={{ fontSize: 12, color: "#b91c1c" }}>{available.error}</p>
      )}
      <WorkspaceSelect
        label="Target Workspace"
        rows={available.rows}
        value={target}
        onChange={setTarget}
      />
      <p style={{ fontSize: 12, color: "#6b7280" }}>
        The current assignment is kept in the history as Transferred; the new
        one is approved immediately.
      </p>
      <div
        style={{
          display: "flex",
          justifyContent: "flex-end",
          gap: 8,
          marginTop: 12,
        }}
      >
        <button className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
        <button
          className="btn-primary"
          disabled={busy || !target || !available.valid}
          onClick={save}
        >
          Transfer
        </button>
      </div>
    </div>
  );
}

export function TransferWorkspaceModal({
  open,
  workOrderId,
  current,
  onClose,
  onDone,
}: {
  open: boolean;
  workOrderId: string;
  current: WorkspaceReservationItem;
  onClose: () => void;
  onDone: () => void;
}) {
  return (
    <Modal open={open} title="Transfer Workspace" onClose={onClose}>
      {open && (
        <TransferWorkspaceForm
          workOrderId={workOrderId}
          current={current}
          onCancel={onClose}
          onDone={() => {
            onDone();
            onClose();
          }}
        />
      )}
    </Modal>
  );
}

function NewScheduleForm({
  wo,
  onDone,
  onCancel,
}: {
  wo: WorkOrderItem;
  onDone: () => void;
  onCancel: () => void;
}) {
  const initial = defaultWindow();
  const [start, setStart] = useState(initial.start);
  const [end, setEnd] = useState(initial.end);
  const [workspaceId, setWorkspaceId] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const available = useAvailableWorkspaces(wo.id, start, end);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post("/app/workspace-reservations", {
        workspace_id: workspaceId,
        work_order_id: wo.id,
        start_at: toIso(start),
        end_at: toIso(end),
      });
      onDone();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div data-schedule-workspace>
      {error && <ErrorState message={error} />}
      <WindowFields start={start} end={end} onStart={setStart} onEnd={setEnd} />
      {available.error && (
        <p style={{ fontSize: 12, color: "#b91c1c" }}>{available.error}</p>
      )}
      <WorkspaceSelect
        label="Workspace"
        rows={available.rows}
        value={workspaceId}
        onChange={setWorkspaceId}
      />
      <p style={{ fontSize: 12, color: "#6b7280" }}>
        The workspace is requested; it must be approved before work can start.
      </p>
      <div
        style={{
          display: "flex",
          justifyContent: "flex-end",
          gap: 8,
          marginTop: 12,
        }}
      >
        <button className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
        <button
          className="btn-primary"
          disabled={busy || !workspaceId || !available.valid}
          onClick={save}
        >
          Schedule
        </button>
      </div>
    </div>
  );
}

/**
 * Schedule Workspace (Work Order header): without a current assignment it requests one; with a
 * pending request it shows it (approve / cancel); with an approved one it offers the transfer —
 * e.g. moving a QC_PENDING Work Order to a QC bay.
 */
export function ScheduleWorkspaceModal({
  open,
  wo,
  onClose,
  onDone,
}: {
  open: boolean;
  wo: WorkOrderItem;
  onClose: () => void;
  onDone: () => void;
}) {
  const { hasPermission } = useAuth();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const current = currentAssignment(wo);
  const finish = () => {
    onDone();
    onClose();
  };

  async function act(action: "approve" | "cancel") {
    if (!current) return;
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(
        `/app/workspace-reservations/${current.id}/${action}`,
      );
      finish();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open={open} title="Schedule Work Order" onClose={onClose}>
      {open && !current && (
        <>
          <p style={{ fontSize: 13, marginTop: 0 }} data-schedule-state="none">
            {wo.status === "QC_PENDING"
              ? "No current workspace — schedule one for the QC stage."
              : "No workspace scheduled yet."}
          </p>
          <NewScheduleForm wo={wo} onDone={finish} onCancel={onClose} />
        </>
      )}
      {open && current && !isApproved(current) && (
        <div data-schedule-state="pending">
          {error && <ErrorState message={error} />}
          <p style={{ fontSize: 13, marginTop: 0 }}>
            Workspace{" "}
            <strong>{current.workspace?.name ?? current.workspace_id}</strong>{" "}
            is requested for {formatWindow(current)} and awaits approval.
          </p>
          <div style={{ display: "flex", justifyContent: "flex-end", gap: 8 }}>
            {hasPermission("workspace.reserve") && (
              <button
                className="btn-secondary"
                disabled={busy}
                onClick={() => act("cancel")}
              >
                Cancel Request
              </button>
            )}
            {hasPermission("workspace.approve") && (
              <button
                className="btn-primary"
                disabled={busy}
                onClick={() => act("approve")}
              >
                Approve
              </button>
            )}
          </div>
        </div>
      )}
      {open && current && isApproved(current) && (
        <div data-schedule-state="approved">
          <p style={{ fontSize: 13, marginTop: 0 }}>
            {wo.status === "QC_PENDING"
              ? "The Work Order has an approved workspace. Transfer it to another workspace for the QC stage if needed."
              : "The Work Order already has an approved workspace. Transfer it to reschedule."}
          </p>
          {hasPermission("workspace.approve") ? (
            <TransferWorkspaceForm
              workOrderId={wo.id}
              current={current}
              onDone={finish}
              onCancel={onClose}
            />
          ) : (
            <p style={{ fontSize: 12, color: "#b45309" }}>
              Transferring a workspace requires the workspace approval
              permission.
            </p>
          )}
        </div>
      )}
    </Modal>
  );
}
