import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WorkOrderItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';

const STATUSES = [
  '', 'DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS',
  'ON_HOLD', 'WAITING_PART', 'EXTERNAL', 'QC_PENDING', 'REWORK', 'COMPLETED', 'CLOSED', 'REJECTED', 'CANCELLED',
];

export function WorkOrderListPage() {
  const { hasPermission } = useAuth();
  const [searchParams] = useSearchParams();
  const [status, setStatus] = useState(searchParams.get('status') ?? '');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<WorkOrderItem>('/app/work-orders', { status: status || undefined }, reloadKey);

  const columns: Column<WorkOrderItem>[] = [
    { key: 'wo_number', header: 'WO Number', render: (w) => <Link to={`/app/work-orders/${w.id}`}>{w.wo_number}</Link> },
    { key: 'vehicle', header: 'Vehicle', render: (w) => w.vehicle?.registration_number ?? w.vehicle_id },
    { key: 'workshop', header: 'Workshop', render: (w) => w.workshop?.name ?? '—' },
    { key: 'type', header: 'Type', render: (w) => w.maintenance_type },
    { key: 'priority', header: 'Priority', render: (w) => w.priority },
    { key: 'status', header: 'Status', render: (w) => <StatusBadge status={w.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Work Orders</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('work_order.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Work Order
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No work orders found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateWorkOrderModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateWorkOrderModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [vehicles, setVehicles] = useState<{ id: string; registration_number: string; current_odometer: string | null; engine_hour: string | null }[]>([]);
  const [vehicleId, setVehicleId] = useState('');
  const [maintenanceType, setMaintenanceType] = useState('CORRECTIVE');
  const [priority, setPriority] = useState('MEDIUM');
  const [complaint, setComplaint] = useState('');
  const [currentOdometer, setCurrentOdometer] = useState('');
  const [engineHour, setEngineHour] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const selectedVehicle = vehicles.find((v) => v.id === vehicleId);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/work-orders', {
        vehicle_id: vehicleId,
        maintenance_type: maintenanceType,
        priority,
        complaint: complaint || null,
        current_odometer: currentOdometer === '' ? null : Number(currentOdometer),
        engine_hour: engineHour === '' ? null : Number(engineHour),
      });
      setComplaint('');
      setCurrentOdometer('');
      setEngineHour('');
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
    <Modal open={open} title="New Work Order" onClose={onClose}>
      <FormField label="Vehicle" errors={errors.vehicle_id} required>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Maintenance Type" errors={errors.maintenance_type} required>
        <select value={maintenanceType} onChange={(e) => setMaintenanceType(e.target.value)} style={inputStyle}>
          {['CORRECTIVE', 'BREAKDOWN'].map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Priority" errors={errors.priority}>
        <select value={priority} onChange={(e) => setPriority(e.target.value)} style={inputStyle}>
          {['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Complaint (optional)" errors={errors.complaint}>
        <textarea value={complaint} onChange={(e) => setComplaint(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Current KM" required errors={errors.current_odometer}>
          <div style={{ fontSize: 11, color: '#9ca3af', marginBottom: 2 }}>Last Odometer: {selectedVehicle?.current_odometer ?? '—'}</div>
          <NumericInput min="0" value={currentOdometer} onChange={(e) => setCurrentOdometer(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Current HM (optional)" errors={errors.engine_hour}>
          <div style={{ fontSize: 11, color: '#9ca3af', marginBottom: 2 }}>Last HM: {selectedVehicle?.engine_hour ?? '—'}</div>
          <NumericInput min="0" value={engineHour} onChange={(e) => setEngineHour(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId || !currentOdometer} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
