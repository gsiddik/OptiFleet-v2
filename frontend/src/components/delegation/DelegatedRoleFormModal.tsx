import { useMemo, useState } from 'react';
import { classifyFailure, delegationApi, extractApiError, type CatalogModule, type DelegatedRole } from '../../api/delegation';
import type { ApiErrorShape } from '../../api/client';
import { FormField, inputStyle } from '../FormField';
import { Modal } from '../Modal';
import { FailurePanel } from './DelegationPanels';
import { t } from '../../i18n/i18n';

/**
 * Create or change a custom role. Only the permissions OptiNexus lets this organization delegate are offered, grouped
 * Module -> Feature -> Action like the local role editor; whether a selection is accepted is decided by OptiNexus.
 */
export function DelegatedRoleFormModal({
  modules,
  role,
  canEditPermissions,
  onClose,
  onSaved,
}: {
  modules: CatalogModule[];
  role?: DelegatedRole;
  canEditPermissions: boolean;
  onClose: () => void;
  onSaved: () => void;
}) {
  const offered = useMemo(() => new Set(modules.flatMap((m) => m.features.flatMap((f) => f.permissions.map((p) => p.key)))), [modules]);
  const [name, setName] = useState(role?.name ?? '');
  const [description, setDescription] = useState(role?.description ?? '');
  const [selected, setSelected] = useState<Set<string>>(() => new Set((role?.permissions ?? []).filter((k) => offered.has(k))));
  const [search, setSearch] = useState('');
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<ApiErrorShape | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Permissions the role holds that can no longer be delegated: saving the permissions removes them.
  const dropped = (role?.permissions ?? []).filter((k) => !offered.has(k));
  const refused = failure?.details?.permissions;
  const refusedPermissions = Array.isArray(refused) ? refused.map(String) : [];
  const needle = search.trim().toLowerCase();
  const visible = useMemo(() => {
    if (!needle) return modules;
    return modules
      .map((m) => ({
        ...m,
        features: m.features
          .map((f) => ({ ...f, permissions: f.permissions.filter((p) => `${m.name} ${f.name} ${p.name} ${p.action_name}`.toLowerCase().includes(needle)) }))
          .filter((f) => f.permissions.length > 0),
      }))
      .filter((m) => m.features.length > 0);
  }, [modules, needle]);

  const keysOf = (m: CatalogModule) => m.features.flatMap((f) => f.permissions.map((p) => p.key));
  const allOf = (keys: string[]) => keys.length > 0 && keys.every((k) => selected.has(k));
  const someOf = (keys: string[]) => keys.some((k) => selected.has(k));
  const visibleKeys = visible.flatMap(keysOf);

  function setMany(keys: string[], on: boolean) {
    setSelected((current) => {
      const next = new Set(current);
      keys.forEach((k) => (on ? next.add(k) : next.delete(k)));
      return next;
    });
  }

  function toggleCollapsed(code: string) {
    setCollapsed((current) => {
      const next = new Set(current);
      if (next.has(code)) next.delete(code);
      else next.add(code);
      return next;
    });
  }

  async function submit() {
    setSubmitting(true);
    setErrors({});
    setFailure(null);
    try {
      const permissions = Array.from(selected);
      if (role) {
        await delegationApi.updateRole(role.id, { name, description: description || null, ...(canEditPermissions ? { permissions } : {}) });
      } else {
        await delegationApi.createRole({ name, description: description || null, permissions });
      }
      onSaved();
    } catch (err) {
      const error = extractApiError(err);
      setErrors(error.errors ?? {});
      setFailure(error);
    } finally {
      setSubmitting(false);
    }
  }

  const checkbox = (keys: string[], disabled: boolean, label: string) => (
    <input
      type="checkbox"
      aria-label={label}
      checked={allOf(keys)}
      ref={(el) => {
        if (el) el.indeterminate = !allOf(keys) && someOf(keys);
      }}
      disabled={disabled}
      onChange={() => setMany(keys, !allOf(keys))}
    />
  );

  return (
    <Modal open title={role ? t('delegation.roles.editTitle', { name: role.name }) : t('delegation.roles.newTitle')} onClose={onClose} width={760}>
      {failure && (
        <div style={{ marginBottom: 12 }}>
          <FailurePanel kind={classifyFailure(failure)} error={failure} />
          {refusedPermissions.length > 0 && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{refusedPermissions.join(', ')}</div>}
        </div>
      )}
      <FormField label={t('common.fields.name')} errors={errors.name} required>
        <input value={name} maxLength={120} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.description')} errors={errors.description}>
        <input value={description ?? ''} maxLength={500} onChange={(e) => setDescription(e.target.value)} style={inputStyle} />
      </FormField>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 6 }}>
        <label style={{ fontSize: 13, fontWeight: 600 }}>
          {t('common.fields.permissions')}{' '}
          <span style={{ fontWeight: 400, color: '#6b7280' }}>{t('delegation.roles.selectedOf', { selected: selected.size, total: offered.size })}</span>
        </label>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <input placeholder={t('common.search.filterPermissions')} aria-label={t('common.search.filterPermissions')} value={search} onChange={(e) => setSearch(e.target.value)} style={{ ...inputStyle, width: 200 }} />
          <button type="button" className="btn-link" disabled={!canEditPermissions} onClick={() => setMany(visibleKeys, true)}>
            {needle ? t('common.actions.selectAllShown') : t('common.actions.selectAll')}
          </button>
          <button type="button" className="btn-link" disabled={!canEditPermissions} onClick={() => setMany(visibleKeys, false)}>
            {needle ? t('common.actions.clearShown') : t('common.actions.clearAll')}
          </button>
        </div>
      </div>
      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>{t('delegation.roles.onlyDelegable')}</div>
      {errors.permissions && <div style={{ color: '#b91c1c', fontSize: 12, marginBottom: 6 }}>{errors.permissions[0]}</div>}
      {dropped.length > 0 && canEditPermissions && (
        <div role="note" style={{ background: '#fffbeb', border: '1px solid #fde68a', color: '#92400e', borderRadius: 6, padding: '6px 10px', fontSize: 12, marginBottom: 6 }}>
          {t('delegation.roles.droppedWarning', { count: dropped.length })}
        </div>
      )}
      <div style={{ maxHeight: '50vh', overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6 }}>
        {modules.length === 0 && <div style={{ padding: 12, fontSize: 13, color: '#6b7280' }}>{t('delegation.roles.nothingDelegable')}</div>}
        {modules.length > 0 && visible.length === 0 && <div style={{ padding: 12, fontSize: 13, color: '#6b7280' }}>{t('common.empty.noPermissionMatchesFilter')}</div>}
        {visible.map((m) => {
          const moduleKeys = keysOf(m);
          const isCollapsed = collapsed.has(m.code) && !needle;
          return (
            <div key={m.code} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', background: '#f9fafb', position: 'sticky', top: 0 }}>
                {checkbox(moduleKeys, !canEditPermissions, m.name)}
                <button type="button" className="btn-link" aria-expanded={!isCollapsed} style={{ fontWeight: 700, color: '#111827' }} onClick={() => toggleCollapsed(m.code)}>
                  {isCollapsed ? '▸' : '▾'} {m.name}
                </button>
                <span style={{ fontSize: 11, color: '#6b7280' }}>
                  {moduleKeys.filter((k) => selected.has(k)).length}/{moduleKeys.length}
                </span>
              </div>
              {!isCollapsed &&
                m.features.map((f) => {
                  const featureKeys = f.permissions.map((p) => p.key);
                  return (
                    <div key={f.code} style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'flex-start', gap: '4px 14px', padding: '6px 10px 6px 28px' }}>
                      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600, minWidth: 180 }}>
                        {checkbox(featureKeys, !canEditPermissions, f.name)}
                        {f.name}
                      </label>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '2px 14px', flex: 1, minWidth: 200 }}>
                        {f.permissions.map((p) => (
                          <label key={p.key} title={p.description ?? p.name} style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 13 }}>
                            <input type="checkbox" checked={selected.has(p.key)} disabled={!canEditPermissions} onChange={() => setMany([p.key], !selected.has(p.key))} />
                            {p.action_name}
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
        <button className="btn-primary" disabled={submitting || !name || (!role && selected.size === 0)} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('common.actions.save')}
        </button>
      </div>
    </Modal>
  );
}
