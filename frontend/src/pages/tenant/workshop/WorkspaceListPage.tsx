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
import type { WorkspaceItem } from '../../../types';

const TYPES = ['GENERAL_SERVICE_BAY', 'HEAVY_VEHICLE_BAY', 'INSPECTION_BAY', 'ELECTRICAL_BAY', 'TIRE_BAY', 'QC_BAY', 'WASHING_BAY', 'PARKING_LOT', 'HOLDING_AREA', 'OTHER'];
const STATUSES = ['', 'AVAILABLE', 'RESERVED', 'OCCUPIED', 'BLOCKED', 'UNDER_MAINTENANCE', 'INACTIVE'];

export function WorkspaceListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);
  const { data, loading, error } = useApiList<WorkspaceItem>('/app/workspaces', { status: status || undefined }, reloadKey);

  async function toggleBlock(ws: WorkspaceItem) {
    setBusyId(ws.id);
    try {
      await apiClient.post(`/app/workspaces/${ws.id}/${ws.status === 'BLOCKED' ? 'unblock' : 'block'}`);
      setReloadKey((k) => k + 1);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<WorkspaceItem>[] = [
    { key: 'code', header: 'Code', render: (w) => w.code },
    { key: 'name', header: 'Name', render: (w) => w.name },
    { key: 'workshop', header: 'Workshop', render: (w) => w.workshop?.name ?? '—' },
    { key: 'type', header: 'Type', render: (w) => w.workspace_type },
    { key: 'categories', header: 'Vehicle Categories', render: (w) => (w.vehicle_categories ?? []).map((c) => c.name).join(', ') || 'Any' },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
    {
      key: 'actions',
      header: '',
      render: (w) =>
        hasPermission('workspace.block') && ['AVAILABLE', 'BLOCKED'].includes(w.status) ? (
          <button className="btn-secondary" disabled={busyId === w.id} onClick={() => toggleBlock(w)}>
            {w.status === 'BLOCKED' ? 'Unblock' : 'Block'}
          </button>
        ) : null,
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workspaces / Service Bays</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('workspace.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Workspace
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No workspaces found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateWorkspaceModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateWorkspaceModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [workshopId, setWorkshopId] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [type, setType] = useState('GENERAL_SERVICE_BAY');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/workspaces', { workshop_id: workshopId, code, name, workspace_type: type });
      setCode('');
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
    <Modal open={open} title="New Workspace" onClose={onClose}>
      <FormField label="Workshop" errors={errors.workshop_id}>
        <select value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {workshops.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Code" errors={errors.code}>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Type" errors={errors.workspace_type}>
        <select value={type} onChange={(e) => setType(e.target.value)} style={inputStyle}>
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !workshopId || !code || !name} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
