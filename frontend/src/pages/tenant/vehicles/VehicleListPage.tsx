import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VehicleBrandItem, VehicleItem, VehicleModelItem } from '../../../types';

const STATUSES = ['', 'ACTIVE', 'IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE', 'DISPOSED'];

export function VehicleListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<VehicleItem>('/app/vehicles', { search, status: status || undefined }, reloadKey);

  const columns: Column<VehicleItem>[] = [
    { key: 'registration_number', header: 'Registration', render: (v) => <Link to={`/app/vehicles/${v.id}`}>{v.registration_number}</Link> },
    { key: 'brand', header: 'Vehicle', render: (v) => `${v.brand} ${v.model}` },
    { key: 'branch', header: 'Branch', render: (v) => v.branch?.name ?? '—' },
    { key: 'category', header: 'Category', render: (v) => v.vehicle_category?.name ?? '—' },
    { key: 'odometer', header: 'Odometer', render: (v) => Number(v.current_odometer).toLocaleString() },
    { key: 'status', header: 'Status', render: (v) => <StatusBadge status={v.status} /> },
    { key: 'operational_status', header: 'Operational', render: (v) => v.operational_status },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vehicles</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('vehicle.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Vehicle
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vehicles found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateVehicleModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateVehicleModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [vehicleBrands, setVehicleBrands] = useState<VehicleBrandItem[]>([]);
  const [vehicleModels, setVehicleModels] = useState<VehicleModelItem[]>([]);
  const [branchId, setBranchId] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [brand, setBrand] = useState('');
  const [model, setModel] = useState('');
  const [vehicleBrandId, setVehicleBrandId] = useState('');
  const [vehicleModelId, setVehicleModelId] = useState('');
  const [registrationNumber, setRegistrationNumber] = useState('');
  const [vin, setVin] = useState('');
  const [vehicleType, setVehicleType] = useState('');
  const [currentOdometer, setCurrentOdometer] = useState('0');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
    apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
    apiClient.get('/app/vehicle-brands', { params: { per_page: 100 } }).then((res) => setVehicleBrands(res.data.data)).catch(() => setVehicleBrands([]));
  }, [open]);

  useEffect(() => {
    if (!vehicleBrandId) {
      setVehicleModels([]);
      setVehicleModelId('');
      return;
    }
    apiClient
      .get('/app/vehicle-models', { params: { vehicle_brand_id: vehicleBrandId, per_page: 100 } })
      .then((res) => setVehicleModels(res.data.data))
      .catch(() => setVehicleModels([]));
  }, [vehicleBrandId]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/vehicles', {
        branch_id: branchId,
        vehicle_category_id: categoryId,
        brand,
        model,
        vehicle_brand_id: vehicleBrandId || undefined,
        vehicle_model_id: vehicleModelId || undefined,
        registration_number: registrationNumber,
        vin: vin || null,
        vehicle_type: vehicleType || null,
        current_odometer: currentOdometer,
      });
      setBrand('');
      setModel('');
      setVehicleBrandId('');
      setVehicleModelId('');
      setRegistrationNumber('');
      setVin('');
      setVehicleType('');
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
    <Modal open={open} title="New Vehicle" onClose={onClose} width={520}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Branch" errors={errors.branch_id} required>
          <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
            <option value="">Select…</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
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
        <FormField label="Brand" errors={errors.brand} required>
          <input value={brand} onChange={(e) => setBrand(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Model" errors={errors.model} required>
          <input value={model} onChange={(e) => setModel(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Brand (master data, optional)" errors={errors.vehicle_brand_id}>
          <select value={vehicleBrandId} onChange={(e) => setVehicleBrandId(e.target.value)} style={inputStyle}>
            <option value="">None</option>
            {vehicleBrands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Model (master data, optional)" errors={errors.vehicle_model_id}>
          <select value={vehicleModelId} onChange={(e) => setVehicleModelId(e.target.value)} style={inputStyle} disabled={!vehicleBrandId}>
            <option value="">None</option>
            {vehicleModels.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label="Registration Number" errors={errors.registration_number} required>
          <input value={registrationNumber} onChange={(e) => setRegistrationNumber(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="VIN (optional)" errors={errors.vin}>
          <input value={vin} onChange={(e) => setVin(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Vehicle Type (optional)" errors={errors.vehicle_type}>
          <input value={vehicleType} onChange={(e) => setVehicleType(e.target.value)} style={inputStyle} placeholder="e.g. Truck, Pickup, Bus" />
        </FormField>
        <FormField label="Current Odometer" errors={errors.current_odometer}>
          <input type="number" value={currentOdometer} onChange={(e) => setCurrentOdometer(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !branchId || !categoryId} onClick={submit}>
          {submitting ? 'Creating…' : 'Create Vehicle'}
        </button>
      </div>
    </Modal>
  );
}
