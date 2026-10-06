import { useEffect, useMemo, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../api/client';
import { EmptyState, ErrorState, LoadingState } from './States';
import { FormField, inputStyle } from './FormField';
import { Modal } from './Modal';
import type { PermissionItem, RoleItem } from '../types';
import { useAuth } from '../auth/AuthContext';
import { t } from '../i18n/i18n';

type FeatureGroup = { feature: string; label: string; permissions: PermissionItem[] };
type ModuleGroup = { module: string; label: string; features: FeatureGroup[] };

/** Module -> Feature -> Action tree, from the backend-provided permission metadata. */
function buildTree(permissions: PermissionItem[]): ModuleGroup[] {
  const modules = new Map<string, ModuleGroup>();
  for (const p of permissions) {
    const moduleKey = p.module_name ?? p.module ?? t('common.fields.general');
    const featureKey = p.feature ?? p.group;
    let mod = modules.get(moduleKey);
    if (!mod) {
      mod = { module: moduleKey, label: moduleKey, features: [] };
      modules.set(moduleKey, mod);
    }
    let feature = mod.features.find((f) => f.feature === featureKey);
    if (!feature) {
      feature = { feature: featureKey, label: p.feature_name ?? featureKey, permissions: [] };
      mod.features.push(feature);
    }
    feature.permissions.push(p);
  }
  return Array.from(modules.values()).sort((a, b) => a.label.localeCompare(b.label));
}

/**
 * Role management for the tenant and platform portals. Authorized users can edit
 * any existing role — including the seeded default roles — except the platform
 * superadmin role, which always holds every platform permission. The backend
 * (role.update / role.assign_permission, scope-checked IDs, self lock-out guard)
 * stays authoritative; this UI only reflects it.
 */
export function RoleManager({ rolesEndpoint, permissionsEndpoint }: { rolesEndpoint: string; permissionsEndpoint: string }) {
  const { hasPermission, refresh } = useAuth();
  const [roles, setRoles] = useState<RoleItem[] | null>(null);
  const [permissions, setPermissions] = useState<PermissionItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [editingRole, setEditingRole] = useState<RoleItem | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    Promise.all([apiClient.get(rolesEndpoint), apiClient.get(permissionsEndpoint)])
      .then(([roleRes, permRes]) => {
        setRoles(roleRes.data.data);
        setPermissions(permRes.data.data);
      })
      .catch((err) => setError(extractApiError(err).message));
  }, [rolesEndpoint, permissionsEndpoint, reloadKey]);

  const tree = useMemo(() => buildTree(permissions), [permissions]);
  const canEdit = hasPermission('role.update') || hasPermission('role.assign_permission');

  if (error) return <ErrorState message={error} />;
  if (!roles) return <LoadingState />;

  async function saved() {
    setShowCreate(false);
    setEditingRole(null);
    setReloadKey((k) => k + 1);
    // The edited role may be one of the current user's own roles: re-read their effective permissions.
    await refresh();
  }

  return (
    <div>
      {hasPermission('role.create') && (
        <div style={{ marginBottom: 12, textAlign: 'right' }}>
          <button className="btn-primary" onClick={() => setShowCreate(true)}>
            {t('common.actions.newRole')}
          </button>
        </div>
      )}

      {roles.length === 0 && <EmptyState label={t('common.empty.noRolesDefinedYet')} />}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 14 }}>
        {roles.map((role) => (
          <div key={role.id} className="card">
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
              <strong>{role.name}</strong>
              {role.is_system && <span style={{ fontSize: 11, color: '#9ca3af' }}>{t('common.fields.system2')}</span>}
            </div>
            {role.description && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{role.description}</div>}
            <div style={{ fontSize: 12, color: '#6b7280', margin: '6px 0 10px' }}>{role.permissions.length} permissions</div>
            {canEdit && role.editable !== false && (
              <button className="btn-link" onClick={() => setEditingRole(role)}>
                {t('common.actions.editRoleAndPermissions')}
              </button>
            )}
            {role.editable === false && <div style={{ fontSize: 11, color: '#9ca3af' }}>{t('common.help.holdsEveryPermissionNotEditable')}</div>}
          </div>
        ))}
      </div>

      {(showCreate || editingRole) && (
        <RoleFormModal
          tree={tree}
          permissions={permissions}
          rolesEndpoint={rolesEndpoint}
          role={editingRole ?? undefined}
          canEditDetails={editingRole ? hasPermission('role.update') : true}
          canEditPermissions={editingRole ? hasPermission('role.assign_permission') : true}
          onClose={() => {
            setShowCreate(false);
            setEditingRole(null);
          }}
          onSaved={saved}
        />
      )}
    </div>
  );
}

function RoleFormModal({
  tree,
  permissions,
  rolesEndpoint,
  role,
  canEditDetails,
  canEditPermissions,
  onClose,
  onSaved,
}: {
  tree: ModuleGroup[];
  permissions: PermissionItem[];
  rolesEndpoint: string;
  role?: RoleItem;
  canEditDetails: boolean;
  canEditPermissions: boolean;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(role?.name ?? '');
  const [description, setDescription] = useState(role?.description ?? '');
  const [selected, setSelected] = useState<Set<string>>(
    () => new Set(role ? role.permission_ids ?? permissions.filter((p) => role.permissions.includes(p.name)).map((p) => p.id) : []),
  );
  const [search, setSearch] = useState('');
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const needle = search.trim().toLowerCase();
  const visibleTree = useMemo(() => {
    if (!needle) return tree;
    return tree
      .map((m) => ({
        ...m,
        features: m.features
          .map((f) => ({
            ...f,
            permissions: f.permissions.filter((p) => `${m.label} ${f.label} ${p.name} ${p.action_name ?? ''}`.toLowerCase().includes(needle)),
          }))
          .filter((f) => f.permissions.length > 0),
      }))
      .filter((m) => m.features.length > 0);
  }, [tree, needle]);

  const idsOf = (perms: PermissionItem[]) => perms.map((p) => p.id);
  const allOf = (ids: string[]) => ids.length > 0 && ids.every((id) => selected.has(id));
  const someOf = (ids: string[]) => ids.some((id) => selected.has(id));

  function setMany(ids: string[], on: boolean) {
    setSelected((current) => {
      const next = new Set(current);
      ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
      return next;
    });
  }

  function toggleCollapsed(key: string) {
    setCollapsed((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFormError(null);
    try {
      if (role) {
        if (canEditDetails) {
          await apiClient.put(`${rolesEndpoint}/${role.id}`, role.is_system ? { description: description || null } : { name, description: description || null });
        }
        if (canEditPermissions) {
          await apiClient.post(`${rolesEndpoint}/${role.id}/permissions`, { permission_ids: Array.from(selected) });
        }
      } else {
        await apiClient.post(rolesEndpoint, { name, description: description || null, permission_ids: Array.from(selected) });
      }
      onSaved();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
      setFormError(apiError.errors ? Object.values(apiError.errors).flat()[0] ?? apiError.message : apiError.message);
    } finally {
      setSubmitting(false);
    }
  }

  const visibleIds = visibleTree.flatMap((m) => m.features.flatMap((f) => idsOf(f.permissions)));
  const checkbox = (ids: string[], disabled: boolean) => (
    <input
      type="checkbox"
      checked={allOf(ids)}
      ref={(el) => {
        if (el) el.indeterminate = !allOf(ids) && someOf(ids);
      }}
      disabled={disabled}
      onChange={() => setMany(ids, !allOf(ids))}
    />
  );

  return (
    <Modal open title={role ? t('common.modals.editRoleName', { name: role.name }) : t('common.modals.newRole')} onClose={onClose} width={760}>
      {formError && <ErrorState message={formError} />}
      <FormField label={t('common.fields.name')} errors={errors.name} required={!role}>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} disabled={!!role && (role.is_system || !canEditDetails)} />
      </FormField>
      {role?.is_system && <div style={{ fontSize: 12, color: '#6b7280', marginTop: -10, marginBottom: 12 }}>{t('common.help.systemRoleNameFixedDescriptionPermissions')}</div>}
      <FormField label={t('common.fields.description')} errors={errors.description}>
        <input value={description ?? ''} onChange={(e) => setDescription(e.target.value)} style={inputStyle} disabled={!!role && !canEditDetails} />
      </FormField>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 6 }}>
        <label style={{ fontSize: 13, fontWeight: 600 }}>
          {t('common.fields.permissions')} <span style={{ fontWeight: 400, color: '#6b7280' }}>{t('common.fields.selectedCountOfPermissionsCountSelected', { selectedCount: selected.size, permissionsCount: permissions.length })}</span>
        </label>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <input placeholder={t('common.search.filterPermissions')} value={search} onChange={(e) => setSearch(e.target.value)} style={{ ...inputStyle, width: 200 }} />
          <button type="button" className="btn-link" disabled={!canEditPermissions} onClick={() => setMany(visibleIds, true)}>
            {needle ? t('common.actions.selectAllShown') : t('common.actions.selectAll')}
          </button>
          <button type="button" className="btn-link" disabled={!canEditPermissions} onClick={() => setMany(visibleIds, false)}>
            {needle ? t('common.actions.clearShown') : t('common.actions.clearAll')}
          </button>
        </div>
      </div>
      {errors.permission_ids && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 6 }}>{errors.permission_ids[0]}</div>}
      <div style={{ maxHeight: '50vh', overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6 }}>
        {visibleTree.length === 0 && <div style={{ padding: 12, fontSize: 13, color: '#6b7280' }}>{t('common.empty.noPermissionMatchesFilter')}</div>}
        {visibleTree.map((m) => {
          const moduleIds = m.features.flatMap((f) => idsOf(f.permissions));
          const isCollapsed = collapsed.has(m.module) && !needle;
          return (
            <div key={m.module} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', background: '#f9fafb', position: 'sticky', top: 0 }}>
                {checkbox(moduleIds, !canEditPermissions)}
                <button type="button" className="btn-link" style={{ fontWeight: 700, color: '#111827' }} onClick={() => toggleCollapsed(m.module)}>
                  {isCollapsed ? '▸' : '▾'} {m.label}
                </button>
                <span style={{ fontSize: 11, color: '#6b7280' }}>
                  {moduleIds.filter((id) => selected.has(id)).length}/{moduleIds.length}
                </span>
              </div>
              {!isCollapsed &&
                m.features.map((f) => {
                  const featureIds = idsOf(f.permissions);
                  return (
                    <div key={f.feature} style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'flex-start', gap: '4px 14px', padding: '6px 10px 6px 28px' }}>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, minWidth: 180 }}>
                        {checkbox(featureIds, !canEditPermissions)}
                        {f.label}
                      </label>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '2px 14px', flex: 1, minWidth: 200 }}>
                        {f.permissions.map((p) => (
                          <label key={p.id} title={p.name} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 13 }}>
                            <input type="checkbox" checked={selected.has(p.id)} disabled={!canEditPermissions} onChange={() => setMany([p.id], !selected.has(p.id))} />
                            {p.action_name ?? p.name}
                          </label>
                        ))}
                      </div>
                    </div>
                  );
                })}
            </div>
          );
        })}
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose} disabled={submitting}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || (!role && !name)} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
