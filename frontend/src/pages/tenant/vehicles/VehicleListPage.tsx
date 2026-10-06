import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { ExcelImportModal } from '../../../components/ExcelImportModal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VehicleBrandItem, VehicleItem, VehicleModelItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatNumber } from '../../../utils/number';
import { monthNames } from '../../../i18n/locale';
import { t } from '../../../i18n/i18n';

const STATUSES = ['', 'ACTIVE', 'IN_MAINTENANCE', 'BREAKDOWN', 'OUT_OF_SERVICE', 'INACTIVE', 'DISPOSED'];


export function VehicleListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [showImport, setShowImport] = useState(false);
  const { data, loading, error } = useApiList<VehicleItem>('/app/vehicles', { search, status: status || undefined }, reloadKey);

  const columns: Column<VehicleItem>[] = [
    { key: 'registration_number', header: t('tire.fields.registration'), render: (v) => <Link to={`/app/vehicles/${v.id}`}>{v.registration_number}</Link> },
    { key: 'brand', header: t('common.fields.vehicle'), render: (v) => `${v.brand} ${v.model}` },
    { key: 'branch', header: t('common.fields.branch'), render: (v) => v.branch?.name ?? '—' },
    { key: 'category', header: t('common.fields.category'), render: (v) => v.vehicle_category?.name ?? '—' },
    { key: 'odometer', header: t('inspection.labels.odometer'), render: (v) => formatNumber(v.current_odometer) },
    { key: 'status', header: t('common.fields.status'), render: (v) => <StatusBadge status={v.status} /> },
    { key: 'operational_status', header: t('vehicle.fields.operational'), render: (v) => statusLabel(v.operational_status) },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('vehicle.titles.vehicles')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : t('common.actions.all')}
          </button>
        ))}
      </div>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('vehicle.create') ? (
            <span style={{ display: 'flex', gap: 8 }}>
              <button className="btn-secondary" onClick={() => setShowImport(true)} data-vehicle-import>
                {t('vehicle.actions.importVehicles')}
              </button>
              <button className="btn-primary" onClick={() => setShowCreate(true)}>
                {t('vehicle.actions.newVehicle')}
              </button>
            </span>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('vehicle.empty.noVehiclesFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {showImport && (
        <ExcelImportModal
          title={t('vehicle.modals.importVehicles')}
          intro={t('vehicle.help.importIntro')}
          templatePath="/app/vehicles/import-template"
          templateFilename="vehicle-import-template.xlsx"
          previewPath="/app/vehicles/import/preview"
          importPath="/app/vehicles/import"
          onClose={() => setShowImport(false)}
          onImported={() => setReloadKey((k) => k + 1)}
        />
      )}
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
  const [vehicleBrandId, setVehicleBrandId] = useState('');
  const [vehicleModelId, setVehicleModelId] = useState('');
  const [registrationNumber, setRegistrationNumber] = useState('');
  const [vin, setVin] = useState('');
  const [currentOdometer, setCurrentOdometer] = useState('0');
  const [purchaseMonth, setPurchaseMonth] = useState('');
  const [purchaseYear, setPurchaseYear] = useState('');
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
        vehicle_brand_id: vehicleBrandId,
        vehicle_model_id: vehicleModelId,
        registration_number: registrationNumber,
        vin: vin || null,
        current_odometer: currentOdometer,
        purchase_month: purchaseMonth || undefined,
        purchase_year: purchaseYear || undefined,
      });
      setVehicleBrandId('');
      setVehicleModelId('');
      setRegistrationNumber('');
      setVin('');
      setPurchaseMonth('');
      setPurchaseYear('');
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
    <Modal open={open} title={t('vehicle.modals.newVehicle')} onClose={onClose} width={520}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={t('common.fields.branch')} errors={errors.branch_id} required>
          <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
            <option value="">{t('common.fields.select')}</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('common.fields.category')} errors={errors.vehicle_category_id} required>
          <select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} style={inputStyle}>
            <option value="">{t('common.fields.select')}</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('common.fields.brand')} errors={errors.vehicle_brand_id} required>
          <select value={vehicleBrandId} onChange={(e) => setVehicleBrandId(e.target.value)} style={inputStyle}>
            <option value="">{t('common.fields.select')}</option>
            {vehicleBrands.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('common.fields.model')} errors={errors.vehicle_model_id} required>
          <select value={vehicleModelId} onChange={(e) => setVehicleModelId(e.target.value)} style={inputStyle} disabled={!vehicleBrandId}>
            <option value="">{t('common.fields.select')}</option>
            {vehicleModels.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('tire.fields.registrationNumber')} errors={errors.registration_number} required>
          <input value={registrationNumber} onChange={(e) => setRegistrationNumber(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('vehicle.fields.vinOptional')} errors={errors.vin}>
          <input value={vin} onChange={(e) => setVin(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('vehicle.fields.currentOdometer')} errors={errors.current_odometer}>
          <NumericInput value={currentOdometer} onChange={(e) => setCurrentOdometer(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={t('vehicle.fields.purchaseMonth')} errors={errors.purchase_month}>
          <select value={purchaseMonth} onChange={(e) => setPurchaseMonth(e.target.value)} style={inputStyle}>
            <option value="">{t('common.fields.select')}</option>
            {monthNames('long').map((m, i) => (
              <option key={m} value={i + 1}>
                {m}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('vehicle.fields.purchaseYear')} errors={errors.purchase_year}>
          <NumericInput min="1900" max={new Date().getFullYear() + 1} value={purchaseYear} onChange={(e) => setPurchaseYear(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button
          className="btn-primary"
          disabled={submitting || !branchId || !categoryId || !vehicleBrandId || !vehicleModelId || !registrationNumber}
          onClick={submit}
        >
          {submitting ? t('common.actions.creating') : t('vehicle.actions.createVehicle')}
        </button>
      </div>
    </Modal>
  );
}
