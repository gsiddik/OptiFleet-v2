import { useEffect, useMemo, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VehicleCategory, WheelConfigurationItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';

interface ConfigSummary {
  id: string;
  vehicleCategoryId: string;
  categoryName: string;
  configCode: string;
  totalAxles: number;
  totalWheels: number;
}

/** Computed display-only summary — grouped from the existing position rows, not a stored field. */
function summarize(rows: WheelConfigurationItem[]): ConfigSummary[] {
  const byCategory = new Map<string, WheelConfigurationItem[]>();
  for (const row of rows) {
    const list = byCategory.get(row.vehicle_category_id) ?? [];
    list.push(row);
    byCategory.set(row.vehicle_category_id, list);
  }
  return Array.from(byCategory.entries()).map(([vehicleCategoryId, positions]) => {
    const axles = new Set(positions.map((p) => p.axle_number).filter((n): n is number => n != null));
    return {
      id: vehicleCategoryId,
      vehicleCategoryId,
      categoryName: positions[0].vehicle_category?.name ?? vehicleCategoryId,
      configCode: positions[0].vehicle_category?.code ?? '—',
      totalAxles: axles.size,
      totalWheels: positions.length,
    };
  });
}

export function WheelConfigurationListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [categories, setCategories] = useState<VehicleCategory[]>([]);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<WheelConfigurationItem | null>(null);
  const [deleting, setDeleting] = useState<WheelConfigurationItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const { data, loading, error } = useApiList<WheelConfigurationItem>(
    '/app/wheel-configurations',
    { search: search || undefined, vehicle_category_id: categoryFilter || undefined },
    reloadKey,
  );

  useEffect(() => {
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data)).catch(() => setCategories([]));
  }, []);

  const summaries = useMemo(() => summarize(data), [data]);

  async function confirmDelete() {
    if (!deleting) return;
    setDeleteError(null);
    try {
      await apiClient.delete(`/app/wheel-configurations/${deleting.id}`);
      setDeleting(null);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setDeleteError(extractApiError(err).message);
    }
  }

  const summaryColumns: Column<ConfigSummary>[] = [
    { key: 'code', header: 'Config Code', render: (s) => s.configCode },
    { key: 'category', header: 'Vehicle Category', render: (s) => s.categoryName },
    { key: 'axles', header: 'Total Axles', render: (s) => s.totalAxles },
    { key: 'wheels', header: 'Total Wheels', render: (s) => s.totalWheels },
  ];

  const columns: Column<WheelConfigurationItem>[] = [
    { key: 'category', header: 'Vehicle Category', render: (w) => w.vehicle_category?.name ?? w.vehicle_category_id },
    { key: 'position', header: 'Position Code', render: (w) => w.position_code },
    { key: 'label', header: 'Label', render: (w) => w.label },
    { key: 'axle', header: 'Axle #', render: (w) => w.axle_number ?? '—' },
    {
      key: 'actions',
      header: '',
      render: (w) =>
        w.tenant_id && hasPermission('tire.manage') ? (
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn-link" onClick={() => setEditing(w)}>
              Edit
            </button>
            <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => setDeleting(w)}>
              Delete
            </button>
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#9ca3af' }}>{w.tenant_id ? '' : 'Platform default'}</span>
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Wheel Configuration</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('tire.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + Add Position
            </button>
          ) : null
        }
      >
        <select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)} style={{ ...inputStyle, maxWidth: 220 }}>
          <option value="">All vehicle categories</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </Toolbar>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No wheel positions configured." />}
      {!error && !loading && summaries.length > 0 && (
        <div style={{ marginBottom: 20 }}>
          <h3 style={{ fontSize: 15 }}>Configuration Summary</h3>
          <Table columns={summaryColumns} rows={summaries} />
        </div>
      )}
      {!error && !loading && data.length > 0 && (
        <>
          <h3 style={{ fontSize: 15 }}>Wheel Positions</h3>
          <Table columns={columns} rows={data} />
        </>
      )}

      <CreateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <EditModal
          config={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title="Delete Wheel Position"
        message={deleteError ?? `Delete position "${deleting?.label}"? This cannot be undone.`}
        confirmLabel="Delete"
        onCancel={() => {
          setDeleting(null);
          setDeleteError(null);
        }}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

function CreateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [categories, setCategories] = useState<VehicleCategory[]>([]);
  const [vehicleCategoryId, setVehicleCategoryId] = useState('');
  const [positionCode, setPositionCode] = useState('');
  const [label, setLabel] = useState('');
  const [axleNumber, setAxleNumber] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data)).catch(() => setCategories([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/wheel-configurations', {
        vehicle_category_id: vehicleCategoryId, position_code: positionCode, label, axle_number: axleNumber || undefined,
      });
      setPositionCode('');
      setLabel('');
      setAxleNumber('');
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
    <Modal open={open} title="Add Wheel Position" onClose={onClose}>
      <FormField label="Vehicle Category" errors={errors.vehicle_category_id} required>
        <select value={vehicleCategoryId} onChange={(e) => setVehicleCategoryId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Position Code" errors={errors.position_code} required>
        <input value={positionCode} onChange={(e) => setPositionCode(e.target.value)} placeholder="e.g. FRONT_LEFT" style={inputStyle} />
      </FormField>
      <FormField label="Label" errors={errors.label} required>
        <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Front Left" style={inputStyle} />
      </FormField>
      <FormField label="Axle Number" errors={errors.axle_number}>
        <NumericInput value={axleNumber} onChange={(e) => setAxleNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleCategoryId || !positionCode || !label} onClick={submit}>
          Save
        </button>
      </div>
    </Modal>
  );
}

function EditModal({ config, onClose, onSaved }: { config: WheelConfigurationItem; onClose: () => void; onSaved: () => void }) {
  const [positionCode, setPositionCode] = useState(config.position_code);
  const [label, setLabel] = useState(config.label);
  const [axleNumber, setAxleNumber] = useState(config.axle_number != null ? String(config.axle_number) : '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/wheel-configurations/${config.id}`, {
        position_code: positionCode, label, axle_number: axleNumber || null,
      });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Edit Wheel Position" onClose={onClose}>
      <FormField label="Position Code" errors={errors.position_code}>
        <input value={positionCode} onChange={(e) => setPositionCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Label" errors={errors.label}>
        <input value={label} onChange={(e) => setLabel(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Axle Number" errors={errors.axle_number}>
        <NumericInput value={axleNumber} onChange={(e) => setAxleNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !positionCode || !label} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
