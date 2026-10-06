import { useEffect, useState } from 'react';
import { apiClient } from '../../../api/client';
import { Modal } from '../../../components/Modal';
import { LoadingState } from '../../../components/States';
import { ComponentGroupManager } from '../../../components/masterdata/ComponentGroupManager';
import { useAuth } from '../../../auth/AuthContext';
import type { ComponentGroup, VehicleCategory } from '../../../types';
import { componentGroupLabel } from '../../../utils/componentGroup';
import { t } from '../../../i18n/i18n';

/**
 * Tenant view of the Component Group Master: the platform baseline (System,
 * read-only here) plus this tenant's own groups, which it fully manages.
 */
export function ComponentGroupsPage() {
  const { hasPermission } = useAuth();
  const [mapping, setMapping] = useState<ComponentGroup | null>(null);
  const [reloadMapping, setReloadMapping] = useState<(() => void) | null>(null);

  return (
    <>
      <ComponentGroupManager
        apiBase="/app/component-groups"
        canManageRow={(g) => !g.is_system}
        intro="System groups are the shared platform baseline and are read-only here; groups created by your organization can be edited and deleted."
        extraActions={(g, reload) =>
          hasPermission('component_group.map') && !g.is_deleted ? (
            <button
              className="btn-link"
              onClick={() => {
                setMapping(g);
                setReloadMapping(() => reload);
              }}
            >
              {t('masterData.actions.vehicleCategories')}
            </button>
          ) : null
        }
      />
      {mapping && (
        <VehicleCategoryMappingModal
          group={mapping}
          onClose={() => setMapping(null)}
          onSaved={() => {
            setMapping(null);
            reloadMapping?.();
          }}
        />
      )}
    </>
  );
}

function VehicleCategoryMappingModal({
  group,
  onClose,
  onSaved,
}: {
  group: ComponentGroup;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [allCategories, setAllCategories] = useState<VehicleCategory[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    Promise.all([
      apiClient.get('/app/vehicle-categories', { params: { per_page: 100 } }),
      apiClient.get(`/app/component-groups/${group.id}`),
    ]).then(([catRes, groupRes]) => {
      setAllCategories(catRes.data.data);
      const ids: string[] = groupRes.data.data.vehicle_categories.map((c: VehicleCategory) => c.id);
      setSelected(new Set(ids));
      setLoading(false);
    });
  }, [group.id]);

  function toggle(id: string) {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  }

  async function submit() {
    setSubmitting(true);
    try {
      await apiClient.post(`/app/component-groups/${group.id}/vehicle-categories`, {
        vehicle_category_ids: Array.from(selected),
      });
      onSaved();
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open title={t('masterData.modals.vehicleCategoriesValue', { value: componentGroupLabel(group) })} onClose={onClose} width={480}>
      {loading ? (
        <LoadingState />
      ) : (
        <div style={{ maxHeight: 340, overflowY: 'auto', border: '1px solid #e5e7eb', borderRadius: 6, padding: 10 }}>
          {allCategories.map((c) => (
            <label key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, padding: '3px 0' }}>
              <input type="checkbox" checked={selected.has(c.id)} onChange={() => toggle(c.id)} />
              {c.name}
            </label>
          ))}
        </div>
      )}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || loading} onClick={submit}>
          {submitting ? t('common.actions.saving') : t('masterData.actions.saveMapping')}
        </button>
      </div>
    </Modal>
  );
}
