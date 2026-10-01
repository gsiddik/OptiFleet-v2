import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { HistoryEventItem, VehicleAssignmentItem, VehicleDocumentItem, VehicleItem, VehicleTransferItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';

const TABS = ['Overview', 'Assignment', 'Transfer', 'Documents', 'History'] as const;
type Tab = (typeof TABS)[number];

export function VehicleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [vehicle, setVehicle] = useState<VehicleItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>('Overview');

  function load() {
    apiClient
      .get(`/app/vehicles/${id}`)
      .then((res) => setVehicle(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(vehicle?.id, vehicle?.registration_number);

  if (error && !vehicle) return <ErrorState message={error} />;
  if (!vehicle) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/vehicles" label="← Back to List" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {vehicle.registration_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({vehicle.brand} {vehicle.model})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={vehicle.status} />
          {hasPermission('vehicle.status.update') && <StatusChanger vehicle={vehicle} onChanged={load} />}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      <div style={{ display: 'flex', gap: 4, marginBottom: 16, borderBottom: '1px solid #e5e7eb' }}>
        {TABS.map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            style={{
              padding: '8px 16px',
              border: 'none',
              background: 'none',
              borderBottom: tab === t ? '2px solid #1d4ed8' : '2px solid transparent',
              color: tab === t ? '#1d4ed8' : '#6b7280',
              fontWeight: tab === t ? 600 : 400,
              cursor: 'pointer',
              fontSize: 14,
            }}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === 'Overview' && <OverviewTab vehicle={vehicle} onChanged={load} />}
      {tab === 'Assignment' && <AssignmentTab vehicle={vehicle} onChanged={load} />}
      {tab === 'Transfer' && <TransferTab vehicle={vehicle} onChanged={load} />}
      {tab === 'Documents' && <DocumentsTab vehicle={vehicle} />}
      {tab === 'History' && <HistoryTab vehicleId={vehicle.id} />}
    </div>
  );
}

function VehiclePhoto({ vehicle, onUploaded }: { vehicle: VehicleItem; onUploaded: () => void }) {
  const { hasPermission } = useAuth();
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const canEdit = hasPermission('vehicle.update');

  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    if (vehicle.photo_available) {
      apiClient.get(`/app/vehicles/${vehicle.id}/photo`, { responseType: 'blob' }).then((res) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(res.data);
        setPreviewUrl(objectUrl);
      });
    } else if (vehicle.photo_url) {
      setPreviewUrl(vehicle.photo_url);
    } else {
      setPreviewUrl(null);
    }

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [vehicle.id, vehicle.photo_available, vehicle.photo_url]);

  async function handleFile(file: File) {
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setError('Only JPG, JPEG, or PNG images are accepted.');
      return;
    }
    setUploading(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('file', file);
      await apiClient.post(`/app/vehicles/${vehicle.id}/photo`, form);
      onUploaded();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setUploading(false);
    }
  }

  return (
    <div style={{ marginBottom: 16 }}>
      <div
        role={canEdit ? 'button' : undefined}
        tabIndex={canEdit ? 0 : undefined}
        onClick={() => canEdit && inputRef.current?.click()}
        onKeyDown={(e) => {
          if (canEdit && (e.key === 'Enter' || e.key === ' ')) inputRef.current?.click();
        }}
        style={{
          width: 240,
          height: 160,
          border: previewUrl ? 'none' : '2px dashed #d1d5db',
          borderRadius: 8,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          cursor: canEdit ? 'pointer' : 'default',
          background: '#f9fafb',
          overflow: 'hidden',
          position: 'relative',
        }}
      >
        {previewUrl ? (
          <img
            src={previewUrl}
            alt={`${vehicle.registration_number} photo`}
            style={{ width: '100%', height: '100%', objectFit: 'cover' }}
          />
        ) : (
          <span style={{ color: '#9ca3af', fontSize: 13, textAlign: 'center', padding: 12 }}>
            {canEdit ? 'Click to upload photo (JPG/PNG)' : 'No photo'}
          </span>
        )}
        {uploading && (
          <div
            style={{
              position: 'absolute',
              inset: 0,
              background: 'rgba(255,255,255,0.8)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 13,
              color: '#374151',
            }}
          >
            Uploading…
          </div>
        )}
      </div>
      {canEdit && (
        <input
          ref={inputRef}
          type="file"
          accept="image/jpeg,image/png"
          style={{ display: 'none' }}
          onChange={(e) => {
            const f = e.target.files?.[0];
            e.target.value = '';
            if (f) handleFile(f);
          }}
        />
      )}
      {error && <div style={{ color: '#b91c1c', fontSize: 12, marginTop: 4 }}>{error}</div>}
    </div>
  );
}

function OverviewTab({ vehicle, onChanged }: { vehicle: VehicleItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [editing, setEditing] = useState(false);
  const rows: [string, string][] = [
    ['Branch', vehicle.branch?.name ?? '—'],
    ['Default Workshop', vehicle.default_workshop?.name ?? '—'],
    ['Category', vehicle.vehicle_category?.name ?? '—'],
    ['VIN', vehicle.vin ?? '—'],
    ['Chassis Number', vehicle.chassis_number ?? '—'],
    ['Engine Number', vehicle.engine_number ?? '—'],
    ['Year', vehicle.year ? String(vehicle.year) : '—'],
    ['Fuel Type', vehicle.fuel_type ?? '—'],
    ['Transmission', vehicle.transmission_type ?? '—'],
    ['Current Odometer', Number(vehicle.current_odometer).toLocaleString()],
    ['Engine Hour', vehicle.engine_hour ?? '—'],
    ['Operational Status', vehicle.operational_status],
    ['Color', vehicle.color ?? '—'],
    ['Doors', vehicle.doors != null ? String(vehicle.doors) : '—'],
    ['Seats', vehicle.seats != null ? String(vehicle.seats) : '—'],
    ['Dimensions (L×W×H mm)', vehicle.length_mm ? `${vehicle.length_mm} × ${vehicle.width_mm ?? '—'} × ${vehicle.height_mm ?? '—'}` : '—'],
    ['Fuel Tank Capacity (L)', vehicle.fuel_tank_capacity_liters ?? '—'],
    ['Engine Capacity (cc)', vehicle.engine_capacity_cc ?? '—'],
    ['Suspension', vehicle.suspension_type ?? '—'],
    ['Axles', vehicle.axle_count != null ? String(vehicle.axle_count) : '—'],
    ['Empty / Load Weight (kg)', vehicle.empty_weight_kg ? `${vehicle.empty_weight_kg} / ${vehicle.load_weight_kg ?? '—'}` : '—'],
    ['Wheels', vehicle.wheel_count != null ? String(vehicle.wheel_count) : '—'],
  ];

  return (
    <div className="card">
      <VehiclePhoto vehicle={vehicle} onUploaded={onChanged} />
      {hasPermission('vehicle.update') && (
        <div style={{ textAlign: 'right', marginBottom: 12 }}>
          <button className="btn-secondary" onClick={() => setEditing(true)}>
            Edit Specifications
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
      {editing && <EditVehicleModal vehicle={vehicle} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); onChanged(); }} />}
    </div>
  );
}

function EditVehicleModal({ vehicle, onClose, onSaved }: { vehicle: VehicleItem; onClose: () => void; onSaved: () => void }) {
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [categoryId, setCategoryId] = useState(vehicle.vehicle_category_id);
  const [year, setYear] = useState(vehicle.year != null ? String(vehicle.year) : '');
  const [fuelType, setFuelType] = useState(vehicle.fuel_type ?? '');
  const [transmissionType, setTransmissionType] = useState(vehicle.transmission_type ?? '');
  const [engineHour, setEngineHour] = useState(vehicle.engine_hour ?? '');
  const [color, setColor] = useState(vehicle.color ?? '');
  const [doors, setDoors] = useState(vehicle.doors != null ? String(vehicle.doors) : '');
  const [seats, setSeats] = useState(vehicle.seats != null ? String(vehicle.seats) : '');
  const [lengthMm, setLengthMm] = useState(vehicle.length_mm ?? '');
  const [widthMm, setWidthMm] = useState(vehicle.width_mm ?? '');
  const [heightMm, setHeightMm] = useState(vehicle.height_mm ?? '');
  const [fuelTank, setFuelTank] = useState(vehicle.fuel_tank_capacity_liters ?? '');
  const [engineCapacity, setEngineCapacity] = useState(vehicle.engine_capacity_cc ?? '');
  const [suspensionType, setSuspensionType] = useState(vehicle.suspension_type ?? '');
  const [axleCount, setAxleCount] = useState(vehicle.axle_count != null ? String(vehicle.axle_count) : '');
  const [emptyWeight, setEmptyWeight] = useState(vehicle.empty_weight_kg ?? '');
  const [loadWeight, setLoadWeight] = useState(vehicle.load_weight_kg ?? '');
  const [wheelCount, setWheelCount] = useState(vehicle.wheel_count != null ? String(vehicle.wheel_count) : '');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
  }, []);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.put(`/app/vehicles/${vehicle.id}`, {
        vehicle_category_id: categoryId,
        year: year || null,
        fuel_type: fuelType || null,
        transmission_type: transmissionType || null,
        engine_hour: engineHour || null,
        color: color || null,
        doors: doors || null,
        seats: seats || null,
        length_mm: lengthMm || null,
        width_mm: widthMm || null,
        height_mm: heightMm || null,
        fuel_tank_capacity_liters: fuelTank || null,
        engine_capacity_cc: engineCapacity || null,
        suspension_type: suspensionType || null,
        axle_count: axleCount || null,
        empty_weight_kg: emptyWeight || null,
        load_weight_kg: loadWeight || null,
        wheel_count: wheelCount || null,
      });
      onSaved();
    } catch (err) {
      const apiError = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Edit Vehicle Specifications" onClose={onClose} width={640}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Category" errors={errors.vehicle_category_id} required>
          <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
            <option value="">Select…</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Year" errors={errors.year}>
          <NumericInput value={year} onChange={(e) => setYear(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Fuel Type" errors={errors.fuel_type}>
          <input value={fuelType} onChange={(e) => setFuelType(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Transmission" errors={errors.transmission_type}>
          <input value={transmissionType} onChange={(e) => setTransmissionType(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Engine Hour" errors={errors.engine_hour}>
          <NumericInput step="0.01" value={engineHour} onChange={(e) => setEngineHour(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Color" errors={errors.color}>
          <input value={color} onChange={(e) => setColor(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Doors" errors={errors.doors}>
          <NumericInput value={doors} onChange={(e) => setDoors(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Seats" errors={errors.seats}>
          <NumericInput value={seats} onChange={(e) => setSeats(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Length (mm)" errors={errors.length_mm}>
          <NumericInput value={lengthMm} onChange={(e) => setLengthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Width (mm)" errors={errors.width_mm}>
          <NumericInput value={widthMm} onChange={(e) => setWidthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Height (mm)" errors={errors.height_mm}>
          <NumericInput value={heightMm} onChange={(e) => setHeightMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Fuel Tank (L)" errors={errors.fuel_tank_capacity_liters}>
          <NumericInput value={fuelTank} onChange={(e) => setFuelTank(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Engine Capacity (cc)" errors={errors.engine_capacity_cc}>
          <NumericInput value={engineCapacity} onChange={(e) => setEngineCapacity(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Suspension" errors={errors.suspension_type}>
          <input value={suspensionType} onChange={(e) => setSuspensionType(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Axles" errors={errors.axle_count}>
          <NumericInput value={axleCount} onChange={(e) => setAxleCount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Wheels" errors={errors.wheel_count}>
          <NumericInput value={wheelCount} onChange={(e) => setWheelCount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Empty Weight (kg)" errors={errors.empty_weight_kg}>
          <NumericInput value={emptyWeight} onChange={(e) => setEmptyWeight(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Load Weight (kg)" errors={errors.load_weight_kg}>
          <NumericInput value={loadWeight} onChange={(e) => setLoadWeight(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !categoryId} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}

function StatusChanger({ vehicle, onChanged }: { vehicle: VehicleItem; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [status, setStatus] = useState(vehicle.status);
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    try {
      await apiClient.post(`/app/vehicles/${vehicle.id}/status`, { status });
      onChanged();
      setOpen(false);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <button className="btn-secondary" onClick={() => setOpen(true)}>
        Change Status
      </button>
      {open && (
        <Modal open title="Change Vehicle Status" onClose={() => setOpen(false)}>
          <FormField label="Status">
            <select value={status} onChange={(e) => setStatus(e.target.value as VehicleItem['status'])} style={inputStyle}>
              {['ACTIVE', 'IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE', 'DISPOSED'].map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </FormField>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
            <button className="btn-secondary" onClick={() => setOpen(false)}>
              Cancel
            </button>
            <button className="btn-primary" disabled={submitting} onClick={submit}>
              Save
            </button>
          </div>
        </Modal>
      )}
    </>
  );
}

function AssignmentTab({ vehicle, onChanged }: { vehicle: VehicleItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [history, setHistory] = useState<VehicleAssignmentItem[]>([]);
  const [showAssign, setShowAssign] = useState(false);

  function load() {
    apiClient.get(`/app/vehicles/${vehicle.id}/assignments`).then((res) => setHistory(res.data.data));
  }

  useEffect(load, [vehicle.id]);

  return (
    <div className="card">
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <h3 style={{ margin: 0, fontSize: 15 }}>Assignment History</h3>
        {hasPermission('vehicle.assign') && (
          <button className="btn-secondary" onClick={() => setShowAssign(true)}>
            Reassign
          </button>
        )}
      </div>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
        <thead>
          <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
            <th style={{ padding: '6px 8px' }}>From</th>
            <th style={{ padding: '6px 8px' }}>To</th>
            <th style={{ padding: '6px 8px' }}>Effective From</th>
            <th style={{ padding: '6px 8px' }}>Effective Until</th>
          </tr>
        </thead>
        <tbody>
          {history.map((h) => (
            <tr key={h.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '6px 8px' }}>{h.from_branch?.name ?? '—'}</td>
              <td style={{ padding: '6px 8px' }}>{h.to_branch?.name ?? '—'}</td>
              <td style={{ padding: '6px 8px' }}>{h.effective_from}</td>
              <td style={{ padding: '6px 8px' }}>{h.effective_until ?? 'current'}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {history.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>No assignment history yet.</p>}

      {showAssign && (
        <AssignModal
          vehicle={vehicle}
          onClose={() => setShowAssign(false)}
          onAssigned={() => {
            setShowAssign(false);
            load();
            onChanged();
          }}
        />
      )}
    </div>
  );
}

function AssignModal({ vehicle, onClose, onAssigned }: { vehicle: VehicleItem; onClose: () => void; onAssigned: () => void }) {
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [branchId, setBranchId] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
  }, []);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post(`/app/vehicles/${vehicle.id}/assign`, { branch_id: branchId });
      onAssigned();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Reassign Vehicle" onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label="New Branch">
        <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !branchId} onClick={submit}>
          Assign
        </button>
      </div>
    </Modal>
  );
}

const TRANSFER_ACTIONS: Record<string, { label: string; action: string }[]> = {
  DRAFT: [{ label: 'Submit', action: 'submit' }, { label: 'Cancel', action: 'cancel' }],
  REQUESTED: [{ label: 'Approve', action: 'approve' }, { label: 'Reject', action: 'reject' }, { label: 'Cancel', action: 'cancel' }],
  APPROVED: [{ label: 'Dispatch', action: 'dispatch' }, { label: 'Cancel', action: 'cancel' }],
  IN_TRANSIT: [{ label: 'Receive', action: 'receive' }],
  RECEIVED: [{ label: 'Complete', action: 'complete' }],
};

function TransferTab({ vehicle, onChanged }: { vehicle: VehicleItem; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [transfers, setTransfers] = useState<VehicleTransferItem[]>([]);
  const [showCreate, setShowCreate] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function load() {
    apiClient.get('/app/vehicle-transfers', { params: { vehicle_id: vehicle.id } }).then((res) => setTransfers(res.data.data));
  }

  useEffect(load, [vehicle.id]);

  async function act(transferId: string, action: string) {
    setError(null);
    try {
      await apiClient.post(`/app/vehicle-transfers/${transferId}/${action}`);
      load();
      onChanged();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  const hasOpenTransfer = transfers.some((t) => !['COMPLETED', 'REJECTED', 'CANCELLED'].includes(t.status));

  return (
    <div className="card">
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <h3 style={{ margin: 0, fontSize: 15 }}>Transfers</h3>
        {hasPermission('vehicle.transfer') && !hasOpenTransfer && (
          <button className="btn-secondary" onClick={() => setShowCreate(true)}>
            + New Transfer
          </button>
        )}
      </div>
      {error && <ErrorState message={error} />}
      {transfers.map((t) => (
        <div key={t.id} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: 12, marginBottom: 10 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
            <span style={{ fontSize: 13 }}>
              {t.from_branch?.name ?? t.from_branch_id} → {t.to_branch?.name ?? t.to_branch_id}
            </span>
            <StatusBadge status={t.status} />
          </div>
          {hasPermission('vehicle.transfer') && (
            <div style={{ display: 'flex', gap: 8 }}>
              {(TRANSFER_ACTIONS[t.status] ?? []).map((a) => (
                <button key={a.action} className="btn-link" onClick={() => act(t.id, a.action)}>
                  {a.label}
                </button>
              ))}
            </div>
          )}
        </div>
      ))}
      {transfers.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>No transfers yet.</p>}

      {showCreate && (
        <CreateTransferModal
          vehicle={vehicle}
          onClose={() => setShowCreate(false)}
          onCreated={() => {
            setShowCreate(false);
            load();
          }}
        />
      )}
    </div>
  );
}

function CreateTransferModal({ vehicle, onClose, onCreated }: { vehicle: VehicleItem; onClose: () => void; onCreated: () => void }) {
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [toBranchId, setToBranchId] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data.filter((b: { id: string }) => b.id !== vehicle.branch_id)));
  }, [vehicle.branch_id]);

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post('/app/vehicle-transfers', { vehicle_id: vehicle.id, to_branch_id: toBranchId, reason });
      onCreated();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="New Vehicle Transfer" onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label="To Branch">
        <select value={toBranchId} onChange={(e) => setToBranchId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Reason">
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !toBranchId} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}

function DocumentsTab({ vehicle }: { vehicle: VehicleItem }) {
  const { hasPermission } = useAuth();
  const [documents, setDocuments] = useState<VehicleDocumentItem[]>([]);
  const [uploading, setUploading] = useState(false);
  const [docType, setDocType] = useState('REGISTRATION');
  const [error, setError] = useState<string | null>(null);

  function load() {
    apiClient.get(`/app/vehicles/${vehicle.id}/documents`).then((res) => setDocuments(res.data.data));
  }

  useEffect(load, [vehicle.id]);

  async function upload(file: File) {
    setUploading(true);
    setError(null);
    try {
      const form = new FormData();
      form.append('file', file);
      form.append('document_type', docType);
      await apiClient.post(`/app/vehicles/${vehicle.id}/documents`, form);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setUploading(false);
    }
  }

  async function fetchBlob(docId: string) {
    const res = await apiClient.get(`/app/vehicles/${vehicle.id}/documents/${docId}`, { responseType: 'blob' });
    const contentType = typeof res.headers['content-type'] === 'string' ? res.headers['content-type'] : undefined;
    return new Blob([res.data], { type: contentType });
  }

  async function download(docId: string, filename: string) {
    const url = URL.createObjectURL(await fetchBlob(docId));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function preview(docId: string) {
    const url = URL.createObjectURL(await fetchBlob(docId));
    // Opens in the browser's native viewer for previewable types; the browser
    // falls back to a download prompt for anything it cannot render inline.
    window.open(url, '_blank', 'noopener,noreferrer');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  }

  async function remove(docId: string) {
    if (!window.confirm('Delete this document? This cannot be undone.')) return;
    setError(null);
    try {
      await apiClient.delete(`/app/vehicles/${vehicle.id}/documents/${docId}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Documents</h3>
      {error && <ErrorState message={error} />}
      {documents.map((d) => (
        <div key={d.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <span>
            {d.document_type} — {d.original_filename} <span style={{ color: '#9ca3af' }}>({(d.size / 1024).toFixed(0)} KB)</span>
          </span>
          <span style={{ display: 'flex', gap: 12 }}>
            <button className="btn-link" onClick={() => preview(d.id)}>
              Preview
            </button>
            <button className="btn-link" onClick={() => download(d.id, d.original_filename)}>
              Download
            </button>
            {hasPermission('vehicle.update') && (
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => remove(d.id)}>
                Delete
              </button>
            )}
          </span>
        </div>
      ))}
      {documents.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>No documents uploaded.</p>}

      {hasPermission('vehicle.update') && (
        <div style={{ marginTop: 16, display: 'flex', gap: 8, alignItems: 'center' }}>
          <select value={docType} onChange={(e) => setDocType(e.target.value)} style={{ ...inputStyle, width: 200 }}>
            {['REGISTRATION', 'INSPECTION_CERTIFICATE', 'INSURANCE', 'PERMIT', 'WARRANTY', 'OTHER'].map((t) => (
              <option key={t} value={t}>
                {t}
              </option>
            ))}
          </select>
          <input
            type="file"
            accept=".jpg,.jpeg,.png,.webp,.pdf"
            disabled={uploading}
            onChange={(e) => {
              const f = e.target.files?.[0];
              if (f) upload(f);
            }}
          />
        </div>
      )}
    </div>
  );
}

function HistoryTab({ vehicleId }: { vehicleId: string }) {
  const [events, setEvents] = useState<HistoryEventItem[]>([]);

  useEffect(() => {
    apiClient.get(`/app/vehicles/${vehicleId}/history`).then((res) => setEvents(res.data.data));
  }, [vehicleId]);

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Maintenance History</h3>
      {events.map((e) => (
        <div key={`${e.type}-${e.id}`} style={{ display: 'flex', gap: 12, padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <span style={{ color: '#9ca3af', minWidth: 140 }}>{e.at ? new Date(e.at).toLocaleString() : '—'}</span>
          <span style={{ background: '#eff6ff', color: '#1d4ed8', padding: '2px 8px', borderRadius: 6, fontSize: 11, height: 'fit-content' }}>{e.type}</span>
          <span>{e.summary}</span>
        </div>
      ))}
      {events.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>No history yet.</p>}
    </div>
  );
}
