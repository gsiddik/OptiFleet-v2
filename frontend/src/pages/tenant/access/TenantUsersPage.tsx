import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { Branch, RoleItem, Warehouse, Workshop, WorkerItem } from '../../../types';

interface TenantUserRow {
  id: string;
  user_id: string;
  name: string;
  email: string;
  status: string;
  roles: string[];
}

export function TenantUsersPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showInvite, setShowInvite] = useState(false);
  const [managing, setManaging] = useState<TenantUserRow | null>(null);
  const { data, loading, error } = useApiList<TenantUserRow>('/app/users', { search }, reloadKey);

  async function toggleStatus(row: TenantUserRow) {
    await apiClient.patch(`/app/users/${row.id}`, { status: row.status === 'active' ? 'inactive' : 'active' });
    setReloadKey((k) => k + 1);
  }

  const columns: Column<TenantUserRow>[] = [
    { key: 'name', header: 'Name', render: (r) => r.name },
    { key: 'email', header: 'Email', render: (r) => r.email },
    { key: 'roles', header: 'Roles', render: (r) => r.roles.join(', ') || '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'actions',
      header: '',
      render: (r) => (
        <div style={{ display: 'flex', gap: 8 }}>
          {hasPermission('user.assign') && (
            <button className="btn-link" onClick={() => setManaging(r)}>
              Manage Access
            </button>
          )}
          {hasPermission('user.update') && (
            <button className="btn-link" onClick={() => toggleStatus(r)}>
              {r.status === 'active' ? 'Deactivate' : 'Activate'}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Users</h1>
      <Toolbar
        search={search}
        onSearchChange={setSearch}
        actions={
          hasPermission('user.create') ? (
            <button className="btn-primary" onClick={() => setShowInvite(true)}>
              + Add User
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No users found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <InviteModal
        open={showInvite}
        onClose={() => setShowInvite(false)}
        onSaved={() => setReloadKey((k) => k + 1)}
        canLinkWorker={hasPermission('worker.manage')}
        canAssignRole={hasPermission('user.assign')}
      />
      {managing && (
        <ManageAccessModal
          row={managing}
          onClose={() => setManaging(null)}
          onSaved={() => {
            setManaging(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function InviteModal({
  open,
  onClose,
  onSaved,
  canLinkWorker,
  canAssignRole,
}: {
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
  canLinkWorker: boolean;
  canAssignRole: boolean;
}) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [workers, setWorkers] = useState<WorkerItem[]>([]);
  const [workerId, setWorkerId] = useState('');
  const [roles, setRoles] = useState<RoleItem[]>([]);
  const [roleId, setRoleId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [followUpError, setFollowUpError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    if (canLinkWorker) {
      apiClient.get('/app/workers', { params: { per_page: 200 } }).then((res) => setWorkers(res.data.data.filter((w: WorkerItem) => !w.user_id))).catch(() => setWorkers([]));
    }
    if (canAssignRole) {
      apiClient.get('/app/roles', { params: { per_page: 100 } }).then((res) => setRoles(res.data.data)).catch(() => setRoles([]));
    }
  }, [open, canLinkWorker, canAssignRole]);

  // Convenience only: chains the three already-independently-reachable endpoints
  // (create user, link worker, assign role) in one guided flow. Each remains
  // separately usable afterward (e.g. to relink/reassign) — nothing is removed.
  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFollowUpError(null);
    try {
      const created = await apiClient.post('/app/users', { name, email, password });
      const userId = created.data.data.user.id;
      const membershipId = created.data.data.membership.id;

      if (workerId) {
        try {
          await apiClient.post(`/app/workers/${workerId}/link-user`, { user_id: userId });
        } catch (err) {
          setFollowUpError(`User created, but linking the worker failed: ${extractApiError(err).message}`);
        }
      }
      if (roleId) {
        try {
          await apiClient.post(`/app/users/${membershipId}/roles`, { role_id: roleId });
        } catch (err) {
          setFollowUpError((prev) => prev ?? `User created, but assigning the role failed: ${extractApiError(err).message}`);
        }
      }

      setName('');
      setEmail('');
      setPassword('');
      setWorkerId('');
      setRoleId('');
      onSaved();
      if (!workerId && !roleId) onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="Add User" onClose={onClose}>
      <FormField label="Name" errors={errors.name}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Email" errors={errors.email}>
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Password" errors={errors.password}>
        <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} style={inputStyle} />
      </FormField>
      {canLinkWorker && (
        <FormField label="Link to Worker (optional)">
          <select value={workerId} onChange={(e) => setWorkerId(e.target.value)} style={inputStyle}>
            <option value="">Don't link</option>
            {workers.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      {canAssignRole && (
        <FormField label="Assign Role (optional)">
          <select value={roleId} onChange={(e) => setRoleId(e.target.value)} style={inputStyle}>
            <option value="">Don't assign</option>
            {roles.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
        </FormField>
      )}
      {followUpError && <div style={{ color: '#b91c1c', fontSize: 12, marginTop: 8 }}>{followUpError}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {followUpError ? 'Close' : 'Cancel'}
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Adding…' : 'Add User'}
        </button>
      </div>
    </Modal>
  );
}

interface DataScope {
  id: string;
  scope_type: string;
  scope_resource_id: string | null;
}

function ManageAccessModal({ row, onClose, onSaved }: { row: TenantUserRow; onClose: () => void; onSaved: () => void }) {
  const [roles, setRoles] = useState<RoleItem[]>([]);
  const [scopes, setScopes] = useState<DataScope[]>([]);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [workshops, setWorkshops] = useState<Workshop[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedRoleId, setSelectedRoleId] = useState('');
  const [scopeType, setScopeType] = useState('TENANT');
  const [scopeResourceId, setScopeResourceId] = useState('');

  function load() {
    Promise.all([
      apiClient.get('/app/roles'),
      apiClient.get(`/app/users/${row.id}/data-scopes`),
      apiClient.get('/app/branches', { params: { per_page: 100 } }),
      apiClient.get('/app/workshops', { params: { per_page: 100 } }),
      apiClient.get('/app/warehouses', { params: { per_page: 100 } }),
    ]).then(([roleRes, scopeRes, branchRes, workshopRes, warehouseRes]) => {
      setRoles(roleRes.data.data);
      setScopes(scopeRes.data.data);
      setBranches(branchRes.data.data);
      setWorkshops(workshopRes.data.data);
      setWarehouses(warehouseRes.data.data);
      setLoading(false);
    });
  }

  useEffect(load, [row.id]);

  async function assignRole() {
    if (!selectedRoleId) return;
    await apiClient.post(`/app/users/${row.id}/roles`, { role_id: selectedRoleId });
    setSelectedRoleId('');
    onSaved();
  }

  async function addScope() {
    await apiClient.post(`/app/users/${row.id}/data-scopes`, {
      scope_type: scopeType,
      scope_resource_id: ['TENANT', 'OWN'].includes(scopeType) ? null : scopeResourceId,
    });
    load();
  }

  async function removeScope(id: string) {
    await apiClient.delete(`/app/users/${row.id}/data-scopes/${id}`);
    load();
  }

  const resourceOptions =
    scopeType === 'BRANCH' ? branches : scopeType === 'WORKSHOP' ? workshops : scopeType === 'WAREHOUSE' ? warehouses : [];

  return (
    <Modal open title={`Manage Access — ${row.name}`} onClose={onClose} width={520}>
      {loading ? (
        <LoadingState />
      ) : (
        <>
          <h4 style={{ fontSize: 13, marginBottom: 6 }}>Roles</h4>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 8 }}>
            {row.roles.length === 0 && <span style={{ color: '#9ca3af', fontSize: 13 }}>No roles assigned</span>}
            {row.roles.map((r) => (
              <span key={r} style={{ background: '#eff6ff', padding: '3px 8px', borderRadius: 6, fontSize: 12 }}>
                {r}
              </span>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
            <select value={selectedRoleId} onChange={(e) => setSelectedRoleId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
              <option value="">Select role to assign…</option>
              {roles.map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </select>
            <button className="btn-secondary" onClick={assignRole}>
              Assign
            </button>
          </div>

          <h4 style={{ fontSize: 13, marginBottom: 6 }}>Data Scope</h4>
          <div style={{ marginBottom: 8 }}>
            {scopes.length === 0 && <div style={{ color: '#9ca3af', fontSize: 13 }}>No scope assigned (no access).</div>}
            {scopes.map((s) => (
              <div key={s.id} style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, padding: '3px 0' }}>
                <span>
                  {s.scope_type}
                  {s.scope_resource_id ? ` — ${s.scope_resource_id}` : ''}
                </span>
                <button className="btn-link" onClick={() => removeScope(s.id)}>
                  remove
                </button>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <select value={scopeType} onChange={(e) => setScopeType(e.target.value)} style={{ ...inputStyle, width: 130 }}>
              <option value="TENANT">TENANT</option>
              <option value="BRANCH">BRANCH</option>
              <option value="WORKSHOP">WORKSHOP</option>
              <option value="WAREHOUSE">WAREHOUSE</option>
              <option value="OWN">OWN</option>
            </select>
            {!['TENANT', 'OWN'].includes(scopeType) && (
              <select value={scopeResourceId} onChange={(e) => setScopeResourceId(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
                <option value="">Select resource…</option>
                {resourceOptions.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name}
                  </option>
                ))}
              </select>
            )}
            <button className="btn-secondary" onClick={addScope}>
              Add
            </button>
          </div>
        </>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
        <button className="btn-primary" onClick={onClose}>
          Done
        </button>
      </div>
    </Modal>
  );
}
