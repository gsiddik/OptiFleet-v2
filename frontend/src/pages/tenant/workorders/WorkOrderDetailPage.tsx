import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type {
  AuditLogEntry,
  HistoryEventItem,
  PartnerItem,
  QcInspectionItem,
  WorkerItem,
  WorkOrderItem,
  WorkspaceItem,
} from '../../../types';

const TABS = [
  'Overview', 'Complaint', 'Diagnosis', 'Jobs', 'Mechanic',
  'Planned Parts', 'Workspace', 'QC', 'Road Test', 'External Services', 'Documents', 'History', 'Audit',
] as const;
type Tab = (typeof TABS)[number];

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'work_order.submit', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  SUBMITTED: [
    { action: 'approve', label: 'Approve', permission: 'work_order.approve', primary: true },
    { action: 'reject', label: 'Reject', permission: 'work_order.approve' },
    { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' },
  ],
  APPROVED: [{ action: 'assign', label: 'Assign', permission: 'work_order.assign', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  ASSIGNED: [{ action: 'schedule', label: 'Schedule', permission: 'work_order.schedule', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  SCHEDULED: [{ action: 'start', label: 'Start', permission: 'work_order.start', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  IN_PROGRESS: [
    { action: 'hold', label: 'Hold', permission: 'work_order.pause' },
    { action: 'waitForPart', label: 'Wait for Part', permission: 'work_order.pause' },
    { action: 'submitToQc', label: 'Submit to QC', permission: 'work_order.complete', primary: true },
    { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' },
  ],
  ON_HOLD: [{ action: 'resume', label: 'Resume', permission: 'work_order.pause', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
  WAITING_PART: [{ action: 'resume', label: 'Resume', permission: 'work_order.pause', primary: true }, { action: 'cancel', label: 'Cancel', permission: 'work_order.cancel' }],
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

  function load() {
    apiClient
      .get(`/app/work-orders/${id}`)
      .then((res) => setWo(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

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

  if (error && !wo) return <ErrorState message={error} />;
  if (!wo) return <LoadingState />;

  const actions = (LIFECYCLE[wo.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {wo.wo_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({wo.vehicle?.registration_number})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusBadge status={wo.status} />
          {hasPermission('work_order.view') && (
            <button className="btn-secondary" disabled={printing} onClick={printWorkOrder}>
              {printing ? 'Loading…' : 'Print'}
            </button>
          )}
          {actions.map((a) => (
            <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy} onClick={() => act(a.action)}>
              {a.label}
            </button>
          ))}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'flex', gap: 4, marginBottom: 16, borderBottom: '1px solid #e5e7eb', flexWrap: 'wrap' }}>
        {TABS.map((t) => (
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
      {tab === 'Complaint' && <ComplaintTab wo={wo} onChanged={load} />}
      {tab === 'Diagnosis' && <DiagnosisTab wo={wo} onChanged={load} />}
      {tab === 'Jobs' && <JobsTab wo={wo} onChanged={load} />}
      {tab === 'Mechanic' && <MechanicTab wo={wo} onChanged={load} />}
      {tab === 'Planned Parts' && <PlannedPartsTab wo={wo} onChanged={load} />}
      {tab === 'Workspace' && <WorkspaceTab wo={wo} />}
      {tab === 'QC' && <QcTab wo={wo} onChanged={load} />}
      {tab === 'Road Test' && <RoadTestTab wo={wo} onChanged={load} />}
      {tab === 'External Services' && <ExternalServicesTab wo={wo} onChanged={load} />}
      {tab === 'Documents' && <DocumentsTab />}
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

/** G-02: previously a Work Order had no way to record a pre-work cost estimate. */
const ESTIMABLE_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED'];

function OverviewTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [laborCost, setLaborCost] = useState('');
  const [partsCost, setPartsCost] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const rows: [string, string][] = [
    ['Vehicle', wo.vehicle?.registration_number ?? wo.vehicle_id],
    ['Branch', wo.branch?.name ?? '—'],
    ['Workshop', wo.workshop?.name ?? '—'],
    ['Maintenance Type', wo.maintenance_type],
    ['Priority', wo.priority],
    ['Current Odometer', wo.current_odometer ?? '—'],
    ['Estimated Labor Cost', wo.estimated_labor_cost ?? '—'],
    ['Estimated Parts Cost', wo.estimated_parts_cost ?? '—'],
    ['Estimated Total Cost', wo.estimated_total_cost ?? '—'],
    ['Target Start', wo.target_start_at ? new Date(wo.target_start_at).toLocaleString() : '—'],
    ['Target Completion', wo.target_completion_at ? new Date(wo.target_completion_at).toLocaleString() : '—'],
    ['Started At', wo.started_at ? new Date(wo.started_at).toLocaleString() : '—'],
    ['Completed At', wo.completed_at ? new Date(wo.completed_at).toLocaleString() : '—'],
    ['Closed At', wo.closed_at ? new Date(wo.closed_at).toLocaleString() : '—'],
    ['Result Summary', wo.result_summary ?? '—'],
  ];

  async function submitEstimate() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/estimate`, {
        estimated_labor_cost: laborCost || undefined,
        estimated_parts_cost: partsCost || undefined,
      });
      setLaborCost('');
      setPartsCost('');
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div>
      <div className="card">
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          {rows.map(([label, value]) => (
            <div key={label}>
              <div style={{ fontSize: 12, color: '#9ca3af' }}>{label}</div>
              <div style={{ fontSize: 14 }}>{value}</div>
            </div>
          ))}
        </div>
      </div>
      {ESTIMABLE_STATUSES.includes(wo.status) && hasPermission('work_order.estimate') && (
        <div className="card" style={{ marginTop: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Cost Estimate</h3>
          {error && <ErrorState message={error} />}
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
            <FormField label="Estimated Labor Cost">
              <input type="number" min="0" step="0.01" value={laborCost} onChange={(e) => setLaborCost(e.target.value)} style={inputStyle} />
            </FormField>
            <FormField label="Estimated Parts Cost">
              <input type="number" min="0" step="0.01" value={partsCost} onChange={(e) => setPartsCost(e.target.value)} style={inputStyle} />
            </FormField>
            <button className="btn-secondary" disabled={busy || (!laborCost && !partsCost)} onClick={submitEstimate}>
              Save Estimate
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function ComplaintTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [severity, setSeverity] = useState('MEDIUM');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);

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
      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Complaint</h3>
        <p style={{ fontSize: 13 }}>{wo.complaint || '—'}</p>
      </div>
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
          </div>
        ))}
        {hasPermission('diagnosis.manage') && (
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

function DiagnosisTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [findingId, setFindingId] = useState('');
  const [rootCause, setRootCause] = useState('');
  const [notes, setNotes] = useState('');
  const [diagnosisId, setDiagnosisId] = useState('');
  const [actionDescription, setActionDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const canManage = hasPermission('diagnosis.manage');

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

  return (
    <div>
      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Diagnoses</h3>
        {(wo.diagnoses ?? []).length === 0 && <EmptyState label="No diagnoses recorded." />}
        {(wo.diagnoses ?? []).map((d) => (
          <div key={d.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            <div>
              <strong>Root Cause:</strong> {d.root_cause}
            </div>
            {d.notes && <div style={{ color: '#6b7280' }}>{d.notes}</div>}
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
          <div key={c.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 10 }}>
            <StatusBadge status={c.status} />
            <span>{c.action_description}</span>
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
      {(wo.jobs ?? []).length === 0 && <EmptyState label="No jobs added." />}
      {(wo.jobs ?? []).map((j) => (
        <div key={j.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <div>
              <strong>{j.service_item ?? 'Job'}</strong> — {j.description}
              <div style={{ color: '#6b7280' }}>
                Est. {j.estimated_hours ?? '—'}h / Actual {j.actual_hours ?? '—'}h
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
      {canManage && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <input placeholder="Service item" value={serviceItem} onChange={(e) => setServiceItem(e.target.value)} style={{ ...inputStyle, width: 160 }} />
          <input placeholder="Job description" value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          <input placeholder="Est. hours" type="number" value={estimatedHours} onChange={(e) => setEstimatedHours(e.target.value)} style={{ ...inputStyle, width: 100 }} />
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
            </span>
            {canAssign && !a.unassigned_at && (
              <button className="btn-secondary" disabled={busy} onClick={() => unassign(a.id)}>
                Unassign
              </button>
            )}
          </div>
        ))}
        {canAssign && (
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

function PlannedPartsTab({ wo, onChanged }: { wo: WorkOrderItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [description, setDescription] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [notes, setNotes] = useState('');
  const [productId, setProductId] = useState('');
  const [products, setProducts] = useState<{ id: string; name: string }[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [returningPartId, setReturningPartId] = useState<string | null>(null);
  const [returnQty, setReturnQty] = useState('');
  const [returnCondition, setReturnCondition] = useState<'UNUSED_NEW' | 'USED_GOOD' | 'USED_FAULTY'>('UNUSED_NEW');
  const [returnReason, setReturnReason] = useState('');
  const [returnEvidence, setReturnEvidence] = useState('');
  const canManage = hasPermission('maintenance_job.manage');
  const canReserve = hasPermission('inventory.reserve');
  const canIssue = hasPermission('inventory.issue');
  const canReturn = hasPermission('inventory.return');

  useEffect(() => {
    apiClient.get('/app/products', { params: { per_page: 100 } }).then((res) => setProducts(res.data.data)).catch(() => setProducts([]));
  }, []);

  async function addPart() {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/planned-parts`, { description, quantity, notes: notes || undefined, product_id: productId || undefined });
      setDescription('');
      setNotes('');
      setProductId('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function partAction(partId: string, action: 'reserve' | 'issue' | 'return' | 'consume', body?: Record<string, unknown>) {
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

  function startReturn(partId: string) {
    setReturningPartId(partId);
    setReturnQty('');
    setReturnCondition('UNUSED_NEW');
    setReturnReason('');
    setReturnEvidence('');
  }

  async function submitReturn(partId: string) {
    if (!returnQty) return;
    await partAction(partId, 'return', {
      quantity: returnQty,
      condition: returnCondition,
      reason: returnReason || undefined,
      evidence: returnEvidence || undefined,
    });
    setReturningPartId(null);
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Planned Parts</h3>
      {error && <ErrorState message={error} />}
      {(wo.planned_parts ?? []).length === 0 && <EmptyState label="No planned parts." />}
      {(wo.planned_parts ?? []).map((p) => (
        <div key={p.id} style={{ padding: '10px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <span>
              {p.description} — planned {p.planned_quantity} {p.notes && <span style={{ color: '#6b7280' }}>({p.notes})</span>}
            </span>
            <StatusBadge status={p.status} />
          </div>
          {p.product_id && (
            <>
              <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
                reserved {p.reserved_quantity} · issued {p.issued_quantity} · consumed {p.consumed_quantity} · returned {p.returned_quantity}
                {p.unit_cost_at_issue && ` · unit cost ${p.unit_cost_at_issue} · total cost ${p.total_cost}`}
              </div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {canReserve && Number(p.reserved_quantity) < Number(p.planned_quantity) && !['CONSUMED', 'RETURNED', 'CANCELLED'].includes(p.status) && (
                  <button className="btn-secondary" disabled={busy} onClick={() => partAction(p.id, 'reserve')}>
                    Reserve
                  </button>
                )}
                {canIssue && Number(p.reserved_quantity) > 0 && (
                  <button className="btn-secondary" disabled={busy} onClick={() => partAction(p.id, 'issue')}>
                    Issue
                  </button>
                )}
                {canIssue && Number(p.issued_quantity) - Number(p.consumed_quantity) - Number(p.returned_quantity) > 0 && (
                  <button className="btn-secondary" disabled={busy} onClick={() => partAction(p.id, 'consume')}>
                    Consume
                  </button>
                )}
                {canReturn && Number(p.issued_quantity) - Number(p.returned_quantity) > 0 && returningPartId !== p.id && (
                  <button className="btn-secondary" disabled={busy} onClick={() => startReturn(p.id)}>
                    Return
                  </button>
                )}
              </div>
              {returningPartId === p.id && (
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8, alignItems: 'center', background: '#f9fafb', padding: 8, borderRadius: 6 }}>
                  <input
                    type="number"
                    step="0.01"
                    placeholder="Qty"
                    value={returnQty}
                    onChange={(e) => setReturnQty(e.target.value)}
                    style={{ ...inputStyle, width: 90 }}
                  />
                  <select
                    value={returnCondition}
                    onChange={(e) => setReturnCondition(e.target.value as 'UNUSED_NEW' | 'USED_GOOD' | 'USED_FAULTY')}
                    style={{ ...inputStyle, width: 160 }}
                  >
                    <option value="UNUSED_NEW">Unused / New</option>
                    <option value="USED_GOOD">Used — Good</option>
                    <option value="USED_FAULTY">Used — Faulty</option>
                  </select>
                  <input
                    placeholder="Reason (optional)"
                    value={returnReason}
                    onChange={(e) => setReturnReason(e.target.value)}
                    style={{ ...inputStyle, width: 180 }}
                  />
                  <input
                    placeholder="Evidence / photo URL (optional)"
                    value={returnEvidence}
                    onChange={(e) => setReturnEvidence(e.target.value)}
                    style={{ ...inputStyle, width: 200 }}
                  />
                  <button className="btn-secondary" disabled={busy || !returnQty} onClick={() => submitReturn(p.id)}>
                    Confirm return
                  </button>
                  <button className="btn-secondary" disabled={busy} onClick={() => setReturningPartId(null)}>
                    Cancel
                  </button>
                  {returnCondition !== 'UNUSED_NEW' && (
                    <span style={{ fontSize: 11, color: '#6b7280', width: '100%' }}>
                      Used-condition returns go to inspection — they do not restock available inventory until processed.
                    </span>
                  )}
                </div>
              )}
            </>
          )}
        </div>
      ))}
      {canManage && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <input placeholder="Description" value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          <select value={productId} onChange={(e) => setProductId(e.target.value)} style={{ ...inputStyle, width: 200 }}>
            <option value="">No catalog product</option>
            {products.map((prod) => (
              <option key={prod.id} value={prod.id}>
                {prod.name}
              </option>
            ))}
          </select>
          <input type="number" step="0.01" placeholder="Qty" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={{ ...inputStyle, width: 90 }} />
          <input placeholder="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} style={inputStyle} />
          <button className="btn-secondary" disabled={busy || !description} onClick={addPart}>
            Add
          </button>
        </div>
      )}
    </div>
  );
}

function WorkspaceTab({ wo }: { wo: WorkOrderItem }) {
  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Assigned Workspace</h3>
      {wo.workspace_id ? (
        <p style={{ fontSize: 13 }}>Workspace assigned via Schedule action (id: {wo.workspace_id}). See Workshop Operations &gt; Scheduler for the bay calendar.</p>
      ) : (
        <EmptyState label="No workspace assigned yet. Use the Schedule action to assign one." />
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
            <input placeholder="Release odometer (optional)" type="number" value={releaseOdometer} onChange={(e) => setReleaseOdometer(e.target.value)} style={{ ...inputStyle, width: 180 }} />
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
            {s.cost && <>Cost: {s.cost} &nbsp;</>}
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
                Record Workshop Invoice
              </button>
            )}
            {(s.status === 'BILLED' || s.status === 'PAID') && s.workshop_invoice_id && hasPermission('workshop_invoice.view') && (
              <Link className="btn-secondary" to={`/app/workshop-invoices/${s.workshop_invoice_id}`}>
                View Workshop Invoice
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
          <FormField label="Partner">
            <select value={partnerId} onChange={(e) => setPartnerId(e.target.value)} style={{ ...inputStyle, width: 200 }}>
              <option value="">Select partner</option>
              {partners.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} ({p.partner_type})
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Description">
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
            <input type="number" min="0" step="0.01" value={cost} onChange={(e) => setCost(e.target.value)} style={{ ...inputStyle, width: 100 }} />
          </FormField>
          <button className="btn-secondary" disabled={busy || !partnerId || !description} onClick={request}>
            Request
          </button>
        </div>
      )}
    </div>
  );
}

function DocumentsTab() {
  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Documents</h3>
      <EmptyState label="Work Order-level documents are not tracked in this phase — see the vehicle's Documents tab for vehicle-level records." />
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

/** R1: records an externally-issued Workshop Invoice against a COMPLETED Maintenance Memo — OptiFleet never issues one. */
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
    <Modal open title="Record Workshop Invoice" onClose={onClose} width={560}>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0 }}>
        This records an invoice the Workshop Partner already issued externally — OptiFleet does not issue this invoice.
      </p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="External Invoice Number" errors={errors.external_invoice_number}>
          <input value={externalInvoiceNumber} onChange={(e) => setExternalInvoiceNumber(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Partner Reference (optional)" errors={errors.partner_reference}>
          <input value={partnerReference} onChange={(e) => setPartnerReference(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Invoice Date" errors={errors.invoice_date}>
          <input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Due Date (optional)" errors={errors.due_date}>
          <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Currency" errors={errors.currency}>
          <input value={currency} onChange={(e) => setCurrency(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Total Amount" errors={errors.total_amount}>
          <input type="number" step="0.01" value={totalAmount} onChange={(e) => setTotalAmount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Subtotal (optional)" errors={errors.subtotal}>
          <input type="number" step="0.01" value={subtotal} onChange={(e) => setSubtotal(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Tax (optional)" errors={errors.tax_total}>
          <input type="number" step="0.01" value={taxTotal} onChange={(e) => setTaxTotal(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Discount (optional)" errors={errors.discount_total}>
          <input type="number" step="0.01" value={discountTotal} onChange={(e) => setDiscountTotal(e.target.value)} style={inputStyle} />
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
          {submitting ? 'Recording…' : 'Record Workshop Invoice'}
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
