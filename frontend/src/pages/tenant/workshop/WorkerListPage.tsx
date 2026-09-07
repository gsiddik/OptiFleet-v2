import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WorkerItem } from '../../../types';

const WORKER_TYPES = ['LEAD_MECHANIC', 'MECHANIC', 'TECHNICIAN', 'INSPECTOR', 'QC'];

export function WorkerListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<WorkerItem | null>(null);
  const { data, loading, error } = useApiList<WorkerItem>('/app/workers', { search }, reloadKey);

  const columns: Column<WorkerItem>[] = [
    { key: 'employee_code', header: 'Code', render: (w) => <button className="btn-link" onClick={() => setEditing(w)}>{w.employee_code}</button> },
    { key: 'name', header: 'Name', render: (w) => w.name },
    { key: 'worker_type', header: 'Type', render: (w) => w.worker_type },
    { key: 'branch', header: 'Branch', render: (w) => w.branch?.name ?? '—' },
    { key: 'workshop', header: 'Workshop', render: (w) => w.workshop?.name ?? '—' },
    { key: 'skills', header: 'Skills', render: (w) => (w.skills ?? []).map((s) => s.component_group?.name).filter(Boolean).join(', ') || '—' },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workers / Mechanics</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('worker.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Worker
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No workers found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateWorkerModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <WorkerDetailModal workerId={editing.id} onClose={() => setEditing(null)} onChanged={() => setReloadKey((k) => k + 1)} />
      )}
    </div>
  );
}

function CreateWorkerModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [employeeCode, setEmployeeCode] = useState('');
  const [name, setName] = useState('');
  const [branchId, setBranchId] = useState('');
  const [workshopId, setWorkshopId] = useState('');
  const [workerType, setWorkerType] = useState('MECHANIC');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/workers', {
        employee_code: employeeCode, name, branch_id: branchId, workshop_id: workshopId || null, worker_type: workerType,
      });
      setEmployeeCode('');
      setName('');
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="New Worker" onClose={onClose} width={520}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Employee Code" errors={errors.employee_code}>
          <input value={employeeCode} onChange={(e) => setEmployeeCode(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Name" errors={errors.name}>
          <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Branch" errors={errors.branch_id}>
          <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
            <option value="">Select…</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Workshop (optional)" errors={errors.workshop_id}>
          <select value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={inputStyle}>
            <option value="">None</option>
            {workshops.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Worker Type" errors={errors.worker_type}>
          <select value={workerType} onChange={(e) => setWorkerType(e.target.value)} style={inputStyle}>
            {WORKER_TYPES.map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !employeeCode || !name || !branchId} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}

function WorkerDetailModal({ workerId, onClose, onChanged }: { workerId: string; onClose: () => void; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [worker, setWorker] = useState<WorkerItem | null>(null);
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [componentGroupId, setComponentGroupId] = useState('');
  const [skillLevel, setSkillLevel] = useState('3');
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [assignBranchId, setAssignBranchId] = useState('');
  const [assignWorkshopId, setAssignWorkshopId] = useState('');
  const [busy, setBusy] = useState(false);

  function load() {
    apiClient.get(`/app/workers/${workerId}`).then((res) => setWorker(res.data.data));
  }

  useEffect(load, [workerId]);
  useEffect(() => {
    apiClient.get('/app/component-groups', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
  }, []);

  async function addSkill() {
    setBusy(true);
    try {
      await apiClient.post(`/app/workers/${workerId}/skills`, { component_group_id: componentGroupId, skill_level: Number(skillLevel) });
      setComponentGroupId('');
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function assign() {
    setBusy(true);
    try {
      await apiClient.post(`/app/workers/${workerId}/assign`, { branch_id: assignBranchId, workshop_id: assignWorkshopId || null });
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  if (!worker) return null;

  return (
    <Modal open title={`${worker.name} (${worker.employee_code})`} onClose={onClose} width={600}>
      <div style={{ marginBottom: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
        <StatusBadge status={worker.status} />
        <span style={{ fontSize: 13, color: '#6b7280' }}>
          {worker.worker_type} — {worker.branch?.name ?? '—'} / {worker.workshop?.name ?? 'No workshop'}
        </span>
      </div>

      <h4 style={{ fontSize: 13, marginBottom: 6 }}>Skills</h4>
      <table style={{ width: '100%', fontSize: 13, marginBottom: 12, borderCollapse: 'collapse' }}>
        <tbody>
          {(worker.skills ?? []).map((s) => (
            <tr key={s.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '6px 4px' }}>{s.component_group?.name ?? s.component_group_id}</td>
              <td style={{ padding: '6px 4px', color: '#6b7280' }}>Level {s.skill_level ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {hasPermission('worker.manage') && (
        <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
          <select value={componentGroupId} onChange={(e) => setComponentGroupId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
            <option value="">Select component group…</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
          <select value={skillLevel} onChange={(e) => setSkillLevel(e.target.value)} style={{ ...inputStyle, width: 100 }}>
            {[1, 2, 3, 4, 5].map((l) => (
              <option key={l} value={l}>
                Level {l}
              </option>
            ))}
          </select>
          <button className="btn-secondary" disabled={busy || !componentGroupId} onClick={addSkill}>
            Add Skill
          </button>
        </div>
      )}

      {hasPermission('worker.assign') && (
        <>
          <h4 style={{ fontSize: 13, marginBottom: 6 }}>Reassign</h4>
          <div style={{ display: 'flex', gap: 8 }}>
            <select value={assignBranchId} onChange={(e) => setAssignBranchId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
              <option value="">Select branch…</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
            <select value={assignWorkshopId} onChange={(e) => setAssignWorkshopId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
              <option value="">No workshop</option>
              {workshops.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
            <button className="btn-secondary" disabled={busy || !assignBranchId} onClick={assign}>
              Assign
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}
