import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { ComponentGroup, MaintenancePackageItemType, VehicleItem } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { NumericInput } from '../../../components/NumericInput';

const ALL_MAINTENANCE_TYPES = ['PREVENTIVE', 'CORRECTIVE', 'BREAKDOWN', 'INSPECTION', 'CAMPAIGN', 'PERIODIC'];
// Section 10: the new-package workflow only offers these two — legacy types
// still exist in the data and remain filterable/viewable above.
const CREATABLE_MAINTENANCE_TYPES = ['PREVENTIVE', 'PERIODIC'];
const TRIGGER_TYPES = ['ODOMETER', 'ENGINE_HOUR', 'CALENDAR_DAY', 'MONTH', 'COMBINATION', 'CONDITION_BASED'];
const PERIOD_BY_OPTIONS = [
  { value: 'CALENDAR_DAY', label: 'Days' },
  { value: 'MONTH', label: 'Month' },
  { value: 'ODOMETER', label: 'KM' },
  { value: 'ENGINE_HOUR', label: 'Engine Hour' },
];
const THRESHOLD_FIELD_BY_PERIOD: Record<string, 'threshold_days' | 'threshold_month' | 'threshold_km' | 'threshold_engine_hour'> = {
  CALENDAR_DAY: 'threshold_days',
  MONTH: 'threshold_month',
  ODOMETER: 'threshold_km',
  ENGINE_HOUR: 'threshold_engine_hour',
};

/** G-01: Maintenance Package/Interval/Item/vehicle-assignment admin UI — the full CRUD backend has existed since an earlier phase with zero frontend caller. */
export function MaintenancePackagesPage() {
  const { hasPermission } = useAuth();
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<MaintenancePackageItemType>(
    '/app/maintenance-policies',
    { status: statusFilter || undefined, maintenance_type: typeFilter || undefined },
    reloadKey,
  );

  const columns: Column<MaintenancePackageItemType>[] = [
    { key: 'code', header: 'Code', render: (p) => <Link to={`/app/maintenance-policies/${p.id}`}>{p.code}</Link> },
    { key: 'name', header: 'Name', render: (p) => p.name },
    { key: 'type', header: 'Type', render: (p) => p.maintenance_type },
    { key: 'intervals', header: 'Intervals', render: (p) => (p.intervals ?? []).length },
    { key: 'status', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Maintenance Packages</h1>
      <Toolbar
        actions={
          hasPermission('maintenance_policy.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Package
            </button>
          ) : null
        }
      >
        <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)} style={{ ...inputStyle, maxWidth: 180 }}>
          <option value="">All types</option>
          {ALL_MAINTENANCE_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
        <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} style={{ ...inputStyle, maxWidth: 140 }}>
          <option value="">All statuses</option>
          <option value="DRAFT">Draft</option>
          <option value="ACTIVE">Active</option>
          <option value="ARCHIVED">Archived</option>
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No maintenance packages defined yet." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreatePackageModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreatePackageModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [maintenanceType, setMaintenanceType] = useState('PREVENTIVE');
  const [periodBy, setPeriodBy] = useState('CALENDAR_DAY');
  const [primaryThreshold, setPrimaryThreshold] = useState('');
  const [equivalentThresholds, setEquivalentThresholds] = useState<Record<string, string>>({});
  const [schedulePeriod, setSchedulePeriod] = useState('');
  const [description, setDescription] = useState('');
  const [standardLaborHours, setStandardLaborHours] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  const periodByOptions = maintenanceType === 'PERIODIC' ? PERIOD_BY_OPTIONS.filter((o) => o.value === 'CALENDAR_DAY' || o.value === 'MONTH') : PERIOD_BY_OPTIONS;
  const primaryField = THRESHOLD_FIELD_BY_PERIOD[periodBy];
  const equivalentFields = (Object.keys(THRESHOLD_FIELD_BY_PERIOD) as (keyof typeof THRESHOLD_FIELD_BY_PERIOD)[]).filter((p) => p !== periodBy);

  useEffect(() => {
    if (maintenanceType === 'PERIODIC' && periodBy !== 'CALENDAR_DAY' && periodBy !== 'MONTH') {
      setPeriodBy('CALENDAR_DAY');
    }
  }, [maintenanceType, periodBy]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      const payload: Record<string, unknown> = {
        code, name, maintenance_type: maintenanceType,
        description: description || undefined,
        standard_labor_hours: standardLaborHours || undefined,
        period_by: periodBy,
      };
      if (maintenanceType === 'PERIODIC') {
        payload.schedule_period = schedulePeriod || undefined;
      } else {
        payload[primaryField] = primaryThreshold || undefined;
        for (const field of equivalentFields) {
          payload[THRESHOLD_FIELD_BY_PERIOD[field]] = equivalentThresholds[field] || undefined;
        }
      }
      await apiClient.post('/app/maintenance-policies', payload);
      setCode('');
      setName('');
      setDescription('');
      setStandardLaborHours('');
      setPrimaryThreshold('');
      setEquivalentThresholds({});
      setSchedulePeriod('');
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
    <Modal open={open} title="New Maintenance Package" onClose={onClose}>
      <FormField label="Code" errors={errors.code} required>
        <input value={code} onChange={(e) => setCode(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Name" errors={errors.name} required>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Maintenance Type" errors={errors.maintenance_type} required>
        <select value={maintenanceType} onChange={(e) => setMaintenanceType(e.target.value)} style={inputStyle}>
          {CREATABLE_MAINTENANCE_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Maintenance Period By" errors={errors.period_by} required>
        <select value={periodBy} onChange={(e) => setPeriodBy(e.target.value)} style={inputStyle}>
          {periodByOptions.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </FormField>
      {maintenanceType === 'PERIODIC' ? (
        <FormField label="Schedule Period" errors={errors.schedule_period} required>
          <NumericInput min={1} value={schedulePeriod} onChange={(e) => setSchedulePeriod(e.target.value)} style={inputStyle} />
          <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>
            Gap between the schedule start and the next schedule, in {PERIOD_BY_OPTIONS.find((o) => o.value === periodBy)?.label.toLowerCase()}.
          </div>
        </FormField>
      ) : (
        <>
          <FormField label={`Primary Threshold (${PERIOD_BY_OPTIONS.find((o) => o.value === periodBy)?.label})`} errors={errors[primaryField]} required>
            <NumericInput min={1} value={primaryThreshold} onChange={(e) => setPrimaryThreshold(e.target.value)} style={inputStyle} />
          </FormField>
          <div style={{ marginBottom: 14 }}>
            <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 6, color: '#374151' }}>Equivalent Threshold (optional)</div>
            <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 8 }}>
              Leave 0 or blank to skip — an empty/0 threshold is never used to trigger a schedule.
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              {equivalentFields.map((field) => (
                <FormField key={field} label={PERIOD_BY_OPTIONS.find((o) => o.value === field)?.label ?? field}>
                  <NumericInput
                    min={0}
                    value={equivalentThresholds[field] ?? ''}
                    onChange={(e) => setEquivalentThresholds((prev) => ({ ...prev, [field]: e.target.value }))}
                    style={inputStyle}
                  />
                </FormField>
              ))}
            </div>
          </div>
        </>
      )}
      <FormField label="Standard Labor Hours" errors={errors.standard_labor_hours}>
        <NumericInput step="0.1" value={standardLaborHours} onChange={(e) => setStandardLaborHours(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Description" errors={errors.description}>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 60 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !code || !name} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}

export function MaintenancePackageDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [pkg, setPkg] = useState<MaintenancePackageItemType | null>(null);
  const [groups, setGroups] = useState<ComponentGroup[]>([]);
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showAddItem, setShowAddItem] = useState(false);
  const [showAddInterval, setShowAddInterval] = useState(false);
  const [showAssign, setShowAssign] = useState(false);
  const [draftSelectedGroupIds, setDraftSelectedGroupIds] = useState<string[]>([]);

  function load() {
    apiClient
      .get(`/app/maintenance-policies/${id}`)
      .then((res) => {
        const p: MaintenancePackageItemType = res.data.data;
        setPkg(p);
        setDraftSelectedGroupIds((p.component_groups ?? []).map((g) => g.id));
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/component-groups', { params: { per_page: 100 } }).then((res) => setGroups(res.data.data)).catch(() => setGroups([]));
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data)).catch(() => setVehicles([]));
  }, []);

  useBreadcrumbLabel(pkg?.id, pkg?.name);

  const canManage = hasPermission('maintenance_policy.manage');
  const isNewWorkflowType = pkg?.maintenance_type === 'PREVENTIVE' || pkg?.maintenance_type === 'PERIODIC';

  async function activate(componentGroupIds?: string[]) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/maintenance-policies/${id}/activate`, componentGroupIds ? { component_group_ids: componentGroupIds } : undefined);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !pkg) return <ErrorState message={error} />;
  if (!pkg) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/maintenance-policies" label="← Back to Maintenance Packages" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {pkg.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({pkg.code})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={pkg.status} />
          {canManage && pkg.status === 'DRAFT' && (
            <button
              className="btn-primary"
              disabled={busy || (isNewWorkflowType && draftSelectedGroupIds.length === 0)}
              onClick={() => activate(isNewWorkflowType ? draftSelectedGroupIds : undefined)}
            >
              Activate
            </button>
          )}
          {canManage && pkg.status === 'ARCHIVED' && (
            <button className="btn-primary" disabled={busy} onClick={() => activate()}>
              Activate
            </button>
          )}
          {canManage && pkg.status === 'ACTIVE' && (
            <button className="btn-primary" onClick={() => setShowAssign(true)}>
              Assign to Vehicle
            </button>
          )}
        </div>
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Type:</strong> {pkg.maintenance_type} &nbsp;
          <strong>Standard Labor Hours:</strong> {pkg.standard_labor_hours ?? '—'}
        </p>
        {isNewWorkflowType && (
          <p style={{ fontSize: 13 }}>
            <strong>Period By:</strong> {PERIOD_BY_OPTIONS.find((o) => o.value === pkg.period_by)?.label ?? '—'} &nbsp;
            {pkg.maintenance_type === 'PERIODIC' ? (
              <>
                <strong>Schedule Period:</strong> {pkg.schedule_period ?? '—'}
              </>
            ) : (
              <>
                <strong>Thresholds:</strong> Days {pkg.threshold_days ?? '—'} · Month {pkg.threshold_month ?? '—'} · KM {pkg.threshold_km ?? '—'} · Engine
                Hour {pkg.threshold_engine_hour ?? '—'}
              </>
            )}
          </p>
        )}
        {pkg.description && (
          <p style={{ fontSize: 13 }}>
            <strong>Description:</strong> {pkg.description}
          </p>
        )}
      </div>

      {isNewWorkflowType ? (
        <PackageItemsCheckboxCard
          pkg={pkg}
          groups={groups}
          canManage={canManage}
          draftSelectedGroupIds={draftSelectedGroupIds}
          onDraftSelectionChange={setDraftSelectedGroupIds}
          onItemsUpdated={load}
        />
      ) : (
        <>
          <div className="card" style={{ marginBottom: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
              {canManage && (
                <button className="btn-secondary" onClick={() => setShowAddItem(true)}>
                  + Add Item
                </button>
              )}
            </div>
            {(pkg.items ?? []).length === 0 && <EmptyState label="No items defined." />}
            {(pkg.items ?? []).map((it) => (
              <div key={it.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
                {it.service_item}
                {it.component_group_id && <span style={{ color: '#6b7280' }}> — {it.component_group ? componentGroupLabel(it.component_group) : (groups.find((g) => g.id === it.component_group_id)?.name ?? it.component_group_id)}</span>}
                {it.standard_labor_hours && <span style={{ color: '#6b7280' }}> · {it.standard_labor_hours}h</span>}
                {it.recommended_part_reference && <span style={{ color: '#6b7280' }}> · part ref {it.recommended_part_reference}</span>}
              </div>
            ))}
          </div>

          <div className="card">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <h3 style={{ marginTop: 0, fontSize: 15 }}>Intervals</h3>
              {canManage && (
                <button className="btn-secondary" onClick={() => setShowAddInterval(true)}>
                  + Add Interval
                </button>
              )}
            </div>
            {(pkg.intervals ?? []).length === 0 && <EmptyState label="No intervals defined." />}
            {(pkg.intervals ?? []).map((iv) => (
              <div key={iv.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
                {iv.trigger_type}
                {iv.odometer_km && ` · every ${iv.odometer_km} km`}
                {iv.engine_hours && ` · every ${iv.engine_hours} engine hrs`}
                {iv.calendar_days && ` · every ${iv.calendar_days} days`}
                {iv.months && ` · every ${iv.months} months`}
              </div>
            ))}
          </div>
        </>
      )}

      {showAddItem && (
        <AddItemModal packageId={pkg.id} groups={groups} onClose={() => setShowAddItem(false)} onSaved={() => { setShowAddItem(false); load(); }} />
      )}
      {showAddInterval && (
        <AddIntervalModal packageId={pkg.id} onClose={() => setShowAddInterval(false)} onSaved={() => { setShowAddInterval(false); load(); }} />
      )}
      {showAssign && (
        <AssignVehicleModal packageId={pkg.id} vehicles={vehicles} onClose={() => setShowAssign(false)} onSaved={() => setShowAssign(false)} />
      )}
    </div>
  );
}

function PackageItemsCheckboxCard({
  pkg,
  groups,
  canManage,
  draftSelectedGroupIds,
  onDraftSelectionChange,
  onItemsUpdated,
}: {
  pkg: MaintenancePackageItemType;
  groups: ComponentGroup[];
  canManage: boolean;
  draftSelectedGroupIds: string[];
  onDraftSelectionChange: (ids: string[]) => void;
  onItemsUpdated: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [editedIds, setEditedIds] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const activeSelectedIds = (pkg.component_groups ?? []).map((g) => g.id);

  function startEdit() {
    setEditedIds(activeSelectedIds);
    setEditing(true);
    setError(null);
  }

  function cancelEdit() {
    setEditing(false);
    setError(null);
  }

  async function saveEdit() {
    setSaving(true);
    setError(null);
    try {
      await apiClient.put(`/app/maintenance-policies/${pkg.id}/items`, { component_group_ids: editedIds });
      setEditing(false);
      onItemsUpdated();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSaving(false);
    }
  }

  function toggle(list: string[], setList: (ids: string[]) => void, groupId: string) {
    setList(list.includes(groupId) ? list.filter((g) => g !== groupId) : [...list, groupId]);
  }

  return (
    <div className="card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Items</h3>
        {canManage && pkg.status === 'ACTIVE' && !editing && (
          <button className="btn-secondary" onClick={startEdit}>
            Edit Items
          </button>
        )}
        {canManage && pkg.status === 'ACTIVE' && editing && (
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn-secondary" disabled={saving} onClick={cancelEdit}>
              Cancel
            </button>
            <button className="btn-primary" disabled={saving || editedIds.length === 0} onClick={saveEdit}>
              {saving ? 'Saving…' : 'Update Items'}
            </button>
          </div>
        )}
      </div>
      {error && <div style={{ color: '#b91c1c', fontSize: 13, marginBottom: 10 }}>{error}</div>}
      {groups.length === 0 && <EmptyState label="No component groups available." />}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 4 }}>
        {groups.map((g) => {
          const checked = pkg.status === 'DRAFT' ? draftSelectedGroupIds.includes(g.id) : editing ? editedIds.includes(g.id) : activeSelectedIds.includes(g.id);
          const disabled = pkg.status === 'DRAFT' ? !canManage : !editing;
          return (
            <label key={g.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, padding: '4px 0' }}>
              <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={() =>
                  pkg.status === 'DRAFT'
                    ? toggle(draftSelectedGroupIds, onDraftSelectionChange, g.id)
                    : toggle(editedIds, setEditedIds, g.id)
                }
              />
              {componentGroupLabel(g)}
            </label>
          );
        })}
      </div>
      {pkg.status === 'DRAFT' && draftSelectedGroupIds.length === 0 && (
        <p style={{ fontSize: 12, color: '#b91c1c', marginTop: 8 }}>At least one item must be checked before activating.</p>
      )}
    </div>
  );
}

function AddItemModal({
  packageId,
  groups,
  onClose,
  onSaved,
}: {
  packageId: string;
  groups: ComponentGroup[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [componentGroupId, setComponentGroupId] = useState('');
  const [serviceItem, setServiceItem] = useState('');
  const [recommendedPartReference, setRecommendedPartReference] = useState('');
  const [standardLaborHours, setStandardLaborHours] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/app/maintenance-policies/${packageId}/items`, {
        component_group_id: componentGroupId || undefined,
        service_item: serviceItem,
        recommended_part_reference: recommendedPartReference || undefined,
        standard_labor_hours: standardLaborHours || undefined,
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
    <Modal open title="Add Package Item" onClose={onClose}>
      <FormField label="Service Item" errors={errors.service_item} required>
        <input value={serviceItem} onChange={(e) => setServiceItem(e.target.value)} placeholder="e.g. Oil filter replacement" style={inputStyle} />
      </FormField>
      <FormField label="Component Group (optional)" errors={errors.component_group_id}>
        <select value={componentGroupId} onChange={(e) => setComponentGroupId(e.target.value)} style={inputStyle}>
          <option value="">None</option>
          {groups.map((g) => (
            <option key={g.id} value={g.id}>
              {componentGroupLabel(g)}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Recommended Part Reference" errors={errors.recommended_part_reference}>
        <input value={recommendedPartReference} onChange={(e) => setRecommendedPartReference(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Standard Labor Hours" errors={errors.standard_labor_hours}>
        <NumericInput step="0.1" value={standardLaborHours} onChange={(e) => setStandardLaborHours(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !serviceItem} onClick={submit}>
          Save
        </button>
      </div>
    </Modal>
  );
}

function AddIntervalModal({ packageId, onClose, onSaved }: { packageId: string; onClose: () => void; onSaved: () => void }) {
  const [triggerType, setTriggerType] = useState('ODOMETER');
  const [odometerKm, setOdometerKm] = useState('');
  const [engineHours, setEngineHours] = useState('');
  const [calendarDays, setCalendarDays] = useState('');
  const [months, setMonths] = useState('');
  const [toleranceKm, setToleranceKm] = useState('');
  const [toleranceDays, setToleranceDays] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/app/maintenance-policies/${packageId}/intervals`, {
        trigger_type: triggerType,
        odometer_km: odometerKm || undefined,
        engine_hours: engineHours || undefined,
        calendar_days: calendarDays || undefined,
        months: months || undefined,
        tolerance_km: toleranceKm || undefined,
        tolerance_days: toleranceDays || undefined,
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
    <Modal open title="Add Interval" onClose={onClose}>
      <FormField label="Trigger Type" errors={errors.trigger_type} required>
        <select value={triggerType} onChange={(e) => setTriggerType(e.target.value)} style={inputStyle}>
          {TRIGGER_TYPES.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label="Odometer (km)" errors={errors.odometer_km}>
          <NumericInput value={odometerKm} onChange={(e) => setOdometerKm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Engine Hours" errors={errors.engine_hours}>
          <NumericInput value={engineHours} onChange={(e) => setEngineHours(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Calendar Days" errors={errors.calendar_days}>
          <NumericInput value={calendarDays} onChange={(e) => setCalendarDays(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Months" errors={errors.months}>
          <NumericInput value={months} onChange={(e) => setMonths(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Tolerance (km)" errors={errors.tolerance_km}>
          <NumericInput value={toleranceKm} onChange={(e) => setToleranceKm(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label="Tolerance (days)" errors={errors.tolerance_days}>
          <NumericInput value={toleranceDays} onChange={(e) => setToleranceDays(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          Save
        </button>
      </div>
    </Modal>
  );
}

function AssignVehicleModal({
  packageId,
  vehicles,
  onClose,
  onSaved,
}: {
  packageId: string;
  vehicles: VehicleItem[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [vehicleId, setVehicleId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post(`/app/maintenance-policies/${packageId}/assign`, { vehicle_id: vehicleId });
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title="Assign Package to Vehicle" onClose={onClose}>
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
      <p style={{ fontSize: 12, color: '#6b7280' }}>
        Assigning generates a maintenance schedule for this vehicle immediately, based on the package&apos;s intervals.
      </p>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId} onClick={submit}>
          Assign
        </button>
      </div>
    </Modal>
  );
}
