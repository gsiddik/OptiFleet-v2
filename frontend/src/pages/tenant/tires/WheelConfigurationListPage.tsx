import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VehicleCategory, WheelConfigurationItem } from '../../../types';

export function WheelConfigurationListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<WheelConfigurationItem>('/app/wheel-configurations', {}, reloadKey);

  const columns: Column<WheelConfigurationItem>[] = [
    { key: 'category', header: 'Vehicle Category', render: (w) => w.vehicle_category?.name ?? w.vehicle_category_id },
    { key: 'position', header: 'Position Code', render: (w) => w.position_code },
    { key: 'label', header: 'Label', render: (w) => w.label },
    { key: 'axle', header: 'Axle #', render: (w) => w.axle_number ?? '—' },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Wheel Configuration</h1>
      <Toolbar
        actions={
          hasPermission('tire.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + Add Position
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No wheel positions configured." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
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
      <FormField label="Vehicle Category" errors={errors.vehicle_category_id}>
        <select value={vehicleCategoryId} onChange={(e) => setVehicleCategoryId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {categories.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Position Code" errors={errors.position_code}>
        <input value={positionCode} onChange={(e) => setPositionCode(e.target.value)} placeholder="e.g. FRONT_LEFT" style={inputStyle} />
      </FormField>
      <FormField label="Label" errors={errors.label}>
        <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Front Left" style={inputStyle} />
      </FormField>
      <FormField label="Axle Number" errors={errors.axle_number}>
        <input type="number" value={axleNumber} onChange={(e) => setAxleNumber(e.target.value)} style={inputStyle} />
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
