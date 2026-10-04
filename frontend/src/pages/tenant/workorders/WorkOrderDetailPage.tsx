import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ImageUploadField } from '../../../components/ImageUploadField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { formatQty } from '../../../utils/quantity';
import { AssessmentSection, InspectionSourceSection } from '../maintenance/MaintenanceRequestDetailPage';
import type {
  AuditLogEntry,
  HistoryEventItem,
  MaintenanceRequestItem,
  PartnerItem,
  PartRequestItem,
  QcInspectionItem,
  WorkerItem,
  WorkOrderFindingItem,
  WorkOrderItem,
  WorkOrderPlannedPartItem,
  WorkspaceItem,
  WorkspaceReservationItem,
} from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney, sumMoney } from '../../../utils/money';
import { formatDate, formatDateTime } from '../../../utils/date';
import { DocumentViewer } from '../../../components/DocumentViewer';
import { useAuthorizedPreviews } from '../../../hooks/useAuthorizedPreviews';
import { SearchableSelect, type SearchableOption } from '../../../components/SearchableSelect';
import { WorkOrderTireOperationTab } from '../tires/operations/WorkOrderTireOperationTab';
import type { TireOperationDetail } from '../tires/operations/tireOperationTypes';
import { lineName } from '../../../utils/stockCondition';

const INTERNAL_TABS = [
  'Overview', 'Complaint', 'Diagnosis', 'Jobs', 'Mechanic',
  'Planned Parts', 'Issuance & Return', 'Workspace', 'QC', 'Road Test', 'External Services', 'Documents', 'History', 'Audit',
] as const;
// Consolidated External Workshop business rules: an External-mode Work Order uses only Findings
// as its scope — every internal-workshop tab (Diagnosis, Jobs, Mechanic, Planned Parts, Issuance
// & Return, Workspace, QC, Road Test) is hidden, not just its actions. "External Services"
// (towing / 3rd-party memos) is hidden too, in every status: the External Workshop's own
// documents (acknowledged WAL, invoice, payment proof) are under Documents instead. The
// decision is by execution mode, never by status; internal Work Orders keep the tab.
const EXTERNAL_MODE_TABS = ['Overview', 'Findings', 'Documents', 'History', 'Audit'] as const;
type Tab = (typeof INTERNAL_TABS)[number] | 'Findings' | 'Tire Operations';

// Mirrors backend WorkOrderExecutionService — Findings/Diagnosis/Corrective Actions are
// Draft-only (Add + Delete/Remove hidden afterward); Jobs/Mechanic/Planned Parts stay
// addable through the whole active-planning window.
const FINDING_SCOPE_STATUSES = ['DRAFT'];
const PLANNING_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'];
// Doc: "Issuance & Return" (formerly "Request Parts", the old Planned Parts tab) only appears from IN_PROGRESS
// onward — before that, only the new budgeting-only "Planned Parts" tab is shown.
const REQUEST_PARTS_VISIBLE_STATUSES = ['IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'QC_PENDING', 'REWORK', 'COMPLETED', 'CLOSED', 'REJECTED', 'CANCELLED'];
// Reserve/Issue/Consume/Return only apply once real execution has started — Draft's Planned
// Parts tab explicitly must not show these buttons at all (doc: "jangan tampilkan tombol
// Reserve, tombol Issue, Consume atau Return" while Draft). Mirrors backend's unmodified,
// narrower EXECUTABLE_STATUSES used by WorkOrderPartService.
const PART_ACTION_STATUSES = ['ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'];

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'work_order.submit', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  SUBMITTED: [
    { action: 'approve', label: 'Approve', permission: 'work_order.approve', primary: true },
    { action: 'reject', label: 'Reject', permission: 'work_order.reject' },
    { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' },
  ],
  APPROVED: [{ action: 'assign', label: 'Assign', permission: 'work_order.assign', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  ASSIGNED: [{ action: 'schedule', label: 'Schedule', permission: 'work_order.schedule', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  SCHEDULED: [
    { action: 'start', label: 'Start', permission: 'work_order.start', primary: true },
    { action: 'external', label: 'Send External', permission: 'work_order.pause' },
    { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' },
  ],
  IN_PROGRESS: [
    { action: 'hold', label: 'Hold', permission: 'work_order.pause' },
    { action: 'wait-for-part', label: 'Wait for Part', permission: 'work_order.pause' },
    { action: 'external', label: 'Send External', permission: 'work_order.pause' },
    { action: 'submit-to-qc', label: 'Submit to QC', permission: 'work_order.complete', primary: true },
    { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' },
  ],
  ON_HOLD: [{ action: 'resume', label: 'Resume', permission: 'work_order.pause', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  WAITING_PART: [{ action: 'resume', label: 'Resume', permission: 'work_order.pause', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  // EXTERNAL has no entry here: its header actions (Print/Revise/Cancel) are rendered by the
  // isExternalMode branch below, which forces `actions` to [] — see "Perbaikan Tenant Portal -
  // Work Order Status External dan Workshop Invoice" Section 3.
  REWORK: [{ action: 'resume', label: 'Resume to In Progress', permission: 'work_order.pause', primary: true }],
  /** G-03: previously nothing in the UI could ever invoke this transition — a WO reaching QC_PENDING had no path to COMPLETED at all. */
  QC_PENDING: [{ action: 'complete', label: 'Complete', permission: 'work_order.complete', primary: true }],
  COMPLETED: [{ action: 'close', label: 'Close', permission: 'work_order.close', primary: true }],
};

export function WorkOrderDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [wo, setWo] = useState<WorkOrderItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState<Tab>('Overview');
  const [showSchedule, setShowSchedule] = useState(false);
  const [showComplete, setShowComplete] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [showReviseConfirm, setShowReviseConfirm] = useState(false);
  const [showCancelExternal, setShowCancelExternal] = useState(false);
  const [cancelReason, setCancelReason] = useState('');

  function load() {
    apiClient
      .get(`/app/work-orders/${id}`)
      .then((res) => setWo(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  // A Work Order created by a Tire Operation shows it in its own read-only tab.
  const [tireOperation, setTireOperation] = useState<TireOperationDetail | null>(null);
  useEffect(() => {
    apiClient
      .get(`/app/work-orders/${id}/tire-operation`)
      .then((res) => setTireOperation(res.data.data))
      .catch(() => setTireOperation(null));
  }, [id, wo?.status]);

  useBreadcrumbLabel(wo?.id, wo?.wo_number);

  /** G-04: a print PDF endpoint has existed on the backend since Phase 1, with no frontend caller anywhere. */
  async function printWorkOrder() {
    setPrinting(true);
    setError(null);
    try {
      const res = await apiClient.get(`/app/work-orders/${id}/print`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setPrinting(false);
    }
  }

  async function act(action: string) {
    if (action === 'schedule') {
      setShowSchedule(true);
      return;
    }
    if (action === 'complete') {
      setShowComplete(true);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${id}/${action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function markExternalWorkshop() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${id}/execution-mode/external`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function finalizeExternal() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${id}/external`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function reviseExternal() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${id}/external/revise`);
      setShowReviseConfirm(false);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function cancelExternal() {
    if (!cancelReason.trim()) {
      setError('A cancellation reason is required.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${id}/external/cancel`, { reason: cancelReason });
      setShowCancelExternal(false);
      setCancelReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !wo) return <ErrorState message={error} />;
  if (!wo) return <LoadingState />;

  const isExternalMode = wo.execution_mode === 'EXTERNAL';
  const baseTabs: readonly Tab[] = (isExternalMode ? EXTERNAL_MODE_TABS : INTERNAL_TABS).filter(
    (t) => t !== 'Issuance & Return' || REQUEST_PARTS_VISIBLE_STATUSES.includes(wo.status),
  );
  const visibleTabs: readonly Tab[] = tireOperation ? [baseTabs[0], 'Tire Operations', ...baseTabs.slice(1)] : baseTabs;
  const actions = isExternalMode ? [] : (LIFECYCLE[wo.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <BackButton fallbackTo="/app/work-orders" label="← Back to Work Order" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {wo.wo_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({wo.vehicle?.registration_number})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusBadge status={wo.status} />
          {isExternalMode && (
            <span style={{ fontSize: 12, color: '#6b7280' }}>
              External · Rev {wo.external_finalized_revision || '—'}
            </span>
          )}
          {hasPermission('work_order.view') && (
            <button className="btn-secondary" disabled={printing} onClick={printWorkOrder}>
              {printing ? 'Loading…' : 'Print'}
            </button>
          )}
          {!isExternalMode && wo.status === 'DRAFT' && hasPermission('work_order.prepare_external') && (
            <button className="btn-secondary" disabled={busy} onClick={markExternalWorkshop}>
              Select External Workshop
            </button>
          )}
          {isExternalMode && wo.status === 'DRAFT' && hasPermission('work_order.finalize_external') && (
            <button
              className="btn-primary"
              disabled={busy || (wo.findings?.length ?? 0) < 1}
              title={(wo.findings?.length ?? 0) < 1 ? 'At least one Finding is required before finalizing.' : undefined}
              onClick={finalizeExternal}
            >
              External Workshop
            </button>
          )}
          {isExternalMode && wo.status === 'DRAFT' && hasPermission('work_order.cancel') && (
            <button className="btn-secondary" disabled={busy} onClick={() => act('cancel')}>
              Cancel
            </button>
          )}
          {isExternalMode && wo.status === 'EXTERNAL' && hasPermission('work_order.revise_external') && (
            <button className="btn-secondary" disabled={busy} onClick={() => setShowReviseConfirm(true)}>
              Revise
            </button>
          )}
          {isExternalMode && wo.status === 'EXTERNAL' && hasPermission('work_order.cancel_external') && (
            <button className="btn-secondary" disabled={busy} onClick={() => setShowCancelExternal(true)}>
              Cancel
            </button>
          )}
          {!isExternalMode &&
            actions.map((a) => (
              <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy} onClick={() => act(a.action)}>
                {a.label}
              </button>
            ))}
        </div>
      </div>

      {isExternalMode && wo.status === 'DRAFT' && (wo.findings?.length ?? 0) < 1 && (
        <p style={{ fontSize: 13, color: '#b45309', marginTop: -8, marginBottom: 16 }}>
          Add at least one Finding on the Findings tab before this Work Order can be finalized as External.
        </p>
      )}

      {wo.status === 'CANCELLED' && wo.cancellation_reason && (
        <p style={{ fontSize: 13, color: '#6b7280', marginTop: -8, marginBottom: 16 }}>
          <strong>Cancellation reason:</strong> {wo.cancellation_reason}
        </p>
      )}

      <Modal open={showReviseConfirm} title="Revise External Work Order" onClose={() => setShowReviseConfirm(false)}>
        <p style={{ fontSize: 13 }}>
          This returns the Work Order to Draft so its Findings can be edited. The current finalized revision
          (Rev {wo.external_finalized_revision}) is preserved in history. Continue?
        </p>
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setShowReviseConfirm(false)} disabled={busy}>
            Cancel
          </button>
          <button className="btn-primary" onClick={reviseExternal} disabled={busy}>
            Revise
          </button>
        </div>
      </Modal>

      <Modal open={showCancelExternal} title="Cancel External Work Order" onClose={() => setShowCancelExternal(false)}>
        <p style={{ fontSize: 13 }}>This cannot be undone. A cancellation reason is required.</p>
        <textarea
          value={cancelReason}
          onChange={(e) => setCancelReason(e.target.value)}
          placeholder="Cancellation reason (required)"
          style={{ width: '100%', minHeight: 70, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
        />
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setShowCancelExternal(false)} disabled={busy}>
            Back
          </button>
          <button className="btn-primary" onClick={cancelExternal} disabled={busy}>
            Confirm Cancel
          </button>
        </div>
      </Modal>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'flex', gap: 4, marginBottom: 16, borderBottom: '1px solid #e5e7eb', flexWrap: 'wrap' }}>
        {visibleTabs.map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            style={{
              padding: '8px 14px',
              border: 'none',
              background: 'none',
              borderBottom: tab === t ? '2px solid #1d4ed8' : '2px solid transparent',
              color: tab === t ? '#1d4ed8' : '#6b7280',
              fontWeight: tab === t ? 600 : 400,
              cursor: 'pointer',
              fontSize: 13,
            }}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === 'Overview' && <OverviewTab wo={wo} onChanged={load} />}
      {tab === 'Tire Operations' && tireOperation && <WorkOrderTireOperationTab operation={tireOperation} />}
      {tab === 'Complaint' && <ComplaintTab wo={wo} onChanged={load} />}
      {tab === 'Diagnosis' && <DiagnosisTab wo={wo} onChanged={load} />}
      {tab === 'Jobs' && <JobsTab wo={wo} onChanged={load} />}
      {tab === 'Mechanic' && <MechanicTab wo={wo} onChanged={load} />}
      {tab === 'Planned Parts' && <PlannedPartsEstimatesTab wo={wo} onChanged={load} />}
      {tab === 'Issuance & Return' && <IssuanceReturnTab wo={wo} onChanged={load} />}
      {tab === 'Workspace' && <WorkspaceTab wo={wo} onChanged={load} />}
      {tab === 'QC' && <QcTab wo={wo} onChanged={load} />}
      {tab === 'Road Test' && <RoadTestTab wo={wo} onChanged={load} />}
      {tab === 'Findings' && <ExternalFindingsTab wo={wo} onChanged={load} />}
      {tab === 'External Services' && <ExternalServicesTab wo={wo} onChanged={load} />}
      {tab === 'Documents' && <DocumentsTab workOrderId={wo.id} />}
      {tab === 'History' && <HistoryTab vehicleId={wo.vehicle_id} />}
      {tab === 'Audit' && <AuditTab workOrderId={wo.id} />}

      <ScheduleModal open={showSchedule} wo={wo} onClose={() => setShowSchedule(false)} onScheduled={load} />
      <CompleteModal open={showComplete} wo={wo} onClose={() => setShowComplete(false)} onCompleted={load} />
    </div>
  );
}

/** G-03: previously completing a Work Order had no way to record what was actually done. */
function CompleteModal({ open, wo, onClose, onCompleted }: { open: boolean; wo: WorkOrderItem; onClose: () => void; onCompleted: () => void }) {
  const [resultSummary, setResultSummary] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/complete`, {
        result_summary: resultSummary || undefined,
      });
      onCompleted();
      onClose();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="Complete Work Order" onClose={onClose}>
      {error && <ErrorState message={error} />}
      <FormField label="Result Summary (optional)">
        <textarea value={resultSummary} onChange={(e) => setResultSummary(e.target.value)} style={{ ...inputStyle, minHeight: 80 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          Complete
        </button>
      </div>
    </Modal>
  );
}

function ScheduleModal({ open, wo, onClose, onScheduled }: { open: boolean; wo: WorkOrderItem; onClose: () => void; onScheduled: () => void }) {
  const [workspaces, setWorkspaces] = useState<WorkspaceItem[]>([]);
  const [workspaceId, setWorkspaceId] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/workspaces', { params: { workshop_id: wo.workshop_id, status: 'AVAILABLE', per_page: 100 } }).then((res) => setWorkspaces(res.data.data));
  }, [open, wo.workshop_id]);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/schedule`, {
        workspace_id: workspaceId || undefined,
        target_start_at: start || undefined,
        target_completion_at: end || undefined,
      });
      onScheduled();
      onClose();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="Schedule Work Order" onClose={onClose}>
      {error && <ErrorState message={error} />}
      <FormField label="Workspace (optional)">
        <select value={workspaceId} onChange={(e) => setWorkspaceId(e.target.value)} style={inputStyle}>
          <option value="">None</option>
          {workspaces.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name} ({w.code})
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Target Start">
        <input type="datetime-local" value={start} onChange={(e) => setStart(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Target Completion">
        <input type="datetime-local" value={end} onChange={(e) => setEnd(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          Schedule
        </button>
      </div>
    </Modal>
  );
}


/**
 * "Improvement OptiFleet - Maintenance Request dan Work Order": the Overview
 * tab must show the originating Maintenance Request's Assessment (source
 * USER) or Inspection checklist + Recorded Findings (source INSPECTION) —
 * never the Assessment table unconditionally, since an Inspection-sourced
 * request never has an Assessment row to show.
 */
function MaintenanceRequestSourceSection({ maintenanceRequestId }: { maintenanceRequestId: string }) {
  const [request, setRequest] = useState<MaintenanceRequestItem | null>(null);

  useEffect(() => {
    apiClient.get(`/app/maintenance-requests/${maintenanceRequestId}`).then((res) => setRequest(res.data.data)).catch(() => setRequest(null));
  }, [maintenanceRequestId]);

  if (!request) return null;
  if (request.source_type === 'INSPECTION' && request.source_inspection_id) {
    return <InspectionSourceSection inspectionId={request.source_inspection_id} />;
  }
  return <AssessmentSection maintenanceRequestId={maintenanceRequestId} editable={false} />;
}

function OverviewTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [editing, setEditing] = useState(false);
  const canEdit = wo.status === 'DRAFT' && hasPermission('work_order.update');
  const laborCost = wo.estimated_labor_cost_computed ?? null;
  const partsCost = wo.estimated_parts_cost_computed ?? null;
  const totalCost =
    laborCost !== null || partsCost !== null ? sumMoney(laborCost, partsCost) : null;

  const rows: [string, string][] = [
    ['Vehicle', wo.vehicle?.registration_number ?? wo.vehicle_id],
    ['Branch', wo.branch?.name ?? '—'],
    ['Workshop', wo.workshop?.name ?? '—'],
    ['Maintenance Type', wo.maintenance_type],
    ['Priority', wo.priority],
    ['Current Odometer', wo.current_odometer ?? '—'],
    ['Est. Number of Mechanic', wo.estimated_number_of_mechanics != null ? String(wo.estimated_number_of_mechanics) : '—'],
    ['Est. Total Hours', wo.estimated_total_hours ?? '—'],
    ['Estimated Labor Cost', formatMoney(laborCost)],
    ['Estimated Parts Cost', formatMoney(partsCost)],
    ['Estimated Total Cost', formatMoney(totalCost)],
    ['Target Start', wo.target_start_at ? new Date(wo.target_start_at).toLocaleString() : '—'],
    ['Target Completion', wo.target_completion_at ? new Date(wo.target_completion_at).toLocaleString() : '—'],
    ['Started At', wo.started_at ? new Date(wo.started_at).toLocaleString() : '—'],
    ['Completed At', wo.completed_at ? new Date(wo.completed_at).toLocaleString() : '—'],
    ['Closed At', wo.closed_at ? new Date(wo.closed_at).toLocaleString() : '—'],
    ['Result Summary', wo.result_summary ?? '—'],
  ];

  return (
    <div>
      <div className="card">
        {canEdit && (
          <div style={{ textAlign: 'right', marginBottom: 8 }}>
            <button className="btn-secondary" onClick={() => setEditing(true)}>
              Edit Details
            </button>
          </div>
        )}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          {rows.map(([label, value]) => (
            <div key={label}>
              <div style={{ fontSize: 12, color: '#9ca3af' }}>{label}</div>
              <div style={{ fontSize: 14 }}>{value}</div>
            </div>
          ))}
        </div>
      </div>
      {/* Complaint only belongs here for a Work Order created directly by a user through the
          Work Order feature — a Maintenance-Request-converted WO shows its source's Assessment
          or Inspection data instead, never a Complaint block (doc: "Sembunyikan Section
          Complaint yang seharusnya hanya muncul jika Work Order dibuat oleh user"). */}
      {!wo.maintenance_request_id && (
        <div className="card" style={{ marginTop: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Complaint</h3>
          <p style={{ fontSize: 13 }}>{wo.complaint || '—'}</p>
        </div>
      )}
      {wo.maintenance_request_id && (
        <div style={{ marginTop: 16 }}>
          <MaintenanceRequestSourceSection maintenanceRequestId={wo.maintenance_request_id} />
        </div>
      )}
      {editing && (
        <EditWorkOrderModal
          wo={wo}
          onClose={() => setEditing(false)}
          onSaved={() => {
            setEditing(false);
            onChanged();
          }}
        />
      )}
    </div>
  );
}

/** DRAFT-only header edit (`work_order.update`): priority, and the complaint of a directly created WO. */
function EditWorkOrderModal({ wo, onClose, onSaved }: { wo: WorkOrderItem; onClose: () => void; onSaved: () => void }) {
  const [priority, setPriority] = useState<string>(wo.priority ?? 'MEDIUM');
  const [complaint, setComplaint] = useState(wo.complaint ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.put(`/app/work-orders/${wo.id}`, wo.maintenance_request_id ? { priority } : { priority, complaint: complaint || null });
      onSaved();
    } catch (err) {
      const e = extractApiError(err);
      setError(e.errors ? Object.values(e.errors).flat()[0] ?? e.message : e.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open title={`Edit ${wo.wo_number}`} onClose={onClose}>
      {error && <ErrorState message={error} />}
      <FormField label="Priority" required>
        <select value={priority} onChange={(e) => setPriority(e.target.value)} style={inputStyle}>
          {['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
      </FormField>
      {!wo.maintenance_request_id && (
        <FormField label="Complaint">
          <textarea value={complaint} onChange={(e) => setComplaint(e.target.value)} style={{ ...inputStyle, minHeight: 80 }} />
        </FormField>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
        <button className="btn-secondary" onClick={onClose} disabled={busy}>
          Cancel
        </button>
        <button className="btn-primary" onClick={save} disabled={busy}>
          {busy ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}

function ComplaintTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [severity, setSeverity] = useState('MEDIUM');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  // Findings are a Draft-only scoping exercise — Add and Delete/Remove are only ever
  // shown while status=DRAFT, matching WorkOrderExecutionService::assertFindingScopeEditable.
  const editable = FINDING_SCOPE_STATUSES.includes(wo.status) && hasPermission('diagnosis.manage');

  async function addFinding() {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/findings`, { severity, description });
      setDescription('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function deleteFinding(findingId: string) {
    setBusy(true);
    try {
      await apiClient.delete(`/app/work-orders/${wo.id}/findings/${findingId}`);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  /** G-04: previously a Work Order Finding had no API to ever mark it resolved. */
  async function resolveFinding(findingId: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/findings/${findingId}/resolve`);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Findings</h3>
        {(wo.findings ?? []).length === 0 && <EmptyState label="No findings recorded." />}
        {(wo.findings ?? []).map((f) => (
          <div key={f.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', display: 'flex', gap: 10, alignItems: 'center', fontSize: 13 }}>
            <StatusBadge status={f.severity} />
            <StatusBadge status={f.status} />
            <span style={{ flex: 1 }}>{f.description}</span>
            {f.status === 'OPEN' && hasPermission('diagnosis.manage') && (
              <button className="btn-secondary" disabled={busy} onClick={() => resolveFinding(f.id)}>
                Resolve
              </button>
            )}
            {editable && (
              <button className="btn-secondary" disabled={busy} onClick={() => deleteFinding(f.id)}>
                Delete
              </button>
            )}
          </div>
        ))}
        {editable && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
            <select value={severity} onChange={(e) => setSeverity(e.target.value)} style={{ ...inputStyle, width: 140 }}>
              {['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
            <input placeholder="Finding description" value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
            <button className="btn-secondary" disabled={busy || !description} onClick={addFinding}>
              Add Finding
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Consolidated External Workshop business rules: Findings are the ONLY
 * scope-of-work data for an External Work Order. Add/edit/delete are only
 * permitted while status=DRAFT (findings become an immutable finalized
 * snapshot once EXTERNAL) — enforced server-side regardless of what this
 * component renders.
 */
function ExternalFindingsTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [severity, setSeverity] = useState('MEDIUM');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editDescription, setEditDescription] = useState('');
  const [editSeverity, setEditSeverity] = useState('MEDIUM');

  const editable = wo.status === 'DRAFT' && hasPermission('work_order.prepare_external');

  async function addFinding() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/external-findings`, { severity, description });
      setDescription('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  function startEdit(f: WorkOrderFindingItem) {
    setEditingId(f.id);
    setEditDescription(f.description);
    setEditSeverity(f.severity);
  }

  async function saveEdit(findingId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.put(`/app/work-orders/${wo.id}/external-findings/${findingId}`, {
        description: editDescription,
        severity: editSeverity,
      });
      setEditingId(null);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function deleteFinding(findingId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.delete(`/app/work-orders/${wo.id}/external-findings/${findingId}`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Findings (External Scope of Work)</h3>
      {error && <ErrorState message={error} />}
      {(wo.findings ?? []).length === 0 && <EmptyState label="No findings recorded." />}
      {(wo.findings ?? []).map((f) => (
        <div key={f.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          {editingId === f.id ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <select value={editSeverity} onChange={(e) => setEditSeverity(e.target.value)} style={{ ...inputStyle, width: 140 }}>
                {['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </select>
              <input value={editDescription} onChange={(e) => setEditDescription(e.target.value)} style={inputStyle} />
              <button className="btn-primary" disabled={busy} onClick={() => saveEdit(f.id)}>
                Save
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => setEditingId(null)}>
                Cancel
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
              <StatusBadge status={f.severity} />
              <span style={{ flex: 1 }}>{f.description}</span>
              {editable && (
                <>
                  <button className="btn-secondary" disabled={busy} onClick={() => startEdit(f)}>
                    Edit
                  </button>
                  <button className="btn-secondary" disabled={busy} onClick={() => deleteFinding(f.id)}>
                    Delete
                  </button>
                </>
              )}
            </div>
          )}
        </div>
      ))}
      {editable ? (
        <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
          <select value={severity} onChange={(e) => setSeverity(e.target.value)} style={{ ...inputStyle, width: 140 }}>
            {['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
          <input placeholder="Finding description" value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          <button className="btn-secondary" disabled={busy || !description} onClick={addFinding}>
            Add Finding
          </button>
        </div>
      ) : (
        wo.status !== 'DRAFT' && <p style={{ fontSize: 12, color: '#9ca3af', marginTop: 12 }}>Findings are read-only once finalized.</p>
      )}
    </div>
  );
}

function DiagnosisTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [findingId, setFindingId] = useState('');
  const [rootCause, setRootCause] = useState('');
  const [notes, setNotes] = useState('');
  const [diagnosisId, setDiagnosisId] = useState('');
  const [actionDescription, setActionDescription] = useState('');
  const [busy, setBusy] = useState(false);
  // Diagnosis/Corrective Actions share Findings' Draft-only scope (see ComplaintTab).
  const canManage = FINDING_SCOPE_STATUSES.includes(wo.status) && hasPermission('diagnosis.manage');

  async function addDiagnosis() {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/diagnoses`, { work_order_finding_id: findingId || undefined, root_cause: rootCause, notes: notes || undefined });
      setRootCause('');
      setNotes('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function deleteDiagnosis(id: string) {
    setBusy(true);
    try {
      await apiClient.delete(`/app/work-orders/${wo.id}/diagnoses/${id}`);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function addCorrectiveAction() {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/corrective-actions`, { work_order_diagnosis_id: diagnosisId || undefined, action_description: actionDescription });
      setActionDescription('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function deleteCorrectiveAction(actionId: string) {
    setBusy(true);
    try {
      await apiClient.delete(`/app/work-orders/${wo.id}/corrective-actions/${actionId}`);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Diagnoses</h3>
        {(wo.diagnoses ?? []).length === 0 && <EmptyState label="No diagnoses recorded." />}
        {(wo.diagnoses ?? []).map((d) => (
          <div key={d.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div>
                <strong>Root Cause:</strong> {d.root_cause}
              </div>
              {d.notes && <div style={{ color: '#6b7280' }}>{d.notes}</div>}
            </div>
            {canManage && (
              <button className="btn-secondary" disabled={busy} onClick={() => deleteDiagnosis(d.id)}>
                Delete
              </button>
            )}
          </div>
        ))}
        {canManage && (
          <div style={{ marginTop: 12 }}>
            <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
              <select value={findingId} onChange={(e) => setFindingId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">Link to finding (optional)</option>
                {(wo.findings ?? []).map((f) => (
                  <option key={f.id} value={f.id}>
                    {f.description.slice(0, 40)}
                  </option>
                ))}
              </select>
              <input placeholder="Root cause" value={rootCause} onChange={(e) => setRootCause(e.target.value)} style={inputStyle} />
            </div>
            <textarea placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, minHeight: 50, marginBottom: 8 }} />
            <button className="btn-secondary" disabled={busy || !rootCause} onClick={addDiagnosis}>
              Add Diagnosis
            </button>
          </div>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Corrective Actions</h3>
        {(wo.corrective_actions ?? []).length === 0 && <EmptyState label="No corrective actions recorded." />}
        {(wo.corrective_actions ?? []).map((c) => (
          <div key={c.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 10, alignItems: 'center' }}>
            <StatusBadge status={c.status} />
            <span style={{ flex: 1 }}>{c.action_description}</span>
            {canManage && (
              <button className="btn-secondary" disabled={busy} onClick={() => deleteCorrectiveAction(c.id)}>
                Delete
              </button>
            )}
          </div>
        ))}
        {canManage && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
            <select value={diagnosisId} onChange={(e) => setDiagnosisId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
              <option value="">Link to diagnosis (optional)</option>
              {(wo.diagnoses ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.root_cause.slice(0, 40)}
                </option>
              ))}
            </select>
            <input placeholder="Action description" value={actionDescription} onChange={(e) => setActionDescription(e.target.value)} style={inputStyle} />
            <button className="btn-secondary" disabled={busy || !actionDescription} onClick={addCorrectiveAction}>
              Add Action
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function JobsTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [description, setDescription] = useState('');
  const [serviceItem, setServiceItem] = useState('');
  const [estimatedHours, setEstimatedHours] = useState('');
  const [busy, setBusy] = useState(false);
  const canManage = hasPermission('maintenance_job.manage');
  // Adding a Job is Draft-through-active-execution only; changing an existing Job's own
  // status is a separate state machine the backend never WO-status-gates.
  const canAdd = PLANNING_STATUSES.includes(wo.status) && canManage;

  async function addJob() {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/jobs`, {
        description, service_item: serviceItem || undefined, estimated_hours: estimatedHours || undefined,
      });
      setDescription('');
      setServiceItem('');
      setEstimatedHours('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function updateStatus(jobId: string, status: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/jobs/${jobId}/status`, { status });
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Maintenance Jobs</h3>
      <div style={{ fontSize: 13, color: '#374151', marginBottom: 8 }}>
        Est. Total Hours: <strong>{wo.estimated_total_hours ?? '—'}</strong>
      </div>
      {(wo.jobs ?? []).length === 0 && <EmptyState label="No jobs added." />}
      {(wo.jobs ?? []).map((j) => (
        <div key={j.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <strong>{j.service_item ?? 'Job'}</strong> — {j.description}
              <div style={{ color: '#6b7280' }}>
                Est. {j.estimated_hours ?? '—'}h / Actual {j.actual_hours ?? '—'}h
                {j.estimated_labor_cost_computed && ` · Est. Labor Cost: ${formatMoney(j.estimated_labor_cost_computed)}`}
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <StatusBadge status={j.status} />
              {canManage && (
                <select
                  defaultValue=""
                  onChange={(e) => {
                    if (e.target.value) updateStatus(j.id, e.target.value);
                    e.target.value = '';
                  }}
                  style={{ ...inputStyle, width: 140 }}
                  disabled={busy}
                >
                  <option value="">Change status…</option>
                  {['ASSIGNED', 'IN_PROGRESS', 'ON_HOLD', 'COMPLETED', 'CANCELLED'].map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              )}
            </div>
          </div>
        </div>
      ))}
      {canAdd && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <input placeholder="Service item" value={serviceItem} onChange={(e) => setServiceItem(e.target.value)} style={{ ...inputStyle, width: 160 }} />
          <input placeholder="Job description" value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          <NumericInput placeholder="Est. hours" value={estimatedHours} onChange={(e) => setEstimatedHours(e.target.value)} style={{ ...inputStyle, width: 100 }} />
          <button className="btn-secondary" disabled={busy || !description} onClick={addJob}>
            Add Job
          </button>
        </div>
      )}
    </div>
  );
}

function MechanicTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [workers, setWorkers] = useState<WorkerItem[]>([]);
  const [workerId, setWorkerId] = useState('');
  const [role, setRole] = useState('PRIMARY');
  const [jobId, setJobId] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [runningLog, setRunningLog] = useState<Record<string, string>>({});
  const canAssign = hasPermission('worker.assign');
  // Assigning a new mechanic is Draft-through-active-execution only (mirrors Jobs/Planned
  // Parts); unassigning an existing one stays permission-only, unchanged.
  const canAdd = PLANNING_STATUSES.includes(wo.status) && canAssign;
  const canManageJobs = hasPermission('maintenance_job.manage');

  useEffect(() => {
    apiClient.get('/app/workers', { params: { workshop_id: wo.workshop_id, status: 'ACTIVE', per_page: 100 } }).then((res) => setWorkers(res.data.data));
  }, [wo.workshop_id]);

  async function assign() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/mechanics`, { worker_id: workerId, role, maintenance_job_id: jobId || undefined });
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function unassign(assignmentId: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/mechanics/${assignmentId}/unassign`);
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function startLabor(jobId: string, wId: string) {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post(`/app/work-orders/${wo.id}/jobs/${jobId}/labor/start`, { worker_id: wId });
      setRunningLog((prev) => ({ ...prev, [jobId]: res.data.data.id }));
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function laborAction(jobId: string, logId: string, action: 'pause' | 'resume' | 'finish') {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/jobs/${jobId}/labor/${logId}/${action}`);
      if (action === 'finish') setRunningLog((prev) => ({ ...prev, [jobId]: '' }));
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      {error && <ErrorState message={error} />}
      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Mechanic Assignments</h3>
        {(wo.mechanic_assignments ?? []).length === 0 && <EmptyState label="No mechanics assigned." />}
        {(wo.mechanic_assignments ?? []).map((a) => (
          <div key={a.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', justifyContent: 'space-between' }}>
            <span>
              {a.worker?.name ?? a.worker_id} — {a.role} {a.maintenance_job_id ? '(job-specific)' : '(WO-wide)'}
              {a.hourly_rate_snapshot && ` · Rate at assignment: ${a.hourly_rate_snapshot}/hr`}
            </span>
            {canAssign && !a.unassigned_at && (
              <button className="btn-secondary" disabled={busy} onClick={() => unassign(a.id)}>
                Unassign
              </button>
            )}
          </div>
        ))}
        {canAdd && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
            <select value={workerId} onChange={(e) => setWorkerId(e.target.value)} style={{ ...inputStyle, width: 180 }}>
              <option value="">Select worker…</option>
              {workers.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name} ({w.worker_type})
                </option>
              ))}
            </select>
            <select value={role} onChange={(e) => setRole(e.target.value)} style={{ ...inputStyle, width: 120 }}>
              <option value="PRIMARY">PRIMARY</option>
              <option value="ASSISTANT">ASSISTANT</option>
            </select>
            <select value={jobId} onChange={(e) => setJobId(e.target.value)} style={{ ...inputStyle, width: 180 }}>
              <option value="">WO-wide (no job)</option>
              {(wo.jobs ?? []).map((j) => (
                <option key={j.id} value={j.id}>
                  {j.service_item ?? j.description.slice(0, 30)}
                </option>
              ))}
            </select>
            <button className="btn-secondary" disabled={busy || !workerId} onClick={assign}>
              Assign
            </button>
          </div>
        )}
        {/* Both auto-computed and read-only per the doc: Number of Mechanics reflects however
            many are currently assigned; Estimated Labor Cost = Est. Total Hours (Jobs tab) x
            the sum of every assigned mechanic's hourly rate — never manually editable. */}
        <div style={{ display: 'flex', gap: 24, marginTop: 16, paddingTop: 12, borderTop: '1px solid #e5e7eb' }}>
          <FormField label="Number of Mechanics">
            <input value={wo.estimated_number_of_mechanics ?? 0} readOnly style={{ ...inputStyle, width: 100, background: '#f9fafb' }} />
          </FormField>
          <FormField label="Estimated Labor Cost">
            <input value={wo.estimated_labor_cost_computed ?? '—'} readOnly style={{ ...inputStyle, width: 160, background: '#f9fafb' }} />
          </FormField>
        </div>
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Labor Timer</h3>
        {(wo.jobs ?? []).length === 0 && <EmptyState label="No jobs to time." />}
        {(wo.jobs ?? []).map((j) => {
          const activeLog = (j.labor_logs ?? []).find((l) => l.status !== 'FINISHED') ?? (runningLog[j.id] ? { id: runningLog[j.id], status: 'RUNNING' } : null);
          return (
            <div key={j.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span>{j.service_item ?? j.description.slice(0, 40)}</span>
              {canManageJobs && (
                <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                  {!activeLog && (
                    <select
                      defaultValue=""
                      onChange={(e) => {
                        if (e.target.value) startLabor(j.id, e.target.value);
                        e.target.value = '';
                      }}
                      style={{ ...inputStyle, width: 160 }}
                      disabled={busy}
                    >
                      <option value="">Start labor for…</option>
                      {workers.map((w) => (
                        <option key={w.id} value={w.id}>
                          {w.name}
                        </option>
                      ))}
                    </select>
                  )}
                  {activeLog && activeLog.status === 'RUNNING' && (
                    <>
                      <button className="btn-secondary" disabled={busy} onClick={() => laborAction(j.id, activeLog.id, 'pause')}>
                        Pause
                      </button>
                      <button className="btn-secondary" disabled={busy} onClick={() => laborAction(j.id, activeLog.id, 'finish')}>
                        Finish
                      </button>
                    </>
                  )}
                  {activeLog && activeLog.status === 'PAUSED' && (
                    <>
                      <button className="btn-secondary" disabled={busy} onClick={() => laborAction(j.id, activeLog.id, 'resume')}>
                        Resume
                      </button>
                      <button className="btn-secondary" disabled={busy} onClick={() => laborAction(j.id, activeLog.id, 'finish')}>
                        Finish
                      </button>
                    </>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// Issuance & Return only returns a new part that was NOT used; a component taken off the
// vehicle is recorded under Removed Components (Used Sparepart Processing) instead.
const RETURN_CONDITIONS: { value: 'UNUSED_NEW' | 'UNUSED_FAULTY'; label: string }[] = [
  { value: 'UNUSED_NEW', label: 'New Good' },
  { value: 'UNUSED_FAULTY', label: 'New Faulty' },
];


const PLANNED_PART_PRODUCT_TYPES = ['SPARE_PART', 'TIRE', 'CONSUMABLE'];

/**
 * Doc: the TRUE "Planned Parts" tab — a pure budgeting line item (Product + Qty only), never
 * Reserve/Issue/Consume/Return ("jangan tampilkan tombol Reserve, tombol Issue, Consume atau
 * Return"). Feeds Estimated Parts Cost and nothing else. Distinct from "Issuance & Return" below
 * (the OLD Planned Parts tab, renamed, with its full warehouse lifecycle intact).
 */
function PlannedPartsEstimatesTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [products, setProducts] = useState<{ id: string; name: string; product_type: string }[]>([]);
  const [productId, setProductId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canManage = PLANNING_STATUSES.includes(wo.status) && hasPermission('maintenance_job.manage');

  useEffect(() => {
    apiClient
      .get('/app/products', { params: { per_page: 200 } })
      .then((res) => setProducts((res.data.data as { id: string; name: string; product_type: string }[]).filter((p) => PLANNED_PART_PRODUCT_TYPES.includes(p.product_type))))
      .catch(() => setProducts([]));
  }, []);

  async function addEstimate() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/planned-part-estimates`, { product_id: productId, quantity, notes: notes || undefined });
      setProductId('');
      setQuantity('1');
      setNotes('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function deleteEstimate(id: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.delete(`/app/work-orders/${wo.id}/planned-part-estimates/${id}`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Planned Parts</h3>
      {error && <ErrorState message={error} />}
      {(wo.planned_part_estimates ?? []).length === 0 && <EmptyState label="No parts planned." />}
      {(wo.planned_part_estimates ?? []).map((e) => (
        <div key={e.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <span>
            {e.product?.name ?? e.product_id} — qty {formatQty(e.quantity)} {e.notes && <span style={{ color: '#6b7280' }}>({e.notes})</span>}
          </span>
          {canManage && (
            <button className="btn-secondary" disabled={busy} onClick={() => deleteEstimate(e.id)}>
              Delete
            </button>
          )}
        </div>
      ))}
      {canManage && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <select value={productId} onChange={(e) => setProductId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
            <option value="">Product…</option>
            {products.map((prod) => (
              <option key={prod.id} value={prod.id}>
                {prod.name}
              </option>
            ))}
          </select>
          <NumericInput step="0.01" placeholder="Qty" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={{ ...inputStyle, width: 90 }} />
          <input placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} style={inputStyle} />
          <button className="btn-secondary" disabled={busy || !productId || !quantity} onClick={addEstimate}>
            Add
          </button>
        </div>
      )}
      {/* SYSTEM_DERIVED — Σ(Qty x Product price), never manually editable. */}
      <FormField label="Estimated Parts Cost">
        <input value={wo.estimated_parts_cost_computed ?? '—'} readOnly style={{ ...inputStyle, width: 160, background: '#f9fafb' }} />
      </FormField>
    </div>
  );
}

/**
 * "Issuance & Return" (previously labelled "Request Parts", before that "Planned Parts" — the
 * warehouse Reserve/Issue/Consume/Return lifecycle). Consumed quantity is never returnable here;
 * returns of components removed from the unit go through Removed Components.
 * Doc: "Request Parts" — "sebelumnya adalah Tab Planned Parts yang berubah nama" (this IS the
 * old "Planned Parts" tab, renamed — its full Reserve/Issue/Consume/Return lifecycle is
 * unchanged). Only visible from IN_PROGRESS onward; see REQUEST_PARTS_VISIBLE_STATUSES.
 */
function IssuanceReturnTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  // "Reserve" = a Part Request (REQUESTED); approval and issuing happen in Part Requests.
  const [quantity, setQuantity] = useState('1');
  const [notes, setNotes] = useState('');
  const [productId, setProductId] = useState('');
  const [selectedProduct, setSelectedProduct] = useState<ReserveProduct | null>(null);
  const loadedProducts = useRef(new Map<string, ReserveProduct>());
  const [partRequests, setPartRequests] = useState<PartRequestItem[]>([]);
  const [requestsKey, setRequestsKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Consume popup state — "Install All" / "Installed Qty" (doc: only shown when Issued Qty > 1).
  const [consumingPart, setConsumingPart] = useState<WorkOrderPlannedPartItem | null>(null);
  const [installAll, setInstallAll] = useState(true);
  const [installedQty, setInstalledQty] = useState('');

  // Return popup state.
  const [returningPartId, setReturningPartId] = useState<string | null>(null);
  const [returnQty, setReturnQty] = useState('');
  const [returnCondition, setReturnCondition] = useState<(typeof RETURN_CONDITIONS)[number]['value']>('UNUSED_NEW');
  const [returnReason, setReturnReason] = useState('');
  const [returnEvidenceIds, setReturnEvidenceIds] = useState<string[]>([]);
  const returnEvidencePreviews = useAuthorizedPreviews(
    (id) => `/app/work-orders/${wo.id}/planned-parts/${returningPartId}/return-evidence/${id}`,
    returnEvidenceIds,
  );

  const partActionsAvailable = PART_ACTION_STATUSES.includes(wo.status);
  const canReserve = partActionsAvailable && hasPermission('part_request.create');
  const canViewRequests = hasPermission('part_request.view');
  const canConsume = partActionsAvailable && hasPermission('inventory.issue');
  const canReturn = partActionsAvailable && hasPermission('inventory.return');

  // Product dropdown with its search box inside the list: active Products only, searched on the
  // server (the catalog can be large). The stored value is the Product id.
  async function loadProductOptions(search: string): Promise<SearchableOption[]> {
    const res = await apiClient.get('/app/products', { params: { status: 'ACTIVE', search: search || undefined, per_page: 50 } });
    const rows: ReserveProduct[] = res.data.data;
    rows.forEach((prod) => loadedProducts.current.set(prod.id, prod));
    return rows.map((prod) => ({ value: prod.id, label: prod.name, hint: prod.sku }));
  }

  useEffect(() => {
    if (!canViewRequests) return;
    apiClient
      .get(`/app/work-orders/${wo.id}/part-requests`)
      .then((res) => setPartRequests(res.data.data))
      .catch(() => setPartRequests([]));
  }, [canViewRequests, wo.id, requestsKey]);

  async function reservePart() {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/part-requests`, {
        notes: notes || undefined,
        items: [{ product_id: productId, quantity_requested: quantity }],
      });
      setProductId('');
      setSelectedProduct(null);
      setQuantity('1');
      setNotes('');
      setNotice('Part Request created (REQUESTED). It is approved and issued from Part Requests.');
      setRequestsKey((k) => k + 1);
    } catch (err) {
      const e = extractApiError(err);
      setError(e.errors ? Object.values(e.errors).flat()[0] ?? e.message : e.message);
    } finally {
      setBusy(false);
    }
  }

  async function partAction(partId: string, action: 'return' | 'consume', body?: Record<string, unknown>) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/planned-parts/${partId}/${action}`, body ?? {});
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  function outstandingIssued(p: WorkOrderPlannedPartItem): number {
    return Number(p.issued_quantity) - Number(p.consumed_quantity) - Number(p.returned_quantity);
  }

  // Consumed quantity is never returnable; the backend's returnable_quantity is authoritative.
  function returnableQuantity(p: WorkOrderPlannedPartItem): number {
    if (p.status === 'CONSUMED') return 0;
    return p.returnable_quantity !== undefined ? Number(p.returnable_quantity) : Math.max(0, outstandingIssued(p));
  }

  // Doc: Consume popup only appears when Issued Qty > 1 — a single outstanding unit has
  // nothing to choose, so it's consumed directly with no dialog.
  function startConsume(p: WorkOrderPlannedPartItem) {
    if (outstandingIssued(p) <= 1) {
      partAction(p.id, 'consume');
      return;
    }
    setConsumingPart(p);
    setInstallAll(true);
    setInstalledQty(String(outstandingIssued(p)));
  }

  async function confirmConsume() {
    if (!consumingPart || !installedQty) return;
    await partAction(consumingPart.id, 'consume', { quantity: installedQty });
    setConsumingPart(null);
  }

  function startReturn(partId: string) {
    setReturningPartId(partId);
    setReturnQty('');
    setReturnCondition('UNUSED_NEW');
    setReturnReason('');
    setReturnEvidenceIds([]);
  }

  async function uploadReturnEvidence(file: File) {
    if (!returningPartId) return;
    const form = new FormData();
    form.append('file', file);
    const res = await apiClient.post(`/app/work-orders/${wo.id}/planned-parts/${returningPartId}/return-evidence`, form);
    setReturnEvidenceIds((prev) => [...prev, res.data.data.id]);
  }

  async function removeReturnEvidence(id: string) {
    if (!returningPartId) return;
    await apiClient.delete(`/app/work-orders/${wo.id}/planned-parts/${returningPartId}/return-evidence/${id}`);
    setReturnEvidenceIds((prev) => prev.filter((e) => e !== id));
  }

  async function submitReturn(partId: string) {
    if (!returnQty) return;
    await partAction(partId, 'return', {
      quantity: returnQty,
      condition: returnCondition,
      reason: returnReason || undefined,
      evidence_ids: returnEvidenceIds.length > 0 ? returnEvidenceIds : undefined,
    });
    setReturningPartId(null);
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Issuance &amp; Return</h3>
      {error && <ErrorState message={error} />}
      {notice && <div style={{ color: '#047857', fontSize: 13, marginBottom: 8 }}>{notice}</div>}

      {canReserve && (
        <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap', alignItems: 'flex-end', background: '#f9fafb', padding: 10, borderRadius: 6 }}>
          <FormField label="Product" required>
            <SearchableSelect
              ariaLabel="Product"
              value={productId}
              selectedLabel={selectedProduct ? `${selectedProduct.name}${selectedProduct.sku ? ` — ${selectedProduct.sku}` : ''}` : null}
              placeholder="Select product…"
              searchPlaceholder="Search product…"
              loadOptions={loadProductOptions}
              onChange={(id) => {
                setProductId(id);
                setSelectedProduct(loadedProducts.current.get(id) ?? null);
              }}
              width={300}
            />
          </FormField>
          <FormField label="Qty" required>
            <NumericInput
              aria-label="Quantity"
              integer={!selectedProduct?.uom?.allows_fractional_quantity}
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              style={{ ...inputStyle, width: 90 }}
            />
          </FormField>
          <FormField label="Notes">
            <input placeholder="Optional" value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, width: 200 }} />
          </FormField>
          <button className="btn-primary" disabled={busy || !productId || !(Number(quantity) > 0)} onClick={reservePart} style={{ marginBottom: 14 }}>
            Reserve
          </button>
        </div>
      )}

      {canViewRequests && partRequests.length > 0 && (
        <div style={{ marginBottom: 14 }}>
          <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4 }}>
            Part Requests <Link to={`/app/part-requests?work_order_id=${wo.id}`} style={{ fontWeight: 400, fontSize: 12 }}>open in Part Requests →</Link>
          </div>
          {partRequests.map((r) => (
            <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 13, padding: '4px 0', borderBottom: '1px solid #f3f4f6' }}>
              <span>
                {(r.items ?? []).map((i) => `${lineName(i.product?.name ?? i.description, i.stock_condition)} × ${formatQty(i.quantity_approved ?? i.quantity_requested)}`).join(', ')}
                {r.requested_at && <span style={{ color: '#9ca3af', fontSize: 12 }}> · {new Date(r.requested_at).toLocaleString()}</span>}
              </span>
              <StatusBadge status={r.status} />
            </div>
          ))}
        </div>
      )}

      <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 4 }}>Issued parts</div>
      {(wo.planned_parts ?? []).length === 0 && <EmptyState label="No parts issued to this Work Order yet." />}
      {(wo.planned_parts ?? []).map((p) => (
        <div key={p.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <StatusBadge status={p.status} />
              <strong>{lineName(p.product?.name ?? p.description, p.stock_condition)}</strong>
              <span>— approved {formatQty(p.planned_quantity)}</span>
              {p.notes && <span style={{ color: '#6b7280' }}>({p.notes})</span>}
            </span>
          </div>
          {p.product_id && (
            <>
              <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
                issued {formatQty(p.issued_quantity)} · used {formatQty(p.consumed_quantity)} · returned {formatQty(p.returned_quantity)}
                {Number(p.reserved_quantity) > 0 && ` · reserved ${formatQty(p.reserved_quantity)}`}
                {p.average_unit_cost && (
                  <>
                    {' · '}Unit Cost <strong>{formatMoney(p.average_unit_cost)}</strong>
                    {' · '}Total Cost <strong title="Consumed quantity × Unit Cost — returned quantity is not charged">{formatMoney(p.consumed_total_cost)}</strong>
                  </>
                )}
              </div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {canConsume && outstandingIssued(p) > 0 && (
                  <button className="btn-secondary" disabled={busy} onClick={() => startConsume(p)}>
                    Consume
                  </button>
                )}
                {canReturn && returnableQuantity(p) > 0 && returningPartId !== p.id && (
                  <button className="btn-secondary" disabled={busy} onClick={() => startReturn(p.id)}>
                    Return
                  </button>
                )}
                {/* Business rule: Consumed material can never be returned here (backend enforces it too);
                    components physically removed from the unit are returned via Removed Components. */}
                {canReturn && p.status === 'CONSUMED' && (
                  <button className="btn-secondary" disabled title="Consumed items cannot be returned. Use Removed Components for components removed from the unit.">
                    Return
                  </button>
                )}
              </div>
              {returningPartId === p.id && (
                <div style={{ marginTop: 8, background: '#f9fafb', padding: 10, borderRadius: 6 }}>
                  {/* SYSTEM_INFORMATION — the ceiling the user is returning against, never re-entered. */}
                  <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 8 }}>
                    Available to return: <strong>{formatQty(returnableQuantity(p))}</strong> (Issued {formatQty(p.issued_quantity)} − Used {formatQty(p.consumed_quantity)} − Returned {formatQty(p.returned_quantity)})
                  </div>
                  <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center', marginBottom: 8 }}>
                    <NumericInput
                      integer={!p.product?.uom?.allows_fractional_quantity}
                      placeholder="Qty"
                      value={returnQty}
                      onChange={(e) => setReturnQty(e.target.value)}
                      style={{ ...inputStyle, width: 90 }}
                    />
                    <select
                      value={returnCondition}
                      onChange={(e) => setReturnCondition(e.target.value as (typeof RETURN_CONDITIONS)[number]['value'])}
                      style={{ ...inputStyle, width: 150 }}
                    >
                      {RETURN_CONDITIONS.map((c) => (
                        <option key={c.value} value={c.value}>
                          {c.label}
                        </option>
                      ))}
                    </select>
                    <input
                      placeholder="Reason (optional)"
                      value={returnReason}
                      onChange={(e) => setReturnReason(e.target.value)}
                      style={{ ...inputStyle, width: 180 }}
                    />
                  </div>
                  <div style={{ marginBottom: 8 }}>
                    <ImageUploadField
                      images={returnEvidenceIds.map((id) => ({ id, previewUrl: returnEvidencePreviews[id] ?? '' }))}
                      onUpload={uploadReturnEvidence}
                      onRemove={removeReturnEvidence}
                    />
                  </div>
                  <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <button className="btn-secondary" disabled={busy || !returnQty} onClick={() => submitReturn(p.id)}>
                      Confirm return
                    </button>
                    <button className="btn-secondary" disabled={busy} onClick={() => setReturningPartId(null)}>
                      Cancel
                    </button>
                    <span style={{ fontSize: 11, color: '#6b7280' }}>
                      Creates a numbered Return; the warehouse inspects it in Inventory → Return before anything goes back to stock.
                    </span>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      ))}
      <Modal open={!!consumingPart} title="Consumed Parts" onClose={() => setConsumingPart(null)}>
        {consumingPart && (
          <>
            {/* SYSTEM_INFORMATION */}
            <div style={{ fontSize: 13, marginBottom: 12 }}>
              <div>
                <strong>{lineName(consumingPart.product?.name ?? consumingPart.description, consumingPart.stock_condition)}</strong>
              </div>
              <div style={{ color: '#6b7280' }}>Issued Qty: {formatQty(consumingPart.issued_quantity)}</div>
            </div>
            <FormField label="">
              <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <input
                  type="checkbox"
                  checked={installAll}
                  onChange={(e) => {
                    setInstallAll(e.target.checked);
                    if (e.target.checked) setInstalledQty(String(outstandingIssued(consumingPart)));
                  }}
                />
                Install All
              </label>
            </FormField>
            <FormField label="Installed Qty" required>
              <NumericInput
                integer={!consumingPart.product?.uom?.allows_fractional_quantity}
                value={installedQty}
                disabled={installAll}
                onChange={(e) => setInstalledQty(e.target.value)}
                style={inputStyle}
              />
            </FormField>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
              <button className="btn-secondary" onClick={() => setConsumingPart(null)}>
                Cancel
              </button>
              <button className="btn-primary" disabled={!installedQty} onClick={confirmConsume}>
                Consume
              </button>
            </div>
          </>
        )}
      </Modal>

      <RemovedComponentsSection wo={wo} onChanged={onChanged} />
    </div>
  );
}

/**
 * Owner decision: "Used Qty" in the doc's Return popup means an old/removed component taken
 * off the vehicle during a replacement — architecturally distinct from the Unused-issued-stock
 * Return above (which only ever represents warehouse-issued stock, whether installed or not).
 * Presented as its own section so the two concepts are never conflated in the UI either.
 */
interface ReserveProduct {
  id: string;
  name: string;
  sku?: string | null;
  uom?: { allows_fractional_quantity?: boolean } | null;
}

function consumedRemovableProducts(wo: WorkOrderItem) {
  const byProduct = new Map<string, { productId: string; name: string; consumed: number; remaining: number; allowsFraction: boolean }>();
  for (const p of wo.planned_parts ?? []) {
    if (!p.product_id || !(Number(p.consumed_quantity) > 0)) continue;
    const entry = byProduct.get(p.product_id) ?? {
      productId: p.product_id,
      name: p.product?.name ?? p.description,
      consumed: 0,
      remaining: 0,
      allowsFraction: Boolean(p.product?.uom?.allows_fractional_quantity),
    };
    entry.consumed += Number(p.consumed_quantity);
    byProduct.set(p.product_id, entry);
  }
  for (const entry of byProduct.values()) {
    const removed = (wo.removed_components ?? []).filter((rc) => rc.product_id === entry.productId).reduce((sum, rc) => sum + Number(rc.quantity), 0);
    entry.remaining = Math.max(0, entry.consumed - removed);
  }
  return [...byProduct.values()];
}

function RemovedComponentsSection({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [warehouses, setWarehouses] = useState<{ id: string; name: string }[]>([]);
  const [productId, setProductId] = useState('');
  const [jobId, setJobId] = useState('');
  const [removeQty, setRemoveQty] = useState('1');
  const [condition, setCondition] = useState<'GOOD' | 'FAULTY'>('GOOD');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [returningId, setReturningId] = useState<string | null>(null);
  const [returnWarehouseId, setReturnWarehouseId] = useState('');
  const [returnReason, setReturnReason] = useState('');
  const [evidenceByComponent, setEvidenceByComponent] = useState<Record<string, string[]>>({});
  const allEvidenceIds = Object.values(evidenceByComponent).flat();
  const evidencePreviews = useAuthorizedPreviews(
    (id) => {
      const componentId = Object.keys(evidenceByComponent).find((cid) => evidenceByComponent[cid].includes(id));
      return `/app/work-orders/${wo.id}/removed-components/${componentId}/evidence/${id}`;
    },
    allEvidenceIds,
  );

  const canManage = PLANNING_STATUSES.includes(wo.status) && hasPermission('maintenance_job.manage');
  // Only products CONSUMED on this Work Order can be recorded as removed (the old component the new
  // part replaced), up to the consumed quantity. The backend enforces the same rule and derives the
  // replacement link itself.
  const removable = consumedRemovableProducts(wo);
  const selectedRemovable = removable.find((r) => r.productId === productId);
  const canReturn = PART_ACTION_STATUSES.includes(wo.status) && hasPermission('inventory.return');

  useEffect(() => {
    apiClient.get('/app/warehouses', { params: { per_page: 100 } }).then((res) => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
  }, []);

  function evidenceIdsFor(componentId: string): string[] {
    return evidenceByComponent[componentId] ?? [];
  }

  async function uploadEvidence(componentId: string, file: File) {
    const form = new FormData();
    form.append('file', file);
    const res = await apiClient.post(`/app/work-orders/${wo.id}/removed-components/${componentId}/evidence`, form);
    setEvidenceByComponent((prev) => ({ ...prev, [componentId]: [...(prev[componentId] ?? []), res.data.data.id] }));
  }

  async function removeEvidence(componentId: string, evidenceId: string) {
    await apiClient.delete(`/app/work-orders/${wo.id}/removed-components/${componentId}/evidence/${evidenceId}`);
    setEvidenceByComponent((prev) => ({ ...prev, [componentId]: (prev[componentId] ?? []).filter((id) => id !== evidenceId) }));
  }

  async function submitRemoval() {
    if (!productId || !removeQty) return;
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/removed-components`, {
        product_id: productId,
        maintenance_job_id: jobId || undefined,
        quantity: removeQty,
        condition,
        notes: notes || undefined,
      });
      setProductId('');
      setJobId('');
      setRemoveQty('1');
      setCondition('GOOD');
      setNotes('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function deleteRemoval(id: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.delete(`/app/work-orders/${wo.id}/removed-components/${id}`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function submitReturn(id: string) {
    if (!returnWarehouseId) return;
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/removed-components/${id}/return`, {
        warehouse_id: returnWarehouseId,
        reason: returnReason || undefined,
      });
      setReturningId(null);
      setReturnWarehouseId('');
      setReturnReason('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div style={{ marginTop: 20, paddingTop: 16, borderTop: '1px solid #e5e7eb' }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Removed Components</h3>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: -8 }}>
        Old/used components taken off the vehicle when a replacement part is installed — separate from the Unused Return above,
        and never a reversal of the new part's consumption. Each recorded removal is processed in Inventory → Used Sparepart Processing.
      </p>
      {error && <ErrorState message={error} />}
      {(wo.removed_components ?? []).length === 0 && <EmptyState label="No components removed." />}
      {(wo.removed_components ?? []).map((rc) => (
        <div key={rc.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <span>
              {rc.product?.name ?? rc.product_id} — qty {formatQty(rc.quantity)} — {rc.condition}
              {rc.replaced_by_planned_part_id && <span style={{ color: '#6b7280' }}> (replaced by the consumed new part)</span>}
            </span>
            <StatusBadge status={rc.status} />
          </div>
          {rc.notes && <div style={{ color: '#6b7280', marginBottom: 6 }}>{rc.notes}</div>}
          <ImageUploadField
            images={evidenceIdsFor(rc.id).map((id) => ({ id, previewUrl: evidencePreviews[id] ?? '' }))}
            onUpload={(file) => uploadEvidence(rc.id, file)}
            onRemove={rc.status === 'PENDING_RETURN' ? (id) => removeEvidence(rc.id, id) : undefined}
            disabled={rc.status !== 'PENDING_RETURN'}
          />
          {rc.status === 'PENDING_RETURN' && (
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8, alignItems: 'center' }}>
              {canReturn && returningId !== rc.id && (
                <button className="btn-secondary" disabled={busy} onClick={() => setReturningId(rc.id)}>
                  Return to Warehouse
                </button>
              )}
              {canManage && (
                <button className="btn-secondary" disabled={busy} onClick={() => deleteRemoval(rc.id)}>
                  Delete
                </button>
              )}
            </div>
          )}
          {returningId === rc.id && (
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8, alignItems: 'center', background: '#f9fafb', padding: 8, borderRadius: 6 }}>
              <select value={returnWarehouseId} onChange={(e) => setReturnWarehouseId(e.target.value)} style={{ ...inputStyle, width: 180 }}>
                <option value="">Warehouse…</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
              </select>
              <input placeholder="Reason (optional)" value={returnReason} onChange={(e) => setReturnReason(e.target.value)} style={{ ...inputStyle, width: 180 }} />
              <button className="btn-secondary" disabled={busy || !returnWarehouseId} onClick={() => submitReturn(rc.id)}>
                Confirm Return
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => setReturningId(null)}>
                Cancel
              </button>
            </div>
          )}
        </div>
      ))}
      {canManage && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap', alignItems: 'center' }}>
          <select
            aria-label="Old/removed product"
            value={productId}
            onChange={(e) => {
              setProductId(e.target.value);
              setRemoveQty('1');
            }}
            style={{ ...inputStyle, width: 220 }}
          >
            <option value="">{removable.length === 0 ? 'No consumed parts yet' : 'Old/removed product…'}</option>
            {removable.map((r) => (
              <option key={r.productId} value={r.productId} disabled={r.remaining <= 0}>
                {r.name} (consumed {formatQty(r.consumed)}, removable {formatQty(r.remaining)})
              </option>
            ))}
          </select>
          <select value={jobId} onChange={(e) => setJobId(e.target.value)} style={{ ...inputStyle, width: 160 }}>
            <option value="">Job (optional)</option>
            {(wo.jobs ?? []).map((j) => (
              <option key={j.id} value={j.id}>
                {j.service_item ?? j.description.slice(0, 30)}
              </option>
            ))}
          </select>
          <NumericInput
            aria-label="Removed quantity"
            integer={!selectedRemovable?.allowsFraction}
            placeholder="Qty"
            value={removeQty}
            onChange={(e) => setRemoveQty(e.target.value)}
            style={{ ...inputStyle, width: 90 }}
          />
          <select value={condition} onChange={(e) => setCondition(e.target.value as 'GOOD' | 'FAULTY')} style={{ ...inputStyle, width: 110 }}>
            <option value="GOOD">Good</option>
            <option value="FAULTY">Faulty</option>
          </select>
          <input placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} style={inputStyle} />
          <button className="btn-secondary" disabled={busy || !productId || !(Number(removeQty) > 0) || Number(removeQty) > (selectedRemovable?.remaining ?? 0)} onClick={submitRemoval}>
            Record Removal
          </button>
        </div>
      )}
    </div>
  );
}

function WorkspaceTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const reservations: WorkspaceReservationItem[] = wo.workspace_reservations ?? [];
  const canManage = hasPermission('workspace.reserve');

  async function act(id: string, action: 'activate' | 'complete' | 'cancel') {
    setBusyId(id);
    setError(null);
    try {
      await apiClient.post(`/app/workspace-reservations/${id}/${action}`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Workspace Reservations</h3>
      {error && <ErrorState message={error} />}
      {reservations.length === 0 ? (
        <EmptyState label="No workspace reserved yet. Use the Schedule action to assign one." />
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px 4px' }}>Workspace</th>
              <th style={{ padding: '6px 4px' }}>Start</th>
              <th style={{ padding: '6px 4px' }}>End</th>
              <th style={{ padding: '6px 4px' }}>Status</th>
              {canManage && <th style={{ padding: '6px 4px' }} />}
            </tr>
          </thead>
          <tbody>
            {reservations.map((r) => (
              <tr key={r.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                <td style={{ padding: '6px 4px' }}>{r.workspace?.name ?? r.workspace_id} {r.workspace?.code ? `(${r.workspace.code})` : ''}</td>
                <td style={{ padding: '6px 4px' }}>{new Date(r.start_at).toLocaleString()}</td>
                <td style={{ padding: '6px 4px' }}>{new Date(r.end_at).toLocaleString()}</td>
                <td style={{ padding: '6px 4px' }}><StatusBadge status={r.status} /></td>
                {canManage && (
                  <td style={{ padding: '6px 4px' }}>
                    <div style={{ display: 'flex', gap: 6 }}>
                      {r.status === 'RESERVED' && (
                        <>
                          <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'activate')}>
                            Activate
                          </button>
                          <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'cancel')}>
                            Cancel
                          </button>
                        </>
                      )}
                      {r.status === 'ACTIVE' && (
                        <button className="btn-secondary" disabled={busyId === r.id} onClick={() => act(r.id, 'complete')}>
                          Complete
                        </button>
                      )}
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function QcTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [inspections, setInspections] = useState<QcInspectionItem[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [findingDesc, setFindingDesc] = useState('');
  const [findingSeverity, setFindingSeverity] = useState('MEDIUM');

  function loadQc() {
    apiClient.get('/app/qc-inspections', { params: { work_order_id: wo.id, per_page: 20 } }).then((res) => setInspections(res.data.data)).catch(() => setInspections([]));
  }

  useEffect(loadQc, [wo.id]);

  async function start() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/qc/start`);
      loadQc();
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function addFinding(inspectionId: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/qc/${inspectionId}/findings`, { description: findingDesc, severity: findingSeverity });
      setFindingDesc('');
      loadQc();
    } finally {
      setBusy(false);
    }
  }

  /** G-04: previously a QC finding's `resolved` flag had no API to ever set it true. */
  async function resolveFinding(inspectionId: string, findingId: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/qc/${inspectionId}/findings/${findingId}/resolve`);
      loadQc();
    } finally {
      setBusy(false);
    }
  }

  async function pass(inspectionId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/qc/${inspectionId}/pass`);
      loadQc();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function fail(inspectionId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/qc/${inspectionId}/fail`);
      loadQc();
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function complete(inspectionId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/qc/${inspectionId}/complete`);
      loadQc();
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  const latest = inspections[0];

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Quality Control</h3>
      {error && <ErrorState message={error} />}
      {wo.status === 'QC_PENDING' && !latest && hasPermission('qc.perform') && (
        <button className="btn-primary" disabled={busy} onClick={start}>
          Start QC Inspection
        </button>
      )}
      {inspections.length === 0 && !(wo.status === 'QC_PENDING') && <EmptyState label="No QC inspection yet." />}
      {inspections.map((i) => (
        <div key={i.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 6 }}>
            <StatusBadge status={i.status} />
            {i.notes && <span style={{ color: '#6b7280' }}>{i.notes}</span>}
          </div>
          {(i.findings ?? []).map((f) => (
            <div key={f.id} style={{ paddingLeft: 8, color: '#6b7280', display: 'flex', gap: 8, alignItems: 'center' }}>
              <span>
                [{f.severity}] {f.description} {f.resolved ? '(Resolved)' : '(Open)'}
              </span>
              {!f.resolved && hasPermission('qc.perform') && (
                <button className="btn-secondary" disabled={busy} onClick={() => resolveFinding(i.id, f.id)}>
                  Resolve
                </button>
              )}
            </div>
          ))}
          {i.status === 'QC_STARTED' && hasPermission('qc.perform') && (
            <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
              <select value={findingSeverity} onChange={(e) => setFindingSeverity(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                {['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </select>
              <input placeholder="Finding" value={findingDesc} onChange={(e) => setFindingDesc(e.target.value)} style={{ ...inputStyle, width: 220 }} />
              <button className="btn-secondary" disabled={busy || !findingDesc} onClick={() => addFinding(i.id)}>
                Add Finding
              </button>
            </div>
          )}
          {i.status === 'QC_STARTED' && hasPermission('qc.approve') && (
            <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
              <button className="btn-primary" disabled={busy} onClick={() => pass(i.id)}>
                Pass
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => fail(i.id)}>
                Fail (Rework)
              </button>
            </div>
          )}
          {i.status === 'PASS' && hasPermission('qc.approve') && (
            <button className="btn-primary" disabled={busy} onClick={() => complete(i.id)} style={{ marginTop: 8 }}>
              Complete QC
            </button>
          )}
        </div>
      ))}
    </div>
  );
}

function RoadTestTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const roadTests = wo.road_tests ?? [];
  const release = wo.vehicle_release ?? null;
  const [result, setResult] = useState('PASS');
  const [notes, setNotes] = useState('');
  const [releaseOdometer, setReleaseOdometer] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function record() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/road-test`, { result, notes: notes || undefined });
      setNotes('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function releaseVehicle() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/release`, { release_odometer: releaseOdometer || undefined });
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      {error && <ErrorState message={error} />}
      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Road Test</h3>
        {roadTests.length === 0 && <EmptyState label="No road test recorded." />}
        {roadTests.map((r) => (
          <div key={r.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 10 }}>
            <StatusBadge status={r.result} />
            <span>{r.notes ?? '—'}</span>
          </div>
        ))}
        {hasPermission('qc.perform') && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
            <select value={result} onChange={(e) => setResult(e.target.value)} style={{ ...inputStyle, width: 140 }}>
              {['PASS', 'FAIL', 'NOT_REQUIRED'].map((r) => (
                <option key={r} value={r}>
                  {r}
                </option>
              ))}
            </select>
            <input placeholder="Notes" value={notes} onChange={(e) => setNotes(e.target.value)} style={inputStyle} />
            <button className="btn-secondary" disabled={busy} onClick={record}>
              Record Road Test
            </button>
          </div>
        )}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Release</h3>
        {release ? (
          <p style={{ fontSize: 13 }}>Released at {new Date(release.released_at).toLocaleString()}.</p>
        ) : wo.status === 'COMPLETED' && hasPermission('vehicle_release.perform') ? (
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <NumericInput placeholder="Release odometer (optional)" value={releaseOdometer} onChange={(e) => setReleaseOdometer(e.target.value)} style={{ ...inputStyle, width: 180 }} />
            <button className="btn-primary" disabled={busy} onClick={releaseVehicle}>
              Release Vehicle
            </button>
          </div>
        ) : (
          <EmptyState label="Vehicle can be released once the Work Order is COMPLETED." />
        )}
      </div>
    </div>
  );
}

/** G-07: Partner types TOWING_PROVIDER/OTHER_SERVICE_PROVIDER previously had no workflow that ever used them. */
function ExternalServicesTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [partnerId, setPartnerId] = useState('');
  const [description, setDescription] = useState('');
  const [photoEvidence, setPhotoEvidence] = useState('');
  const [conditionNotes, setConditionNotes] = useState('');
  const [priority, setPriority] = useState('');
  const [referenceNumber, setReferenceNumber] = useState('');
  const [cost, setCost] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [recordingInvoiceFor, setRecordingInvoiceFor] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/partners', { params: { per_page: 200 } }).then((res) => setPartners(res.data.data)).catch(() => setPartners([]));
  }, []);

  async function request() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/external-services`, {
        partner_id: partnerId, description,
        photo_evidence: photoEvidence || undefined,
        condition_notes: conditionNotes || undefined,
        priority: priority || undefined,
        reference_number: referenceNumber || undefined, cost: cost || undefined,
      });
      setDescription('');
      setPhotoEvidence('');
      setConditionNotes('');
      setPriority('');
      setReferenceNumber('');
      setCost('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function complete(serviceId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/external-services/${serviceId}/complete`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function cancel(serviceId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/external-services/${serviceId}/cancel`);
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  /** Final reconciliation (queued ADJUST): VMS's Maintenance Memo "Save and Print". */
  async function printMemo(serviceId: string) {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.get(`/app/work-orders/${wo.id}/external-services/${serviceId}/print`, { responseType: 'blob' });
      const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      window.open(url, '_blank');
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>External Services</h3>
      {error && <ErrorState message={error} />}
      {(wo.external_services ?? []).length === 0 && <EmptyState label="No external service has been requested for this Work Order." />}
      {(wo.external_services ?? []).map((s) => (
        <div key={s.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 4 }}>
            <StatusBadge status={s.status} />
            <strong>{s.partner?.name ?? s.partner_id}</strong>
            {s.partner?.partner_type && <span style={{ color: '#9ca3af' }}>({s.partner.partner_type})</span>}
          </div>
          <div style={{ color: '#374151' }}>{s.description}</div>
          <div style={{ color: '#6b7280', fontSize: 12, marginTop: 2 }}>
            {s.reference_number && <>Ref: {s.reference_number} &nbsp;</>}
            {s.cost && <>Cost: {formatMoney(s.cost)} &nbsp;</>}
            {s.priority && <>Priority: {s.priority}</>}
          </div>
          {s.condition_notes && <div style={{ color: '#6b7280', fontSize: 12, marginTop: 2 }}>Condition: {s.condition_notes}</div>}
          {s.photo_evidence && (
            <div style={{ fontSize: 12, marginTop: 2 }}>
              Evidence:{' '}
              <a href={s.photo_evidence} target="_blank" rel="noreferrer">
                {s.photo_evidence}
              </a>
            </div>
          )}
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            {s.status === 'REQUESTED' && hasPermission('work_order_external_service.complete') && (
              <button className="btn-primary" disabled={busy} onClick={() => complete(s.id)}>
                Complete
              </button>
            )}
            {s.status === 'REQUESTED' && hasPermission('work_order_external_service.cancel') && (
              <button className="btn-secondary" disabled={busy} onClick={() => cancel(s.id)}>
                Cancel
              </button>
            )}
            {hasPermission('work_order.view') && (
              <button className="btn-secondary" disabled={busy} onClick={() => printMemo(s.id)}>
                Print Memo
              </button>
            )}
            {s.status === 'COMPLETED' && hasPermission('workshop_invoice.record') && (
              <button className="btn-primary" disabled={busy} onClick={() => setRecordingInvoiceFor(s.id)}>
                Record Service Invoice
              </button>
            )}
            {(s.status === 'BILLED' || s.status === 'PAID') && s.workshop_invoice_id && hasPermission('workshop_invoice.view') && (
              <Link className="btn-secondary" to={`/app/workshop-invoices/${s.workshop_invoice_id}`}>
                View Service Invoice
              </Link>
            )}
          </div>
        </div>
      ))}
      {recordingInvoiceFor && (
        <RecordWorkshopInvoiceModal
          workOrderId={wo.id}
          externalServiceId={recordingInvoiceFor}
          onClose={() => setRecordingInvoiceFor(null)}
          onRecorded={(invoiceId) => {
            setRecordingInvoiceFor(null);
            onChanged();
            window.location.assign(`/app/workshop-invoices/${invoiceId}`);
          }}
        />
      )}
      {hasPermission('work_order_external_service.create') && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <FormField label="Partner" required>
            <select value={partnerId} onChange={(e) => setPartnerId(e.target.value)} style={{ ...inputStyle, width: 200 }}>
              <option value="">Select partner</option>
              {partners.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} ({p.partner_type})
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Description" required>
            <input value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, width: 220 }} />
          </FormField>
          <FormField label="Priority (optional)">
            <select value={priority} onChange={(e) => setPriority(e.target.value)} style={{ ...inputStyle, width: 120 }}>
              <option value="">—</option>
              {['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => (
                <option key={p} value={p}>
                  {p}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Condition (optional)">
            <input value={conditionNotes} onChange={(e) => setConditionNotes(e.target.value)} style={{ ...inputStyle, width: 160 }} />
          </FormField>
          <FormField label="Evidence / photo URL (optional)">
            <input value={photoEvidence} onChange={(e) => setPhotoEvidence(e.target.value)} style={{ ...inputStyle, width: 180 }} />
          </FormField>
          <FormField label="Reference # (optional)">
            <input value={referenceNumber} onChange={(e) => setReferenceNumber(e.target.value)} style={{ ...inputStyle, width: 120 }} />
          </FormField>
          <FormField label="Cost (optional)">
            <NumericInput min="0" step="0.01" value={cost} onChange={(e) => setCost(e.target.value)} style={{ ...inputStyle, width: 100 }} />
          </FormField>
          <button className="btn-secondary" disabled={busy || !partnerId || !description} onClick={request}>
            Request
          </button>
        </div>
      )}
    </div>
  );
}

interface WorkOrderDocument {
  type: 'WORK_AUTHORIZATION_LETTER' | 'EXTERNAL_WORKSHOP_INVOICE' | 'PAYMENT_PROOF';
  title: string;
  status: string;
  reference?: string | null;
  date: string | null;
  date_kind: 'UPLOADED_AT' | 'INVOICE_DATE' | 'PAYMENT_DATE';
  amount?: string | null;
  file: { name: string; mime_type: string | null; size: number | null; uploaded_at: string | null } | null;
  path: string;
}

const DOCUMENT_ACTION: Record<WorkOrderDocument['type'], string> = {
  WORK_AUTHORIZATION_LETTER: 'View WAL',
  EXTERNAL_WORKSHOP_INVOICE: 'View Invoice',
  PAYMENT_PROOF: 'View Payment Proof',
};

/**
 * Work Order documents. External Workshop Work Orders list the acknowledged Work Authorization
 * Letter, the External Workshop Invoice and the Payment Proof; each file opens by its real
 * type (image / PDF / download) through the backend's authorized file endpoints.
 */
function DocumentsTab({ workOrderId }: { workOrderId: string }) {
  const { hasPermission } = useAuth();
  const [docs, setDocs] = useState<WorkOrderDocument[] | null>(null);
  const [mode, setMode] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [viewing, setViewing] = useState<WorkOrderDocument | null>(null);
  const canOpen = hasPermission('external_work_order_invoice.view');

  useEffect(() => {
    apiClient
      .get(`/app/work-orders/${workOrderId}/documents`)
      .then((res) => {
        setDocs(res.data.data.documents);
        setMode(res.data.data.execution_mode);
      })
      .catch((err) => setError(extractApiError(err).message));
  }, [workOrderId]);

  if (error) return <ErrorState message={error} />;
  if (!docs) return <LoadingState />;

  const dateLabel = (d: WorkOrderDocument) =>
    d.date_kind === 'UPLOADED_AT' ? `Uploaded: ${formatDateTime(d.date)}` : d.date_kind === 'INVOICE_DATE' ? `Invoice Date: ${formatDate(d.date)}` : `Payment Date: ${formatDate(d.date)}`;

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Documents</h3>
      {docs.length === 0 && (
        <EmptyState
          label={
            mode === 'EXTERNAL'
              ? 'No External Workshop documents yet — the acknowledged Work Authorization Letter, the workshop invoice and the payment proof appear here as the work progresses.'
              : "Work Order-level documents are not tracked for internal Work Orders — see the vehicle's Documents tab for vehicle-level records."
          }
        />
      )}
      {docs.map((d) => (
        <div key={d.type} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, padding: '12px 0', borderBottom: '1px solid #f3f4f6', flexWrap: 'wrap' }}>
          <div style={{ fontSize: 13 }}>
            <div style={{ fontWeight: 600 }}>
              {d.title}
              {d.reference && <span style={{ fontWeight: 400, color: '#6b7280' }}> · {d.reference}</span>}
            </div>
            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 4, color: '#374151', flexWrap: 'wrap' }}>
              {d.type === 'WORK_AUTHORIZATION_LETTER' && <StatusBadge status="ACKNOWLEDGED" />}
              <span>{dateLabel(d)}</span>
              {d.amount != null && <span>Amount: {formatMoney(d.amount)}</span>}
              {d.file && <span style={{ color: '#6b7280' }}>{d.file.name}</span>}
            </div>
          </div>
          {canOpen && d.file && (
            <button className="btn-secondary" onClick={() => setViewing(d)}>
              {DOCUMENT_ACTION[d.type]}
            </button>
          )}
        </div>
      ))}
      {viewing && <DocumentViewer path={viewing.path} title={viewing.title} fileName={viewing.file?.name} mimeType={viewing.file?.mime_type} onClose={() => setViewing(null)} />}
    </div>
  );
}

function HistoryTab({ vehicleId }: { vehicleId: string }) {
  const [events, setEvents] = useState<HistoryEventItem[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get(`/app/vehicles/${vehicleId}/history`)
      .then((res) => setEvents(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, [vehicleId]);

  if (error) return <ErrorState message={error} />;

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle History (this Work Order's vehicle)</h3>
      {events.length === 0 && <EmptyState label="No history events." />}
      {events.map((e) => (
        <div key={`${e.type}-${e.id}`} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 10 }}>
          <StatusBadge status={e.type} />
          <span style={{ color: '#9ca3af' }}>{new Date(e.at).toLocaleString()}</span>
          <span>{e.summary}</span>
        </div>
      ))}
    </div>
  );
}

/** R1: records an externally-issued third-party Service Invoice (domain: WorkshopInvoice) against a COMPLETED Maintenance Memo — OptiFleet never issues one. */
function RecordWorkshopInvoiceModal({
  workOrderId,
  externalServiceId,
  onClose,
  onRecorded,
}: {
  workOrderId: string;
  externalServiceId: string;
  onClose: () => void;
  onRecorded: (invoiceId: string) => void;
}) {
  const [externalInvoiceNumber, setExternalInvoiceNumber] = useState('');
  const [invoiceDate, setInvoiceDate] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [currency, setCurrency] = useState('IDR');
  const [totalAmount, setTotalAmount] = useState('');
  const [subtotal, setSubtotal] = useState('');
  const [taxTotal, setTaxTotal] = useState('');
  const [discountTotal, setDiscountTotal] = useState('');
  const [partnerReference, setPartnerReference] = useState('');
  const [returnedMemoAttachmentUrl, setReturnedMemoAttachmentUrl] = useState('');
  const [invoiceAttachmentUrl, setInvoiceAttachmentUrl] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const res = await apiClient.post(`/app/work-orders/${workOrderId}/external-services/${externalServiceId}/workshop-invoice`, {
        external_invoice_number: externalInvoiceNumber,
        invoice_date: invoiceDate,
        due_date: dueDate || undefined,
        currency,
        total_amount: totalAmount,
        subtotal: subtotal || undefined,
        tax_total: taxTotal || undefined,
        discount_total: discountTotal || undefined,
        partner_reference: partnerReference || undefined,
        returned_memo_attachment_url: returnedMemoAttachmentUrl || undefined,
        invoice_attachment_url: invoiceAttachmentUrl || undefined,
        notes: notes || undefined,
      });
      onRecorded(res.data.data.id);
    } catch (err) {
      const apiError = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Record Service Invoice" onClose={onClose} width={560}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>
        This records an invoice the service provider already issued externally — OptiFleet does not issue this invoice.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="External Invoice Number" errors={errors.external_invoice_number} required>
          <input value={externalInvoiceNumber} onChange={(e) => setExternalInvoiceNumber(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Partner Reference (optional)" errors={errors.partner_reference}>
          <input value={partnerReference} onChange={(e) => setPartnerReference(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Invoice Date" errors={errors.invoice_date} required>
          <input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Due Date (optional)" errors={errors.due_date}>
          <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Currency" errors={errors.currency}>
          <input value={currency} onChange={(e) => setCurrency(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Total Amount" errors={errors.total_amount} required>
          <NumericInput step="0.01" value={totalAmount} onChange={(e) => setTotalAmount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Subtotal (optional)" errors={errors.subtotal}>
          <NumericInput step="0.01" value={subtotal} onChange={(e) => setSubtotal(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Tax (optional)" errors={errors.tax_total}>
          <NumericInput step="0.01" value={taxTotal} onChange={(e) => setTaxTotal(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Discount (optional)" errors={errors.discount_total}>
          <NumericInput step="0.01" value={discountTotal} onChange={(e) => setDiscountTotal(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label="Returned Maintenance Memo attachment URL (optional)" errors={errors.returned_memo_attachment_url}>
        <input value={returnedMemoAttachmentUrl} onChange={(e) => setReturnedMemoAttachmentUrl(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Invoice attachment URL (optional)" errors={errors.invoice_attachment_url}>
        <input value={invoiceAttachmentUrl} onChange={(e) => setInvoiceAttachmentUrl(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Notes (optional)" errors={errors.notes}>
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !externalInvoiceNumber || !invoiceDate || !totalAmount} onClick={submit}>
          {submitting ? 'Recording…' : 'Record Service Invoice'}
        </button>
      </div>
    </Modal>
  );
}

function AuditTab({ workOrderId }: { workOrderId: string }) {
  const [logs, setLogs] = useState<AuditLogEntry[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get('/app/audit-logs', { params: { resource_type: 'WorkOrder', resource_id: workOrderId, per_page: 50 } })
      .then((res) => setLogs(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, [workOrderId]);

  if (error) return <ErrorState message={error} />;

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Audit Trail</h3>
      {logs.length === 0 && <EmptyState label="No audit entries for this Work Order." />}
      {logs.map((l) => (
        <div key={l.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div>
            <strong>{l.action}</strong> by {l.actor_name ?? 'system'} — {new Date(l.created_at).toLocaleString()}
          </div>
        </div>
      ))}
    </div>
  );
}
