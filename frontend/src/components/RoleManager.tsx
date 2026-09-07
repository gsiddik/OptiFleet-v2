import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../api/client';
import { EmptyState, ErrorState, LoadingState } from './States';
import { FormField, inputStyle } from './FormField';
import { Modal } from './Modal';
import type { PermissionItem, RoleItem } from '../types';
import { useAuth } from '../auth/AuthContext';

export function RoleManager({ rolesEndpoint, permissionsEndpoint }: { rolesEndpoint: string; permissionsEndpoint: string }) {
  const { hasPermission } = useAuth();
  const [roles, setRoles] = useState<RoleItem[] | null>(null);
  const [permissions, setPermissions] = useState<PermissionItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [editingRole, setEditingRole] = useState<RoleItem | null>(null);

  function load() {
    Promise.all([apiClient.get(rolesEndpoint), apiClient.get(permissionsEndpoint)])
      .then(([roleRes, permRes]) => {
        setRoles(roleRes.data.data);
        setPermissions(permRes.data.data);
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [rolesEndpoint, permissionsEndpoint]);

  if (error) return <ErrorState message={error} />;
  if (!roles) return <LoadingState />;

  const grouped = permissions.reduce<Record<string, PermissionItem[]>>((acc, p) => {
    (acc[p.group] ??= []).push(p);
    return acc;
  }, {});

  return (
    <div>
      {hasPermission('role.create') && (
        <div style={{ marginBottom: 12, textAlign: 'right' }}>
          <button className="btn-primary" onClick={() => setShowCreate(true)}>
            + New Role
          </button>
        </div>
      )}

      {roles.length === 0 && <EmptyState label="No roles defined yet." />}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: 14 }}>
        {roles.map((role) => (
          <div key={role.id} className="card">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <strong>{role.name}</strong>
              {role.is_system && <span style={{ fontSize: 11, color: '#9ca3af' }}>SYSTEM</span>}
            </div>
            <div style={{ fontSize: 12, color: '#6b7280', margin: '6px 0 10px' }}>{role.permissions.length} permissions</div>
            {hasPermission('role.assign_permission') && !role.is_system && (
              <button className="btn-link" onClick={() => setEditingRole(role)}>
                Edit permissions
              </button>
            )}
          </div>
        ))}
      </div>

      {showCreate && (
        <RoleFormModal
          grouped={grouped}
          rolesEndpoint={rolesEndpoint}
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            setShowCreate(false);
            load();
          }}
        />
      )}

      {editingRole && (
        <RoleFormModal
          grouped={grouped}
          rolesEndpoint={rolesEndpoint}
          role={editingRole}
          onClose={() => setEditingRole(null)}
          onSaved={() => {
            setEditingRole(null);
            load();
          }}
        />
      )}
    </div>
  );
}

function RoleFormModal({
  grouped,
  rolesEndpoint,
  role,
  onClose,
  onSaved,
}: {
  grouped: Record<string, PermissionItem[]>;
  rolesEndpoint: string;
  role?: RoleItem;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(role?.name ?? '');
  const [description, setDescription] = useState('');
  const [selected, setSelected] = useState<Set<string>>(
    new Set(role ? Object.values(grouped).flat().filter((p) => role.permissions.includes(p.name)).map((p) => p.id) : []),
  );
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  function toggle(id: string) {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      if (role) {
        await apiClient.post(`${rolesEndpoint}/${role.id}/permissions`, { permission_ids: Array.from(selected) });
      } else {
        await apiClient.post(rolesEndpoint, { name, description, permission_ids: Array.from(selected) });
      }
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={role ? `Edit Permissions — ${role.name}` : 'New Role'} onClose={onClose} width={560}>
      {!role && (
        <>
          <FormField label="Name" errors={errors.name}>
            <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
          </FormField>
          <FormField label="Description" errors={errors.description}>
            <input value={description} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
          </FormField>
        </>
      )}

      <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6 }}>Permissions</label>
      <div style={{ maxHeight: 320, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10 }}>
        {Object.entries(grouped).map(([group, perms]) => (
          <div key={group} style={{ marginBottom: 10 }}>
            <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', color: '#6b7280', marginBottom: 4 }}>
              {group}
            </div>
            {perms.map((p) => (
              <label key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, padding: '2px 0' }}>
                <input type="checkbox" checked={selected.has(p.id)} onChange={() => toggle(p.id)} />
                {p.name}
              </label>
            ))}
          </div>
        ))}
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting} onClick={submit}>
          {submitting ? 'Saving…' : 'Save'}
        </button>
      </div>
    </Modal>
  );
}
