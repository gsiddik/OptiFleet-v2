import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { Pagination } from '../../../components/Pagination';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { WorkerItem, WorkerTypeItem } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { t as tt } from '../../../i18n/i18n';

export function WorkerListPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [workerTypeFilter, setWorkerTypeFilter] = useState('');
  const [branchFilter, setBranchFilter] = useState('');
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [workerTypes, setWorkerTypes] = useState<WorkerTypeItem[]>([]);
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<WorkerItem | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const { data, meta, loading, error } = useApiList<WorkerItem>(
    '/app/workers',
    {
      search: search || undefined,
      status: statusFilter || undefined,
      worker_type_id: workerTypeFilter || undefined,
      branch_id: branchFilter || undefined,
      page,
      per_page: 15,
    },
    reloadKey,
  );

  useEffect(() => {
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data)).catch(() => setBranches([]));
    apiClient.get('/app/worker-types', { params: { per_page: 100, status: 'ACTIVE' } }).then((res) => setWorkerTypes(res.data.data)).catch(() => setWorkerTypes([]));
  }, []);

  async function toggleActive(w: WorkerItem) {
    setBusyId(w.id);
    try {
      await apiClient.put(`/app/workers/${w.id}`, { status: w.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' });
      setReloadKey((k) => k + 1);
    } finally {
      setBusyId(null);
    }
  }

  const columns: Column<WorkerItem>[] = [
    { key: 'employee_code', header: tt('common.fields.code'), render: (w) => <button className="btn-link" onClick={() => setEditing(w)}>{w.employee_code}</button> },
    { key: 'name', header: tt('common.fields.name'), render: (w) => w.name },
    { key: 'worker_type', header: tt('common.fields.type'), render: (w) => w.worker_type_master?.name ?? w.worker_type },
    { key: 'branch', header: tt('common.fields.branch'), render: (w) => w.branch?.name ?? '—' },
    { key: 'workshop', header: tt('common.fields.workshop'), render: (w) => w.workshop?.name ?? '—' },
    { key: 'skills', header: tt('workshop.fields.skills'), render: (w) => (w.skills ?? []).map((s) => (s.component_group ? componentGroupLabel(s.component_group) : null)).filter(Boolean).join(', ') || '—' },
    { key: 'status', header: tt('common.fields.status'), render: (w) => <StatusBadge status={w.status} /> },
    {
      key: 'actions',
      header: '',
      render: (w) =>
        hasPermission('worker.manage') ? (
          <button className="btn-secondary" disabled={busyId === w.id} onClick={() => toggleActive(w)}>
            {w.status === 'ACTIVE' ? tt('common.actions.deactivate') : tt('common.actions.activate')}
          </button>
        ) : null,
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('workshop.titles.workersMechanics')}</h1>
      <Toolbar
        search={search}
        onSearchChange={(v) => {
          setSearch(v);
          setPage(1);
        }}
        actions={
          hasPermission('worker.manage') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('workshop.actions.newWorker')}
            </button>
          ) : null
        }
      >
        <select
          value={workerTypeFilter}
          onChange={(e) => {
            setWorkerTypeFilter(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, maxWidth: 180 }}
        >
          <option value="">{tt('common.filters.allTypes')}</option>
          {workerTypes.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name}
            </option>
          ))}
        </select>
        <select
          value={branchFilter}
          onChange={(e) => {
            setBranchFilter(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, maxWidth: 180 }}
        >
          <option value="">{tt('history.filters.allBranches')}</option>
          {branches.map((b) => (
            <option key={b.id} value={b.id}>
              {b.name}
            </option>
          ))}
        </select>
        <select
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, maxWidth: 140 }}
        >
          <option value="">{tt('common.filters.allStatuses')}</option>
          <option value="ACTIVE">{tt('common.fields.active')}</option>
          <option value="INACTIVE">{tt('common.fields.inactive')}</option>
        </select>
      </Toolbar>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('workshop.empty.noWorkersFound')} />}
      {!error && !loading && data.length > 0 && (
        <>
          <Table columns={columns} rows={data} />
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </>
      )}

      <CreateWorkerModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
      {editing && (
        <WorkerDetailModal workerId={editing.id} onClose={() => setEditing(null)} onChanged={() => setReloadKey((k) => k + 1)} />
      )}
    </div>
  );
}

function CreateWorkerModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [workerTypes, setWorkerTypes] = useState<WorkerTypeItem[]>([]);
  const [employeeCode, setEmployeeCode] = useState('');
  const [name, setName] = useState('');
  const [branchId, setBranchId] = useState('');
  const [workshopId, setWorkshopId] = useState('');
  const [workerTypeId, setWorkerTypeId] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [address, setAddress] = useState('');
  const [monthlyRate, setMonthlyRate] = useState('');
  const [hourlyRate, setHourlyRate] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
    apiClient.get('/app/worker-types', { params: { per_page: 100, status: 'ACTIVE' } }).then((res) => {
      setWorkerTypes(res.data.data);
      setWorkerTypeId((current) => current || res.data.data[0]?.id || '');
    });
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/workers', {
        employee_code: employeeCode, name, branch_id: branchId, workshop_id: workshopId || null, worker_type_id: workerTypeId,
        phone: phone || undefined, email: email || undefined, address: address || undefined,
        monthly_rate: monthlyRate || undefined, hourly_rate: hourlyRate || undefined,
      });
      setEmployeeCode('');
      setName('');
      setPhone('');
      setEmail('');
      setAddress('');
      setMonthlyRate('');
      setHourlyRate('');
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
    <Modal open={open} title={tt('workshop.modals.newWorker')} onClose={onClose} width={520}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <FormField label={tt('workshop.fields.employeeCode')} errors={errors.employee_code} required>
          <input value={employeeCode} onChange={(e) => setEmployeeCode(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('common.fields.name')} errors={errors.name} required>
          <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('common.fields.branch')} errors={errors.branch_id} required>
          <select value={branchId} onChange={(e) => setBranchId(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.select')}</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('workshop.fields.workshopOptional')} errors={errors.workshop_id}>
          <select value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.none')}</option>
            {workshops.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('workshop.fields.workerType')} errors={errors.worker_type_id} required>
          <select value={workerTypeId} onChange={(e) => setWorkerTypeId(e.target.value)} style={inputStyle}>
            <option value="">{tt('common.fields.select')}</option>
            {workerTypes.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={tt('workshop.fields.phoneOptional')} errors={errors.phone}>
          <input value={phone} onChange={(e) => setPhone(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('workshop.fields.emailOptional')} errors={errors.email}>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('workshop.fields.monthlyRateOptional')} errors={errors.monthly_rate}>
          <NumericInput step="0.01" value={monthlyRate} onChange={(e) => setMonthlyRate(e.target.value)} style={inputStyle} />
        </FormField>
        <FormField label={tt('workshop.fields.hourlyRateOptional')} errors={errors.hourly_rate}>
          <NumericInput step="0.01" value={hourlyRate} onChange={(e) => setHourlyRate(e.target.value)} style={inputStyle} />
        </FormField>
      </div>
      <FormField label={tt('workshop.fields.addressOptional')} errors={errors.address}>
        <textarea value={address} onChange={(e) => setAddress(e.target.value)} style={{ ...inputStyle, minHeight: 50 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !employeeCode || !name || !branchId} onClick={submit}>
          {tt('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}

interface TenantUserOption {
  user_id: string;
  name: string;
  email: string;
}

function WorkerDetailModal({ workerId, onClose, onChanged }: { workerId: string; onClose: () => void; onChanged: () => void }) {
  const { hasPermission } = useAuth();
  const [worker, setWorker] = useState<WorkerItem | null>(null);
  const [categories, setCategories] = useState<{ id: string; name: string }[]>([]);
  const [componentGroupId, setComponentGroupId] = useState('');
  const [skillLevel, setSkillLevel] = useState('3');
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [assignBranchId, setAssignBranchId] = useState('');
  const [assignWorkshopId, setAssignWorkshopId] = useState('');
  const [tenantUsers, setTenantUsers] = useState<TenantUserOption[]>([]);
  const [selectedUserId, setSelectedUserId] = useState('');
  const [busy, setBusy] = useState(false);
  const [editingContact, setEditingContact] = useState(false);
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [address, setAddress] = useState('');
  const [monthlyRate, setMonthlyRate] = useState('');
  const [hourlyRate, setHourlyRate] = useState('');

  function load() {
    apiClient.get(`/app/workers/${workerId}`).then((res) => {
      const w: WorkerItem = res.data.data;
      setWorker(w);
      setPhone(w.phone ?? '');
      setEmail(w.email ?? '');
      setAddress(w.address ?? '');
      setMonthlyRate(w.monthly_rate ?? '');
      setHourlyRate(w.hourly_rate ?? '');
    });
  }

  async function saveContact() {
    setBusy(true);
    try {
      await apiClient.put(`/app/workers/${workerId}`, {
        phone: phone || null, email: email || null, address: address || null,
        monthly_rate: monthlyRate || null, hourly_rate: hourlyRate || null,
      });
      setEditingContact(false);
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  useEffect(load, [workerId]);
  useEffect(() => {
    apiClient.get('/app/component-groups', { params: { per_page: 100 } }).then((res) => setCategories(res.data.data));
    apiClient.get('/app/branches', { params: { per_page: 100 } }).then((res) => setBranches(res.data.data));
    apiClient.get('/app/workshops', { params: { per_page: 100 } }).then((res) => setWorkshops(res.data.data));
    apiClient.get('/app/users', { params: { per_page: 200 } }).then((res) => setTenantUsers(res.data.data)).catch(() => setTenantUsers([]));
  }, []);

  /** G-14: workers.user_id had no UI to ever set it. */
  async function linkUser() {
    setBusy(true);
    try {
      await apiClient.post(`/app/workers/${workerId}/link-user`, { user_id: selectedUserId });
      setSelectedUserId('');
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function unlinkUser() {
    setBusy(true);
    try {
      await apiClient.post(`/app/workers/${workerId}/unlink-user`);
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function addSkill() {
    setBusy(true);
    try {
      await apiClient.post(`/app/workers/${workerId}/skills`, { component_group_id: componentGroupId, skill_level: Number(skillLevel) });
      setComponentGroupId('');
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function assign() {
    setBusy(true);
    try {
      await apiClient.post(`/app/workers/${workerId}/assign`, { branch_id: assignBranchId, workshop_id: assignWorkshopId || null });
      load();
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  if (!worker) return null;

  return (
    <Modal open title={`${worker.name} (${worker.employee_code})`} onClose={onClose} width={600}>
      <div style={{ marginBottom: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
        <StatusBadge status={worker.status} />
        <span style={{ fontSize: 13, color: '#6b7280' }}>
          {worker.worker_type_master?.name ?? worker.worker_type} — {worker.branch?.name ?? '—'} / {worker.workshop?.name ?? tt('workshop.empty.noWorkshop')}
        </span>
      </div>

      <div style={{ marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h4 style={{ fontSize: 13, margin: 0 }}>{tt('workshop.sections.contactAndCompensation')}</h4>
          {hasPermission('worker.manage') && !editingContact && (
            <button className="btn-link" onClick={() => setEditingContact(true)}>
              {tt('common.actions.edit')}
            </button>
          )}
        </div>
        {!editingContact ? (
          <div style={{ fontSize: 13, color: '#374151', marginTop: 4 }}>
            {tt('workshop.placeholders.phone')}: {worker.phone ?? '—'} {tt('workshop.placeholders.email')}: {worker.email ?? '—'} {tt('workshop.placeholders.monthlyRate')}: {formatMoney(worker.monthly_rate)} {tt('workshop.placeholders.hourlyRate')}: {formatMoney(worker.hourly_rate)}
            {worker.address && <div>{tt('workshop.fields.addressAddress', { address: worker.address })}</div>}
          </div>
        ) : (
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 6 }}>
            <input placeholder={tt('workshop.placeholders.phone')} value={phone} onChange={(e) => setPhone(e.target.value)} style={{ ...inputStyle, width: 130 }} />
            <input placeholder={tt('workshop.placeholders.email')} value={email} onChange={(e) => setEmail(e.target.value)} style={{ ...inputStyle, width: 160 }} />
            <NumericInput placeholder={tt('workshop.placeholders.monthlyRate')} value={monthlyRate} onChange={(e) => setMonthlyRate(e.target.value)}
              style={{ ...inputStyle, width: 130 }}
            />
            <NumericInput placeholder={tt('workshop.placeholders.hourlyRate')} value={hourlyRate} onChange={(e) => setHourlyRate(e.target.value)}
              style={{ ...inputStyle, width: 130 }}
            />
            <input placeholder={tt('workshop.placeholders.address')} value={address} onChange={(e) => setAddress(e.target.value)} style={{ ...inputStyle, width: '100%' }} />
            <button className="btn-primary" disabled={busy} onClick={saveContact}>
              {tt('common.actions.save')}
            </button>
            <button className="btn-secondary" disabled={busy} onClick={() => setEditingContact(false)}>
              {tt('common.actions.cancel')}
            </button>
          </div>
        )}
      </div>

      <h4 style={{ fontSize: 13, marginBottom: 6 }}>{tt('workshop.fields.skills')}</h4>
      <table style={{ width: '100%', fontSize: 13, marginBottom: 12, borderCollapse: 'collapse' }}>
        <tbody>
          {(worker.skills ?? []).map((s) => (
            <tr key={s.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '6px 4px' }}>{s.component_group ? componentGroupLabel(s.component_group) : s.component_group_id}</td>
              <td style={{ padding: '6px 4px', color: '#6b7280' }}>{tt('workshop.fields.levelL', { l: s.skill_level ?? '—' })}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {hasPermission('worker.manage') && (
        <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
          <select value={componentGroupId} onChange={(e) => setComponentGroupId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
            <option value="">{tt('workshop.fields.selectComponentGroup')}</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {componentGroupLabel(c)}
              </option>
            ))}
          </select>
          <select value={skillLevel} onChange={(e) => setSkillLevel(e.target.value)} style={{ ...inputStyle, width: 100 }}>
            {[1, 2, 3, 4, 5].map((l) => (
              <option key={l} value={l}>
                {tt('workshop.fields.levelL', { l: l })}</option>
            ))}
          </select>
          <button className="btn-secondary" disabled={busy || !componentGroupId} onClick={addSkill}>
            {tt('workshop.actions.addSkill')}
          </button>
        </div>
      )}

      {hasPermission('worker.assign') && (
        <>
          <h4 style={{ fontSize: 13, marginBottom: 6 }}>{tt('workshop.sections.reassign')}</h4>
          <div style={{ display: 'flex', gap: 8 }}>
            <select value={assignBranchId} onChange={(e) => setAssignBranchId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
              <option value="">{tt('workshop.fields.selectBranch')}</option>
              {branches.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.name}
                </option>
              ))}
            </select>
            <select value={assignWorkshopId} onChange={(e) => setAssignWorkshopId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
              <option value="">{tt('workshop.empty.noWorkshop')}</option>
              {workshops.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
            <button className="btn-secondary" disabled={busy || !assignBranchId} onClick={assign}>
              {tt('common.actions.assign')}
            </button>
          </div>
        </>
      )}

      {hasPermission('worker.manage') && (
        <>
          <h4 style={{ fontSize: 13, marginTop: 16, marginBottom: 6 }}>{tt('workshop.sections.loginAccount')}</h4>
          {worker.user_id ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13 }}>
              <span>
                {tt('workshop.fields.linkedTo')} {tenantUsers.find((u) => u.user_id === worker.user_id)?.name ?? worker.user_id}
              </span>
              <button className="btn-secondary" disabled={busy} onClick={unlinkUser}>
                {tt('workshop.actions.unlink')}
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', gap: 8 }}>
              <select value={selectedUserId} onChange={(e) => setSelectedUserId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
                <option value="">{tt('workshop.fields.selectALoginAccount')}</option>
                {tenantUsers.map((u) => (
                  <option key={u.user_id} value={u.user_id}>
                    {u.name} ({u.email})
                  </option>
                ))}
              </select>
              <button className="btn-secondary" disabled={busy || !selectedUserId} onClick={linkUser}>
                {tt('workshop.actions.link')}
              </button>
            </div>
          )}
        </>
      )}
    </Modal>
  );
}
