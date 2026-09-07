import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { inputStyle } from '../../../components/FormField';
import { useAuth } from '../../../auth/AuthContext';
import type { ModuleCatalogItem } from '../../../types';

interface DependencyData {
  direct_dependencies: ModuleCatalogItem[];
  transitive_dependencies: ModuleCatalogItem[];
  direct_dependents: ModuleCatalogItem[];
  transitive_dependents: ModuleCatalogItem[];
}

export function ModuleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [module, setModule] = useState<ModuleCatalogItem | null>(null);
  const [deps, setDeps] = useState<DependencyData | null>(null);
  const [allModules, setAllModules] = useState<ModuleCatalogItem[]>([]);
  const [selected, setSelected] = useState('');
  const [error, setError] = useState<string | null>(null);

  function load() {
    Promise.all([
      apiClient.get(`/platform/modules/${id}`),
      apiClient.get(`/platform/modules/${id}/dependencies`),
      apiClient.get('/platform/modules'),
    ])
      .then(([modRes, depRes, allRes]) => {
        setModule(modRes.data.data);
        setDeps(depRes.data.data);
        setAllModules(allRes.data.data);
      })
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function addDependency() {
    if (!selected) return;
    setError(null);
    try {
      await apiClient.post(`/platform/modules/${id}/dependencies`, { depends_on_module_id: selected });
      setSelected('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function removeDependency(dependsOnId: string) {
    await apiClient.delete(`/platform/modules/${id}/dependencies/${dependsOnId}`);
    load();
  }

  if (error) return <ErrorState message={error} />;
  if (!module || !deps) return <LoadingState />;

  const availableToAdd = allModules.filter(
    (m) => m.id !== module.id && !deps.direct_dependencies.some((d) => d.id === m.id),
  );

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>
        {module.name} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({module.code})</span>
      </h1>
      <p style={{ color: '#6b7280', marginBottom: 24 }}>{module.description ?? 'No description.'}</p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 20 }}>
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Direct Dependencies</h3>
          {deps.direct_dependencies.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>None</p>}
          <ul style={{ paddingLeft: 18, fontSize: 14 }}>
            {deps.direct_dependencies.map((d) => (
              <li key={d.id} style={{ marginBottom: 4 }}>
                {d.name} ({d.code})
                {hasPermission('module.manage') && (
                  <button className="btn-link" onClick={() => removeDependency(d.id)}>
                    remove
                  </button>
                )}
              </li>
            ))}
          </ul>

          {hasPermission('module.manage') && (
            <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
              <select value={selected} onChange={(e) => setSelected(e.target.value)} style={{ ...inputStyle, flex: 1 }}>
                <option value="">Select module…</option>
                {availableToAdd.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name} ({m.code})
                  </option>
                ))}
              </select>
              <button className="btn-secondary" onClick={addDependency}>
                Add
              </button>
            </div>
          )}

          <h4 style={{ fontSize: 13, color: '#6b7280', marginTop: 16 }}>Transitive Dependencies</h4>
          <div style={{ fontSize: 13 }}>{deps.transitive_dependencies.map((d) => d.code).join(', ') || '—'}</div>
        </div>

        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Direct Dependents (reverse)</h3>
          {deps.direct_dependents.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>None</p>}
          <ul style={{ paddingLeft: 18, fontSize: 14 }}>
            {deps.direct_dependents.map((d) => (
              <li key={d.id}>
                {d.name} ({d.code})
              </li>
            ))}
          </ul>
          <h4 style={{ fontSize: 13, color: '#6b7280', marginTop: 16 }}>Transitive Dependents</h4>
          <div style={{ fontSize: 13 }}>{deps.transitive_dependents.map((d) => d.code).join(', ') || '—'}</div>
        </div>
      </div>
    </div>
  );
}
