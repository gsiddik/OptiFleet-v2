import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { StockOpnameItem } from '../../../types';

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'counting', label: 'Start Counting', permission: 'inventory.stock_opname', primary: true }],
  COUNTING: [{ action: 'submit', label: 'Submit', permission: 'inventory.stock_opname', primary: true }],
  SUBMITTED: [{ action: 'approve', label: 'Approve', permission: 'inventory.stock_opname', primary: true }],
  APPROVED: [{ action: 'post', label: 'Post Variance', permission: 'inventory.stock_opname', primary: true }],
};

export function StockOpnameDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [opname, setOpname] = useState<StockOpnameItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [counts, setCounts] = useState<Record<string, string>>({});

  function load() {
    apiClient
      .get(`/app/stock-opnames/${id}`)
      .then((res) => setOpname(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function act(action: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/stock-opnames/${id}/${action === 'counting' ? 'counting' : action}`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function recordCount(itemId: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/stock-opnames/${id}/items/${itemId}/count`, { physical_quantity: counts[itemId] });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !opname) return <ErrorState message={error} />;
  if (!opname) return <LoadingState />;

  const actions = (LIFECYCLE[opname.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{opname.opname_number}</h1>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusBadge status={opname.status} />
          {actions.map((a) => (
            <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy} onClick={() => act(a.action)}>
              {a.label}
            </button>
          ))}
        </div>
      </div>
      {error && <ErrorState message={error} />}

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Count Sheet — {opname.warehouse?.name ?? opname.warehouse_id}</h3>
        {(opname.items ?? []).map((item) => (
          <div key={item.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            <span>
              {item.product?.name ?? item.product_id} — system: {item.system_quantity}
              {item.physical_quantity !== null && ` — counted: ${item.physical_quantity}`}
            </span>
            {opname.status === 'COUNTING' && hasPermission('inventory.stock_opname') && (
              <div style={{ display: 'flex', gap: 6 }}>
                <input
                  type="number" step="0.0001" placeholder="Physical qty" value={counts[item.id] ?? ''}
                  onChange={(e) => setCounts((c) => ({ ...c, [item.id]: e.target.value }))}
                  style={{ ...inputStyle, width: 110 }}
                />
                <button className="btn-secondary" disabled={busy || !counts[item.id]} onClick={() => recordCount(item.id)}>
                  Record
                </button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
