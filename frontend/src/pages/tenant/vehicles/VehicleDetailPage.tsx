import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useTabParam } from '../../../hooks/useTabParam';
import type { TabDef } from '../../../utils/tabs';
import { useWorkflowTransitions, workflowButtons } from '../../../hooks/useWorkflowTransitions';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { HistoryEventItem, VehicleAssignmentItem, VehicleDocumentItem, VehicleItem, VehicleTransferItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { VEHICLE_TYPES, resolveVehicleType, vehicleTypeOption } from '../tires/wheel-configuration/vehicleTypes';
import { VehicleWheelsConfigurationTab } from '../tires/wheel-configuration/VehicleWheelsConfigurationTab';
import { formatDate, formatDateTime } from '../../../utils/date';
import { DetailsWithImage, ImageContainer } from '../../../components/ImageContainer';
import { formatNumber } from '../../../utils/number';
import { labelText, t as tt, translatedRecord, withLabels } from '../../../i18n/i18n';

type Tab = 'overview' | 'assignment' | 'transfer' | 'documents' | 'wheels' | 'history';
// Stable ids drive state, ?tab= and permission gating; labels are display only.
// `wheels` keeps the existing ?tab=wheels deep links; legacy label links still resolve.
const TABS: readonly TabDef<Tab>[] = withLabels([
  { id: 'overview', label: 'Overview', labelKey: 'vehicle.fields.overview' },
  { id: 'assignment', label: 'Assignment', labelKey: 'vehicle.sections.assignment' },
  { id: 'transfer', label: 'Transfer', labelKey: 'vehicle.sections.transfer' },
  { id: 'documents', label: 'Documents', labelKey: 'vehicle.sections.documents' },
  { id: 'wheels', label: 'Wheels Configuration', labelKey: 'vehicle.fields.wheelsConfiguration' },
  { id: 'history', label: 'History', labelKey: 'vehicle.sections.history' },
]);
/** Tabs that need more than vehicle.view. */
const TAB_PERMISSION: Partial<Record<Tab, string>> = { wheels: 'tire.view' };

export function VehicleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [vehicle, setVehicle] = useState<VehicleItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  // ?tab=<id> opens a tab directly (e.g. ?tab=wheels from the Tire Operations "complete the tire data" links).
  const [tab, setTab] = useTabParam(TABS, 'overview');

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

  // A deep-linked tab the user may not see (e.g. ?tab=wheels without tire.view) falls back to Overview.
  const permittedTab = (t: Tab) => !TAB_PERMISSION[t] || hasPermission(TAB_PERMISSION[t]);
  const activeTab: Tab = permittedTab(tab) ? tab : 'overview';

  return (
    <div>
      <BackButton fallbackTo="/app/vehicles" label={tt('vehicle.actions.backToList')} />
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

      <div style={{ display: 'flex', gap: 4, marginBottom: 16, borderBottom: '1px solid #e5e7eb', overflowX: 'auto' }}>
        {TABS.filter(({ id: t }) => permittedTab(t)).map(({ id: t, ...tabDef }) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            style={{
              padding: '8px 16px',
              border: 'none',
              background: 'none',
              borderBottom: activeTab === t ? '2px solid #1d4ed8' : '2px solid transparent',
              color: activeTab === t ? '#1d4ed8' : '#6b7280',
              fontWeight: activeTab === t ? 600 : 400,
              cursor: 'pointer',
              fontSize: 14,
              whiteSpace: 'nowrap',
              flexShrink: 0,
            }}
          >
            {labelText(tabDef)}
          </button>
        ))}
      </div>

      {activeTab === 'overview' && <OverviewTab vehicle={vehicle} onChanged={load} />}
      {activeTab === 'assignment' && <AssignmentTab vehicle={vehicle} onChanged={load} />}
      {activeTab === 'transfer' && <TransferTab vehicle={vehicle} onChanged={load} />}
      {activeTab === 'documents' && <DocumentsTab vehicle={vehicle} />}
      {activeTab === 'wheels' && <VehicleWheelsConfigurationTab vehicleId={vehicle.id} />}
      {activeTab === 'history' && <HistoryTab vehicleId={vehicle.id} />}
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
      setError(tt('vehicle.errors.onlyJpgJpegPngImagesAccepted'));
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
    <div>
      <ImageContainer
        src={previewUrl}
        alt={tt('vehicle.tooltips.registrationNumberPhoto', { registration_number: vehicle.registration_number })}
        placeholder={canEdit ? tt('vehicle.placeholders.clickUploadPhotoJpgPng') : tt('vehicle.placeholders.noPhoto')}
        onActivate={canEdit ? () => inputRef.current?.click() : undefined}
      >
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
            {tt('common.actions.uploading')}
          </div>
        )}
      </ImageContainer>
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
    [tt('common.fields.branch'), vehicle.branch?.name ?? '—'],
    [tt('vehicle.fields.defaultWorkshop'), vehicle.default_workshop?.name ?? '—'],
    [tt('common.fields.category'), vehicle.vehicle_category?.name ?? '—'],
    [tt('tire.fields.vehicleType'), vehicleTypeOption(resolveVehicleType(vehicle.vehicle_type) ?? '')?.label ?? vehicle.vehicle_type ?? '—'],
    ['VIN', vehicle.vin ?? '—'],
    [tt('vehicle.fields.chassisNumber'), vehicle.chassis_number ?? '—'],
    [tt('vehicle.fields.engineNumber'), vehicle.engine_number ?? '—'],
    [tt('vehicle.fields.year'), vehicle.year ? String(vehicle.year) : '—'],
    [tt('vehicle.fields.fuelType'), vehicle.fuel_type ?? '—'],
    [tt('vehicle.fields.transmission'), vehicle.transmission_type ?? '—'],
    [tt('vehicle.fields.currentOdometer'), formatNumber(vehicle.current_odometer)],
    [tt('maintenance.fields.engineHour'), vehicle.engine_hour ?? '—'],
    [tt('vehicle.fields.operationalStatus'), vehicle.operational_status],
    [tt('vehicle.fields.color'), vehicle.color ?? '—'],
    [tt('vehicle.fields.doors'), vehicle.doors != null ? String(vehicle.doors) : '—'],
    [tt('vehicle.fields.seats'), vehicle.seats != null ? String(vehicle.seats) : '—'],
    [tt('inventory.fields.dimensionsLWHMm'), vehicle.length_mm ? `${vehicle.length_mm} × ${vehicle.width_mm ?? '—'} × ${vehicle.height_mm ?? '—'}` : '—'],
    [tt('vehicle.help.fuelTankCapacityL'), vehicle.fuel_tank_capacity_liters ?? '—'],
    [tt('vehicle.fields.engineCapacityCc'), vehicle.engine_capacity_cc ?? '—'],
    [tt('vehicle.fields.suspension'), vehicle.suspension_type ?? '—'],
    [tt('tire.fields.axles'), vehicle.axle_count != null ? String(vehicle.axle_count) : '—'],
    [tt('vehicle.help.emptyLoadWeightKg'), vehicle.empty_weight_kg ? `${vehicle.empty_weight_kg} / ${vehicle.load_weight_kg ?? '—'}` : '—'],
    [tt('vehicle.fields.wheelsInclSpare'), vehicle.wheel_count != null ? String(vehicle.wheel_count) : '—'],
  ];

  return (
    <div className="card">
      {hasPermission('vehicle.update') && (
        <div style={{ textAlign: 'right', marginBottom: 12 }}>
          <button className="btn-secondary" onClick={() => setEditing(true)}>
            {tt('vehicle.actions.editSpecifications')}
          </button>
        </div>
      )}
      <DetailsWithImage
        details={
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 12 }}>
            {rows.map(([label, value]) => (
              <div key={label}>
                <div style={{ fontSize: 12, color: '#9ca3af' }}>{label}</div>
                <div style={{ fontSize: 14 }}>{value}</div>
              </div>
            ))}
          </div>
        }
        image={<VehiclePhoto vehicle={vehicle} onUploaded={onChanged} />}
      />
      {editing && <EditVehicleModal vehicle={vehicle} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); onChanged(); }} />}
    </div>
  );
}

function EditVehicleModal({ vehicle, onClose, onSaved }: { vehicle: VehicleItem; onClose: () => void; onSaved: () => void }) {
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [categoryId, setCategoryId] = useState(vehicle.vehicle_category_id);
  // Stored as the Vehicle Type code; a legacy free-text value that maps to no type is kept as is.
  const [vehicleType, setVehicleType] = useState(resolveVehicleType(vehicle.vehicle_type) ?? vehicle.vehicle_type ?? '');
  const legacyVehicleType = vehicleType !== '' && !resolveVehicleType(vehicleType) ? vehicleType : null;
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
        vehicle_type: vehicleType || null,
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
    <Modal open title={tt('vehicle.modals.editVehicleSpecifications')} onClose={onClose} width={640}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={tt('common.fields.category')} errors={errors.vehicle_category_id} required>
          <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.select')}</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('tire.fields.vehicleType')} errors={errors.vehicle_type}>
          <select aria-label={tt('tire.fields.vehicleType')} value={vehicleType} onChange={(e) => setVehicleType(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.select')}</option>
            {VEHICLE_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {labelText(t)}
              </option>
            ))}
            {legacyVehicleType && <option value={legacyVehicleType}>{tt('vehicle.fields.valueLegacy', { value: legacyVehicleType })}</option>}
          </select>
        </FormField>
        <FormField label={tt('vehicle.fields.year')} errors={errors.year}>
          <NumericInput value={year} onChange={(e) => setYear(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.fuelType')} errors={errors.fuel_type}>
          <input value={fuelType} onChange={(e) => setFuelType(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.transmission')} errors={errors.transmission_type}>
          <input value={transmissionType} onChange={(e) => setTransmissionType(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('maintenance.fields.engineHour')} errors={errors.engine_hour}>
          <NumericInput step="0.01" value={engineHour} onChange={(e) => setEngineHour(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.color')} errors={errors.color}>
          <input value={color} onChange={(e) => setColor(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.doors')} errors={errors.doors}>
          <NumericInput value={doors} onChange={(e) => setDoors(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.seats')} errors={errors.seats}>
          <NumericInput value={seats} onChange={(e) => setSeats(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('inventory.fields.lengthMm')} errors={errors.length_mm}>
          <NumericInput value={lengthMm} onChange={(e) => setLengthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('inventory.fields.widthMm')} errors={errors.width_mm}>
          <NumericInput value={widthMm} onChange={(e) => setWidthMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('inventory.fields.heightMm')} errors={errors.height_mm}>
          <NumericInput value={heightMm} onChange={(e) => setHeightMm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.fuelTankL')} errors={errors.fuel_tank_capacity_liters}>
          <NumericInput value={fuelTank} onChange={(e) => setFuelTank(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.engineCapacityCc')} errors={errors.engine_capacity_cc}>
          <NumericInput value={engineCapacity} onChange={(e) => setEngineCapacity(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.suspension')} errors={errors.suspension_type}>
          <input value={suspensionType} onChange={(e) => setSuspensionType(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('tire.fields.axles')} errors={errors.axle_count}>
          <NumericInput value={axleCount} onChange={(e) => setAxleCount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.wheelsInclSpare')} errors={errors.wheel_count}>
          <NumericInput value={wheelCount} onChange={(e) => setWheelCount(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.emptyWeightKg')} errors={errors.empty_weight_kg}>
          <NumericInput value={emptyWeight} onChange={(e) => setEmptyWeight(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('vehicle.fields.loadWeightKg')} errors={errors.load_weight_kg}>
          <NumericInput value={loadWeight} onChange={(e) => setLoadWeight(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !categoryId} onClick={submit}>
          {submitting ? tt('common.actions.saving') : tt('common.actions.save')}
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
        {tt('vehicle.actions.changeStatus')}
      </button>
      {open && (
        <Modal open title={tt('vehicle.modals.changeVehicleStatus')} onClose={() => setOpen(false)}>
          <FormField label={tt('common.fields.status')}>
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
              {tt('common.actions.cancel')}
            </button>
            <button className="btn-primary" disabled={submitting} onClick={submit}>
              {tt('common.actions.save')}
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
        <h3 style={{ margin: 0, fontSize: 15 }}>{tt('vehicle.sections.assignmentHistory')}</h3>
        {hasPermission('vehicle.assign') && (
          <button className="btn-secondary" onClick={() => setShowAssign(true)}>
            {tt('vehicle.actions.reassign')}
          </button>
        )}
      </div>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
        <thead>
          <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
            <th style={{ padding: '6px 8px' }}>{tt('common.fields.from')}</th>
            <th style={{ padding: '6px 8px' }}>{tt('common.fields.to')}</th>
            <th style={{ padding: '6px 8px' }}>{tt('platform.pricing.fields.effectiveFrom')}</th>
            <th style={{ padding: '6px 8px' }}>{tt('vehicle.fields.effectiveUntil')}</th>
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
      {history.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>{tt('vehicle.empty.noAssignmentHistoryYet')}</p>}

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
    <Modal open title={tt('vehicle.modals.reassignVehicle')} onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label={tt('vehicle.fields.newBranch')}>
        <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !branchId} onClick={submit}>
          {tt('common.actions.assign')}
        </button>
      </div>
    </Modal>
  );
}

const TRANSFER_ACTIONS: Record<string, { label: string; labelKey?: string; action: string }[]> = {
  DRAFT: [{ label: 'Submit', labelKey: 'common.actions.submit', action: 'submit' }, { label: 'Cancel', labelKey: 'common.actions.cancelRecord', action: 'cancel' }],
  REQUESTED: [{ label: 'Approve', labelKey: 'common.actions.approve', action: 'approve' }, { label: 'Reject', labelKey: 'common.actions.reject', action: 'reject' }, { label: 'Cancel', labelKey: 'common.actions.cancelRecord', action: 'cancel' }],
  APPROVED: [{ label: 'Dispatch', labelKey: 'vehicle.actions.dispatch', action: 'dispatch' }, { label: 'Cancel', labelKey: 'common.actions.cancelRecord', action: 'cancel' }],
  IN_TRANSIT: [{ label: 'Receive', labelKey: 'tire.actions.receive', action: 'receive' }],
  RECEIVED: [{ label: 'Complete', labelKey: 'common.actions.complete', action: 'complete' }],
};

/** The module action that moves a vehicle transfer into each status (the workflow decides when it is offered). */
const TRANSFER_ACTIONS_BY_TARGET: Record<string, { label: string; labelKey?: string; action: string; permission: string }> = {
  REQUESTED: { label: 'Submit', labelKey: 'common.actions.submit', action: 'submit', permission: 'vehicle.transfer' },
  APPROVED: { label: 'Approve', labelKey: 'common.actions.approve', action: 'approve', permission: 'vehicle.transfer' },
  REJECTED: { label: 'Reject', labelKey: 'common.actions.reject', action: 'reject', permission: 'vehicle.transfer' },
  CANCELLED: { label: 'Cancel', labelKey: 'common.actions.cancelRecord', action: 'cancel', permission: 'vehicle.transfer' },
  IN_TRANSIT: { label: 'Dispatch', labelKey: 'vehicle.actions.dispatch', action: 'dispatch', permission: 'vehicle.transfer' },
  RECEIVED: { label: 'Receive', labelKey: 'tire.actions.receive', action: 'receive', permission: 'vehicle.transfer' },
  COMPLETED: { label: 'Complete', labelKey: 'common.actions.complete', action: 'complete', permission: 'vehicle.transfer' },
};

function TransferActions({ transfer, onAct }: { transfer: VehicleTransferItem; onAct: (action: string) => void }) {
  const available = useWorkflowTransitions('vehicle_transfer', transfer.id, transfer.status);
  const fallback = (TRANSFER_ACTIONS[transfer.status] ?? []).map((a) => ({ ...a, permission: 'vehicle.transfer' }));
  return (
    <>
      {workflowButtons(available, TRANSFER_ACTIONS_BY_TARGET, fallback).map((a) => (
        <button key={a.action} className="btn-link" onClick={() => onAct(a.action)}>
          {labelText(a)}
        </button>
      ))}
    </>
  );
}

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
        <h3 style={{ margin: 0, fontSize: 15 }}>{tt('vehicle.sections.transfers')}</h3>
        {hasPermission('vehicle.transfer') && !hasOpenTransfer && (
          <button className="btn-secondary" onClick={() => setShowCreate(true)}>
            {tt('inventory.actions.newTransfer')}
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
              <TransferActions transfer={t} onAct={(action) => act(t.id, action)} />
            </div>
          )}
        </div>
      ))}
      {transfers.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>{tt('vehicle.empty.noTransfersYet')}</p>}

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
    <Modal open title={tt('vehicle.modals.newVehicleTransfer')} onClose={onClose}>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      <FormField label={tt('vehicle.fields.toBranch')}>
        <select value={toBranchId} onChange={(e) => setToBranchId(e.target.value)} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('common.fields.reason')}>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !toBranchId} onClick={submit}>
          {tt('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}

/** Vehicle document type code → English label; shown through vehicle.documentType.<camelCase> (documentTypeLabel). */
const DOCUMENT_TYPE_LABEL: Record<string, string> = translatedRecord({
  REGISTRATION: 'Registration',
  INSPECTION_CERTIFICATE: 'Inspection Certificate',
  INSURANCE: 'Insurance',
  PERMIT: 'Permit',
  VEHICLE_TAX: 'Vehicle Tax',
  WARRANTY: 'Warranty',
  OTHER: 'Other',
}, { REGISTRATION: 'tire.fields.registration', INSPECTION_CERTIFICATE: 'vehicle.documentType.inspectionCertificate', INSURANCE: 'vehicle.documentType.insurance', PERMIT: 'vehicle.documentType.permit', VEHICLE_TAX: 'vehicle.documentType.vehicleTax', WARRANTY: 'vehicle.documentType.warranty', OTHER: 'tire.fields.other' });

function documentTypeLabel(code: string): string {
  return DOCUMENT_TYPE_LABEL[code] ?? code;
}

function DocumentsTab({ vehicle }: { vehicle: VehicleItem }) {
  const { hasPermission } = useAuth();
  const [documents, setDocuments] = useState<VehicleDocumentItem[]>([]);
  const [uploading, setUploading] = useState(false);
  const [docType, setDocType] = useState('REGISTRATION');
  const [hasExpiry, setHasExpiry] = useState(false);
  const [expiryDate, setExpiryDate] = useState('');
  const [needsExtension, setNeedsExtension] = useState(false);
  const [extensionDeadline, setExtensionDeadline] = useState('');
  const [fileKey, setFileKey] = useState(0);
  const [error, setError] = useState<string | null>(null);
  // Upload gating: nothing checked, or every date a checked box shows is filled (the API enforces the same).
  const missingDate = (hasExpiry && !expiryDate) || (needsExtension && !extensionDeadline);

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
      form.append('has_expiry', hasExpiry ? '1' : '0');
      form.append('needs_extension', needsExtension ? '1' : '0');
      if (hasExpiry) form.append('expiry_date', expiryDate);
      if (needsExtension) form.append('extension_deadline', extensionDeadline);
      await apiClient.post(`/app/vehicles/${vehicle.id}/documents`, form);
      setHasExpiry(false);
      setExpiryDate('');
      setNeedsExtension(false);
      setExtensionDeadline('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setUploading(false);
      setFileKey((k) => k + 1);
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
    if (!window.confirm(tt('vehicle.confirm.deleteDocumentCannotUndone'))) return;
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
      <h3 style={{ marginTop: 0, fontSize: 15 }}>{tt('vehicle.sections.documents')}</h3>
      {error && <ErrorState message={error} />}
      {documents.map((d) => (
        <div key={d.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <span>
            {documentTypeLabel(d.document_type)} — {d.original_filename} <span style={{ color: '#9ca3af' }}>({(d.size / 1024).toFixed(0)} KB)</span>
            {(d.expiry_date || d.extension_deadline) && (
              <span style={{ display: 'block', color: '#6b7280', fontSize: 12 }}>
                {d.expiry_date && <>{tt('vehicle.fields.expiryExpiryDate', { expiry_date: formatDate(d.expiry_date) })}</>}
                {d.expiry_date && d.extension_deadline && ' · '}
                {d.extension_deadline && <>{tt('vehicle.fields.extensionDeadlineExtensionDeadline', { extension_deadline: formatDate(d.extension_deadline) })}</>}
              </span>
            )}
          </span>
          <span style={{ display: 'flex', gap: 12 }}>
            <button className="btn-link" onClick={() => preview(d.id)}>
              {tt('configuration.actions.preview')}
            </button>
            <button className="btn-link" onClick={() => download(d.id, d.original_filename)}>
              {tt('common.actions.download')}
            </button>
            {hasPermission('vehicle.update') && (
              <button className="btn-link" style={{ color: '#b91c1c' }} onClick={() => remove(d.id)}>
                {tt('common.actions.delete')}
              </button>
            )}
          </span>
        </div>
      ))}
      {documents.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>{tt('vehicle.empty.noDocumentsUploaded')}</p>}

      {hasPermission('vehicle.update') && (
        <div data-document-upload style={{ marginTop: 16, display: 'grid', gap: 10 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <select aria-label={tt('configuration.fields.documentType')} value={docType} onChange={(e) => setDocType(e.target.value)} style={{ ...inputStyle, width: 220 }}>
              {Object.keys(DOCUMENT_TYPE_LABEL).map((value) => (
                <option key={value} value={value}>
                  {documentTypeLabel(value)}
                </option>
              ))}
            </select>
          </div>
          <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap', fontSize: 13 }}>
            <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <input type="checkbox" checked={hasExpiry} onChange={(e) => setHasExpiry(e.target.checked)} />
              {tt('vehicle.fields.haveAnExpiryDate')}
            </label>
            {hasExpiry && (
              <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                {tt('vehicle.fields.expiryDate')} <span style={{ color: '#b91c1c' }}>*</span>
                <input aria-label={tt('vehicle.fields.expiryDate')} type="date" value={expiryDate} onChange={(e) => setExpiryDate(e.target.value)} style={{ ...inputStyle, width: 170 }} />
              </label>
            )}
          </div>
          <div style={{ display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap', fontSize: 13 }}>
            <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <input type="checkbox" checked={needsExtension} onChange={(e) => setNeedsExtension(e.target.checked)} />
              {tt('vehicle.fields.needToBeExtended')}
            </label>
            {needsExtension && (
              <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                {tt('vehicle.fields.extensionDeadline')} <span style={{ color: '#b91c1c' }}>*</span>
                <input aria-label={tt('vehicle.fields.extensionDeadline')} type="date" value={extensionDeadline} onChange={(e) => setExtensionDeadline(e.target.value)} style={{ ...inputStyle, width: 170 }} />
              </label>
            )}
          </div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <input
              key={fileKey}
              aria-label={tt('vehicle.fields.documentFile')}
              type="file"
              accept=".jpg,.jpeg,.png,.webp,.pdf"
              disabled={uploading || missingDate}
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) upload(f);
              }}
            />
            {missingDate && <span style={{ fontSize: 12, color: '#b45309' }}>{tt('vehicle.help.fillDateEveryCheckedBoxBefore')}</span>}
          </div>
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
      <h3 style={{ marginTop: 0, fontSize: 15 }}>{tt('vehicle.sections.maintenanceHistory')}</h3>
      {events.map((e) => (
        <div key={`${e.type}-${e.id}`} style={{ display: 'flex', gap: 12, padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          <span style={{ color: '#9ca3af', minWidth: 140 }}>{e.at ? formatDateTime(e.at) : '—'}</span>
          <span style={{ background: '#eff6ff', color: '#1d4ed8', padding: '2px 8px', borderRadius: 6, fontSize: 11, height: 'fit-content' }}>{e.type}</span>
          <span>{e.summary}</span>
        </div>
      ))}
      {events.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>{tt('inspection.empty.noHistoryYet')}</p>}
    </div>
  );
}
