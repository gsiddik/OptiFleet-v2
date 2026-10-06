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
import type { VehicleCategory, WorkspaceItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { statusLabel } from '../../../i18n/statusRegistry';

const TYPES = ['GENERAL_SERVICE_BAY', 'HEAVY_VEHICLE_BAY', 'INSPECTION_BAY', 'ELECTRICAL_BAY', 'TIRE_BAY', 'QC_BAY', 'WASHING_BAY', 'PARKING_LOT', 'HOLDING_AREA', 'OTHER'];
const STATUSES = ['', 'AVAILABLE', 'RESERVED', 'OCCUPIED', 'BLOCKED', 'UNDER_MAINTENANCE', 'INACTIVE'];

export function WorkspaceListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<WorkspaceItem | null>(null);
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
    { key: 'capacity', header: 'Capacity', render: (w) => w.capacity ?? '—' },
    { key: 'categories', header: 'Vehicle Categories', render: (w) => (w.vehicle_categories ?? []).map((c) => c.name).join(', ') || 'Any' },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
    {
      key: 'actions',
      header: '',
      render: (w) => (
        <div style={{ display: 'flex', gap: 6 }}>
          {hasPermission('workspace.manage') && (
            <button className="btn-secondary" onClick={() => setEditing(w)}>
              Edit
            </button>
          )}
          {hasPermission('workspace.block') && ['AVAILABLE', 'BLOCKED'].includes(w.status) && (
            <button className="btn-secondary" disabled={busyId === w.id} onClick={() => toggleBlock(w)}>
              {w.status === 'BLOCKED' ? 'Unblock' : 'Block'}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workspaces / Service Bays</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : 'All'}
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
      <EditWorkspaceModal workspace={editing} onClose={() => setEditing(null)} onSaved={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function VehicleCategoryChecklist({ categories, selected, onToggle }: { categories: VehicleCategory[]; selected: string[]; onToggle: (id: string) => void }) {
  return (
    <FormField label="Vehicle Categories" errors={undefined}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4, maxHeight: 140, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, padding: 8 }}>
        {categories.length === 0 && <span style={{ fontSize: 12, color: '#6b7280' }}>No vehicle categories available.</span>}
        {categories.map((c) => (
          <label key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
            <input type="checkbox" checked={selected.includes(c.id)} onChange={() => onToggle(c.id)} /> {c.name}
          </label>
        ))}
      </div>
      <div style={{ fontSize: 11, color: '#6b7280', marginTop: 4 }}>Leave all unchecked to allow any vehicle category.</div>
    </FormField>
  );
}

function CreateWorkspaceModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [categories, setCategories] = useState<VehicleCategory[]>([]);
  const [workshopId, setWorkshopId] = useState('');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [type, setType] = useState('GENERAL_SERVICE_BAY');
  const [capacity, setCapacity] = useState('');
  const [categoryIds, setCategoryIds] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
  }, [open]);

  function toggleCategory(id: string) {
    setCategoryIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const res = await apiClient.post('/app/workspaces', {
        workshop_id: workshopId, code, name, workspace_type: type,
        capacity: capacity || undefined,
      });
      if (categoryIds.length > 0) {
        await apiClient.post(`/app/workspaces/${res.data.data.id}/vehicle-categories`, { vehicle_category_ids: categoryIds });
      }
      setCode('');
      setName('');
      setCapacity('');
      setCategoryIds([]);
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
      <FormField label="Workshop" errors={errors.workshop_id} required>
        <select value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {workshops.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Code" errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Type" errors={errors.workspace_type} required>
        <select value={type} onChange={(e) => setType(e.target.value)} style={inputStyle}>
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Capacity" errors={errors.capacity}>
        <NumericInput min="1" value={capacity} onChange={(e) => setCapacity(e.target.value)} style={inputStyle} />
      </FormField>
      <VehicleCategoryChecklist categories={categories} selected={categoryIds} onToggle={toggleCategory} />
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

function EditWorkspaceModal({ workspace, onClose, onSaved }: { workspace: WorkspaceItem | null; onClose: () => void; onSaved: () => void }) {
  const [categories, setCategories] = useState<VehicleCategory[]>([]);
  const [name, setName] = useState('');
  const [type, setType] = useState('GENERAL_SERVICE_BAY');
  const [capacity, setCapacity] = useState('');
  const [categoryIds, setCategoryIds] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!workspace) return;
    setName(workspace.name);
    setType(workspace.workspace_type);
    setCapacity(workspace.capacity != null ? String(workspace.capacity) : '');
    setCategoryIds((workspace.vehicle_categories ?? []).map((c) => c.id));
    setErrors({});
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
  }, [workspace]);

  if (!workspace) return null;

  function toggleCategory(id: string) {
    setCategoryIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
  }

  async function submit() {
    if (!workspace) return;
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/workspaces/${workspace.id}`, {
        name, workspace_type: type, capacity: capacity || null,
      });
      await apiClient.post(`/app/workspaces/${workspace.id}/vehicle-categories`, { vehicle_category_ids: categoryIds });
      onSaved();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={!!workspace} title={`Edit Workspace — ${workspace.code}`} onClose={onClose}>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Type" errors={errors.workspace_type} required>
        <select value={type} onChange={(e) => setType(e.target.value)} style={inputStyle}>
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Capacity" errors={errors.capacity}>
        <NumericInput min="1" value={capacity} onChange={(e) => setCapacity(e.target.value)} style={inputStyle} />
      </FormField>
      <VehicleCategoryChecklist categories={categories} selected={categoryIds} onToggle={toggleCategory} />
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !name} onClick={submit}>
          Save
        </button>
      </div>
    </Modal>
  );
}
