import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { BackButton } from '../../../components/BackButton';
import { useBackNavigation } from '../../../navigation/useBackNavigation';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { useAuth } from '../../../auth/AuthContext';
import type { BundleItem, ModuleCatalogItem } from '../../../types';

export function BundleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const goBackToList = useBackNavigation('/platform/bundles');
  const [bundle, setBundle] = useState<BundleItem | null>(null);
  const [allModules, setAllModules] = useState<ModuleCatalogItem[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [missing, setMissing] = useState<string[]>([]);
  const [autoAdded, setAutoAdded] = useState<{ module: ModuleCatalogItem; required_by: string[] }[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [statusBusy, setStatusBusy] = useState(false);

  function load() {
    Promise.all([apiClient.get(`/platform/bundles/${id}`), apiClient.get('/platform/modules')])
      .then(([bRes, mRes]) => {
        setBundle(bRes.data.data);
        setAllModules(mRes.data.data);
        setSelected(new Set((bRes.data.data.modules ?? []).map((m: ModuleCatalogItem) => m.id)));
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(bundle?.id, bundle ? `${bundle.name} (${bundle.code})` : undefined);

  function toggle(moduleId: string) {
    const next = new Set(selected);
    if (next.has(moduleId)) next.delete(moduleId);
    else next.add(moduleId);
    setSelected(next);
  }

  async function saveComposition() {
    setSaving(true);
    setError(null);
    try {
      const res = await apiClient.put(`/platform/bundles/${id}/modules`, { module_ids: Array.from(selected) });
      setAutoAdded(res.data.data.auto_added ?? []);
      const missingRes = await apiClient.get(`/platform/bundles/${id}/missing-dependencies`);
      setMissing(missingRes.data.data);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSaving(false);
    }
  }

  async function publish() {
    setPublishing(true);
    setError(null);
    try {
      await apiClient.post(`/platform/bundles/${id}/publish`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setPublishing(false);
    }
  }

  async function toggleActive() {
    if (!bundle) return;
    const action = bundle.is_active ? 'deactivate' : 'reactivate';
    const confirmed = window.confirm(
      bundle.is_active
        ? 'Deactivate this bundle? It will no longer be selectable for new contracts, but existing contracts referencing it are unaffected.'
        : 'Reactivate this bundle so it can be selected for new contracts again?',
    );
    if (!confirmed) return;
    setStatusBusy(true);
    setError(null);
    try {
      await apiClient.post(`/platform/bundles/${id}/${action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setStatusBusy(false);
    }
  }

  async function deleteBundle() {
    const confirmed = window.confirm(
      'Delete this bundle? It will be hidden from Bundle Management and new contracts, but existing contracts that already reference it keep working — this cannot be undone from here.',
    );
    if (!confirmed) return;
    setStatusBusy(true);
    setError(null);
    try {
      await apiClient.delete(`/platform/bundles/${id}`);
      goBackToList();
    } catch (err) {
      setError(extractApiError(err).message);
      setStatusBusy(false);
    }
  }

  if (error && !bundle) return <ErrorState message={error} />;
  if (!bundle) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/platform/bundles" label="← Back to Bundle Management" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 8 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {bundle.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({bundle.code})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusBadge status={bundle.status} />
          <StatusBadge status={bundle.is_active ? 'ACTIVE' : 'INACTIVE'} />
          {hasPermission('bundle.publish') && (
            <button className="btn-primary" disabled={publishing} onClick={publish}>
              {publishing ? 'Publishing…' : 'Publish New Version'}
            </button>
          )}
          {hasPermission(bundle.is_active ? 'bundle.deactivate' : 'bundle.activate') && (
            <button className="btn-secondary" disabled={statusBusy} onClick={toggleActive}>
              {bundle.is_active ? 'Deactivate' : 'Reactivate'}
            </button>
          )}
          {hasPermission('bundle.delete') && (
            <button className="btn-danger" disabled={statusBusy} onClick={deleteBundle}>
              Delete
            </button>
          )}
        </div>
      </div>

      {error && <ErrorState message={error} />}
      {missing.length > 0 && (
        <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 12, borderRadius: 8, marginBottom: 16, fontSize: 13 }}>
          Missing required dependencies: {missing.join(', ')}
        </div>
      )}
      {autoAdded.length > 0 && (
        <div style={{ background: '#eff6ff', color: '#1d4ed8', padding: 12, borderRadius: 8, marginBottom: 16, fontSize: 13 }}>
          Automatically added by dependency:{' '}
          {autoAdded.map((a) => `${a.module.name} (${a.module.code}) — required by ${a.required_by.join(', ')}`).join('; ')}
        </div>
      )}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Module Composition</h3>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 4 }}>
          {allModules.map((m) => (
            <label key={m.id} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, padding: '3px 0' }}>
              <input
                type="checkbox"
                checked={selected.has(m.id)}
                disabled={!hasPermission('bundle.update')}
                onChange={() => toggle(m.id)}
              />
              {m.name} <span style={{ color: '#9ca3af' }}>({m.code})</span>
            </label>
          ))}
        </div>
        {hasPermission('bundle.update') && (
          <button className="btn-secondary" style={{ marginTop: 16 }} disabled={saving} onClick={saveComposition}>
            {saving ? 'Saving…' : 'Save Composition'}
          </button>
        )}
      </div>
    </div>
  );
}
