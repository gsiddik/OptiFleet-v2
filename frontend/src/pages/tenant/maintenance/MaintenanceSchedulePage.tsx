import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { MaintenancePackageItemType, MaintenanceScheduleItem, VehicleItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatNumber } from '../../../utils/number';
import { t } from '../../../i18n/i18n';

const STATUSES = ['', 'UPCOMING', 'DUE_SOON', 'DUE', 'OVERDUE', 'SCHEDULED', 'COMPLETED'];

const PERIOD_BY_LABELS: Record<string, string> = {
  CALENDAR_DAY: 'days',
  MONTH: 'months',
};

export function MaintenanceSchedulePage() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error: listError } = useApiList<MaintenanceScheduleItem>('/app/maintenance-schedules', { status: status || undefined }, reloadKey);

  async function refresh(schedule: MaintenanceScheduleItem) {
    setBusyId(schedule.id);
    setError(null);
    try {
      await apiClient.post(`/app/maintenance-schedules/${schedule.id}/refresh`);
      setReloadKey((k) => k + 1);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  /** G-01: previously a due schedule had no path to a Work Order at all. */
  async function convertToWorkOrder(schedule: MaintenanceScheduleItem) {
    setBusyId(schedule.id);
    setError(null);
    try {
      const res = await apiClient.post(`/app/maintenance-schedules/${schedule.id}/work-order`);
      navigate(`/app/work-orders/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  /** Section 12: Due/Overdue/Scheduled -> Maintenance Request, idempotent on the backend. */
  async function convertToMaintenanceRequest(schedule: MaintenanceScheduleItem) {
    setBusyId(schedule.id);
    setError(null);
    try {
      const res = await apiClient.post(`/app/maintenance-schedules/${schedule.id}/maintenance-request`);
      navigate(`/app/maintenance-requests/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<MaintenanceScheduleItem>[] = [
    { key: 'vehicle', header: t('common.fields.vehicle'), render: (s) => <Link to={`/app/vehicles/${s.vehicle_id}`}>{s.vehicle?.registration_number ?? s.vehicle_id}</Link> },
    { key: 'package', header: t('maintenance.fields.package'), render: (s) => s.package?.name ?? '—' },
    { key: 'schedule_start_date', header: t('maintenance.fields.scheduleStart'), render: (s) => (s.schedule_start_date ? s.schedule_start_date.slice(0, 10) : '—') },
    { key: 'due_date', header: t('maintenance.fields.nextDueDate'), render: (s) => (s.next_due_date ? s.next_due_date.slice(0, 10) : '—') },
    { key: 'due_odometer', header: t('maintenance.fields.nextDueOdometer'), render: (s) => (s.next_due_odometer != null ? formatNumber(s.next_due_odometer) : '—') },
    { key: 'status', header: t('common.fields.status'), render: (s) => <StatusBadge status={s.status} /> },
    {
      key: 'actions',
      header: '',
      render: (s) => (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {hasPermission('maintenance_schedule.manage') && (
            <button className="btn-secondary" disabled={busyId === s.id} onClick={() => refresh(s)}>
              {t('maintenance.actions.recalculate')}
            </button>
          )}
          {['DUE_SOON', 'DUE', 'OVERDUE'].includes(s.status) && hasPermission('maintenance_schedule.convert_work_order') && (
            <button className="btn-secondary" disabled={busyId === s.id} onClick={() => convertToWorkOrder(s)}>
              {t('maintenance.actions.convertToWo')}
            </button>
          )}
          {['DUE', 'OVERDUE', 'SCHEDULED'].includes(s.status) && hasPermission('maintenance_schedule.convert_maintenance_request') && (
            <button className="btn-primary" disabled={busyId === s.id} onClick={() => convertToMaintenanceRequest(s)}>
              {t('maintenance.actions.convertToMaintenanceRequest')}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('maintenance.titles.maintenancePlanningAndSchedule')}</h1>
      <Toolbar
        actions={
          hasPermission('maintenance_schedule.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('maintenance.actions.addNewSchedule')}
            </button>
          ) : null
        }
      />
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : t('common.actions.all')}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && data.length === 0 && <EmptyState label={t('maintenance.empty.noMaintenanceSchedulesFound')} />}
      {!listError && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateScheduleModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function computeCandidateDate(startDate: string, periodBy: string, schedulePeriod: number): Date | null {
  if (!startDate || !schedulePeriod) return null;
  const start = new Date(startDate + 'T00:00:00Z');
  const candidate = new Date(start);
  if (periodBy === 'MONTH') {
    candidate.setUTCMonth(candidate.getUTCMonth() + schedulePeriod);
  } else {
    candidate.setUTCDate(candidate.getUTCDate() + schedulePeriod);
  }
  return candidate;
}

function adjustForWorkingDays(date: Date, workshopWorkingDays: number): Date {
  const day = date.getUTCDay(); // 0=Sunday..6=Saturday
  const isNonWorking = workshopWorkingDays === 5 ? day === 0 || day === 6 : workshopWorkingDays === 6 ? day === 0 : false;
  if (!isNonWorking) return date;
  const result = new Date(date);
  const daysUntilMonday = day === 0 ? 1 : 8 - day; // Sunday->+1, Saturday->+2
  result.setUTCDate(result.getUTCDate() + daysUntilMonday);
  return result;
}

function CreateScheduleModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [packages, setPackages] = useState<MaintenancePackageItemType[]>([]);
  const [workshopWorkingDays, setWorkshopWorkingDays] = useState<number | null>(null);
  const [vehicleId, setVehicleId] = useState('');
  const [packageId, setPackageId] = useState('');
  const [scheduleStartDate, setScheduleStartDate] = useState('');
  const [occupiedDates, setOccupiedDates] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const selectedPackage = packages.find((p) => p.id === packageId);

  useEffect(() => {
    if (!open) return;
    setVehicleId('');
    setPackageId('');
    setScheduleStartDate('');
    setErrors({});
    apiClient.get('/app/vehicles', { params: { per_page: 200 } }).then((res) => setVehicles(res.data.data));
    apiClient.get('/app/maintenance-policies', { params: { maintenance_type: 'PERIODIC', status: 'ACTIVE', per_page: 100 } }).then((res) => setPackages(res.data.data));
    apiClient.get('/app/account/company').then((res) => setWorkshopWorkingDays(res.data.data.workshop_working_days));
  }, [open]);

  // Section 12: refresh disabled dates and reset an invalid selection when Vehicle changes.
  useEffect(() => {
    setScheduleStartDate('');
    if (!vehicleId) {
      setOccupiedDates([]);
      return;
    }
    apiClient.get('/app/maintenance-schedules', { params: { vehicle_id: vehicleId, per_page: 200 } }).then((res) => {
      const dates = (res.data.data as MaintenanceScheduleItem[])
        .filter((s) => s.maintenance_package_id !== packageId)
        .map((s) => s.schedule_start_date)
        .filter((d): d is string => !!d)
        .map((d) => d.slice(0, 10));
      setOccupiedDates(dates);
    });
  }, [vehicleId, packageId]);

  const preview = (() => {
    if (!selectedPackage || !scheduleStartDate || !selectedPackage.period_by || !selectedPackage.schedule_period || workshopWorkingDays === null) return null;
    const candidate = computeCandidateDate(scheduleStartDate, selectedPackage.period_by, selectedPackage.schedule_period);
    if (!candidate) return null;
    const adjusted = adjustForWorkingDays(candidate, workshopWorkingDays);
    return adjusted.toISOString().slice(0, 10);
  })();

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/maintenance-schedules', {
        vehicle_id: vehicleId,
        maintenance_package_id: packageId,
        schedule_start_date: scheduleStartDate,
      });
      onCreated();
      onClose();
    } catch (err) {
      const apiError = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title={t('maintenance.modals.newMaintenanceSchedule')} onClose={onClose} width={520}>
      <FormField label={t('common.fields.vehicle')} errors={errors.vehicle_id} required>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.select')}</option>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={t('maintenance.fields.maintenancePackage')} errors={errors.maintenance_package_id} required>
        <select value={packageId} onChange={(e) => setPackageId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.select')}</option>
          {packages.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      {selectedPackage && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
          <FormField label={t('maintenance.fields.maintenancePeriodBy')}>
            <input value={PERIOD_BY_LABELS[selectedPackage.period_by ?? ''] ?? selectedPackage.period_by ?? '—'} disabled style={inputStyle} />
          </FormField>
          <FormField label={t('maintenance.fields.schedulePeriod')}>
            <input value={selectedPackage.schedule_period ?? ''} disabled style={inputStyle} />
          </FormField>
        </div>
      )}
      <FormField label={t('maintenance.fields.scheduleStartDate')} errors={errors.schedule_start_date} required>
        <input
          type="date"
          value={scheduleStartDate}
          onChange={(e) => setScheduleStartDate(e.target.value)}
          style={inputStyle}
          disabled={!vehicleId || !packageId}
        />
        {occupiedDates.includes(scheduleStartDate) && (
          <div style={{ color: '#b91c1c', fontSize: 12, marginTop: 4 }}>{t('maintenance.help.vehicleAlreadyScheduleDate')}</div>
        )}
      </FormField>
      {workshopWorkingDays === null && (
        <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 10 }}>
          {t('maintenance.help.workshopWorkingDaysNotSetCompany')}
        </div>
      )}
      {preview && (
        <p style={{ fontSize: 12, color: '#6b7280', marginBottom: 10 }}>
          {t('maintenance.fields.previewNextDueDate')}: <strong>{preview}</strong> {t('maintenance.help.backendRecalculatesAuthoritativelySave')}
        </p>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button
          className="btn-primary"
          disabled={submitting || !vehicleId || !packageId || !scheduleStartDate || occupiedDates.includes(scheduleStartDate) || workshopWorkingDays === null}
          onClick={submit}
        >
          {submitting ? t('common.actions.creating') : t('maintenance.actions.createSchedule')}
        </button>
      </div>
    </Modal>
  );
}
