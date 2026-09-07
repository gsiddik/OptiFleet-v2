import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type {
  AuditLogEntry,
  HistoryEventItem,
  QcInspectionItem,
  WorkerItem,
  WorkOrderItem,
  WorkspaceItem,
} from '../../../types';

const TABS = [
  'Overview', 'Complaint', 'Diagnosis', 'Jobs', 'Mechanic',
  'Planned Parts', 'Workspace', 'QC', 'Road Test', 'Documents', 'History', 'Audit',
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

  function load() {
    apiClient
      .get(`/app/work-orders/${id}`)
      .then((res) => setWo(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function act(action: string) {
    if (action === 'schedule') {
      setShowSchedule(true);
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

      {tab === 'Overview' && <OverviewTab wo={wo} />}
      {tab === 'Complaint' && <ComplaintTab wo={wo} onChanged={load} />}
      {tab === 'Diagnosis' && <DiagnosisTab wo={wo} onChanged={load} />}
      {tab === 'Jobs' && <JobsTab wo={wo} onChanged={load} />}
      {tab === 'Mechanic' && <MechanicTab wo={wo} onChanged={load} />}
      {tab === 'Planned Parts' && <PlannedPartsTab wo={wo} onChanged={load} />}
      {tab === 'Workspace' && <WorkspaceTab wo={wo} />}
      {tab === 'QC' && <QcTab wo={wo} onChanged={load} />}
      {tab === 'Road Test' && <RoadTestTab wo={wo} onChanged={load} />}
      {tab === 'Documents' && <DocumentsTab />}
      {tab === 'History' && <HistoryTab vehicleId={wo.vehicle_id} />}
      {tab === 'Audit' && <AuditTab workOrderId={wo.id} />}

      <ScheduleModal open={showSchedule} wo={wo} onClose={() => setShowSchedule(false)} onScheduled={load} />
    </div>
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

function OverviewTab({ wo }: { wo: WorkOrderItem }) {
  const rows: [string, string][] = [
    ['Vehicle', wo.vehicle?.registration_number ?? wo.vehicle_id],
    ['Branch', wo.branch?.name ?? '—'],
    ['Workshop', wo.workshop?.name ?? '—'],
    ['Maintenance Type', wo.maintenance_type],
    ['Priority', wo.priority],
    ['Current Odometer', wo.current_odometer ?? '—'],
    ['Target Start', wo.target_start_at ? new Date(wo.target_start_at).toLocaleString() : '—'],
    ['Target Completion', wo.target_completion_at ? new Date(wo.target_completion_at).toLocaleString() : '—'],
    ['Started At', wo.started_at ? new Date(wo.started_at).toLocaleString() : '—'],
    ['Completed At', wo.completed_at ? new Date(wo.completed_at).toLocaleString() : '—'],
    ['Closed At', wo.closed_at ? new Date(wo.closed_at).toLocaleString() : '—'],
  ];

  return (
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
            <span>{f.description}</span>
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
  const [busy, setBusy] = useState(false);
  const canManage = hasPermission('maintenance_job.manage');

  async function addPart() {
    setBusy(true);
    try {
      await apiClient.post(`/app/work-orders/${wo.id}/planned-parts`, { description, quantity, notes: notes || undefined });
      setDescription('');
      setNotes('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Planned Parts</h3>
      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: -6 }}>
        Planned parts only — no stock reservation or inventory movement in this phase.
      </p>
      {(wo.planned_parts ?? []).length === 0 && <EmptyState label="No planned parts." />}
      {(wo.planned_parts ?? []).map((p) => (
        <div key={p.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          {p.description} — qty {p.quantity} {p.notes && <span style={{ color: '#6b7280' }}>({p.notes})</span>}
        </div>
      ))}
      {canManage && (
        <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          <input placeholder="Description" value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
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
            <div key={f.id} style={{ paddingLeft: 8, color: '#6b7280' }}>
              [{f.severity}] {f.description}
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
