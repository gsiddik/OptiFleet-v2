import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { ComponentAssetItem, VehicleItem } from '../../../types';

export function ComponentAssetDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [asset, setAsset] = useState<ComponentAssetItem | null>(null);
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [vehicleId, setVehicleId] = useState('');
  const [positionLocation, setPositionLocation] = useState('');
  const [removalReason, setRemovalReason] = useState('');
  const [disposition, setDisposition] = useState('REUSE');
  const [repairDescription, setRepairDescription] = useState('');
  const [repairOutcome, setRepairOutcome] = useState('RETURNED_TO_SERVICE');

  function load() {
    apiClient.get(`/app/component-assets/${id}`).then((res) => setAsset(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data)).catch(() => setVehicles([]));
  }, []);

  async function install() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/install`, { vehicle_id: vehicleId, position_location: positionLocation || undefined });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/remove`, { removal_reason: removalReason, disposition });
      setRemovalReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function startRepair() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/repairs`, { description: repairDescription });
      setRepairDescription('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function completeRepair(repairId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/component-assets/${id}/repairs/${repairId}/complete`, { outcome: repairOutcome });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  useBreadcrumbLabel(asset?.id, asset?.serial_number ?? asset?.asset_number);

  if (error && !asset) return <ErrorState message={error} />;
  if (!asset) return <LoadingState />;

  const canInstall = hasPermission('component_asset.install');
  const canRemove = hasPermission('component_asset.remove');
  const canManage = hasPermission('component_asset.manage');
  const openRepair = (asset.repairs ?? []).find((r) => !r.completed_at);

  return (
    <div>
      <BackButton fallbackTo="/app/component-assets" label="← Back to Component Assets" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{asset.serial_number ?? asset.asset_number ?? 'Component Asset'}</h1>
        <StatusBadge status={asset.current_status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Product:</strong> {asset.product?.name ?? '—'} &nbsp; <strong>Group:</strong> {asset.component_group?.name ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Vehicle:</strong> {asset.current_vehicle?.registration_number ?? '—'}
        </p>
      </div>

      {asset.current_status === 'IN_STOCK' && canInstall && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Install</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Vehicle" required>
              <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">Select…</option>
                {vehicles.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.registration_number}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Position / Location">
              <input value={positionLocation} onChange={(e) => setPositionLocation(e.target.value)} style={{ ...inputStyle, width: 200 }} />
            </FormField>
            <button className="btn-primary" disabled={busy || !vehicleId} onClick={install} style={{ marginBottom: 14 }}>
              Install
            </button>
          </div>
        </div>
      )}

      {['INSTALLED', 'ACTIVE', 'FAILED'].includes(asset.current_status) && canRemove && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Remove / Replace</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Removal Reason" required>
              <input value={removalReason} onChange={(e) => setRemovalReason(e.target.value)} style={{ ...inputStyle, width: 220 }} />
            </FormField>
            <FormField label="Disposition" required>
              <select value={disposition} onChange={(e) => setDisposition(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                {['REUSE', 'REPAIR', 'SCRAP'].map((d) => (
                  <option key={d} value={d}>
                    {d}
                  </option>
                ))}
              </select>
            </FormField>
            <button className="btn-secondary" disabled={busy || !removalReason} onClick={remove} style={{ marginBottom: 14 }}>
              Remove From Vehicle
            </button>
          </div>
        </div>
      )}

      {asset.current_status === 'UNDER_REPAIR' && canManage && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Repair</h3>
          {!openRepair ? (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <FormField label="Description" required>
                <input value={repairDescription} onChange={(e) => setRepairDescription(e.target.value)} style={{ ...inputStyle, width: 260 }} />
              </FormField>
              <button className="btn-secondary" disabled={busy || !repairDescription} onClick={startRepair} style={{ marginBottom: 14 }}>
                Start Repair
              </button>
            </div>
          ) : (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <FormField label="Outcome" required>
                <select value={repairOutcome} onChange={(e) => setRepairOutcome(e.target.value)} style={{ ...inputStyle, width: 200 }}>
                  {['RECONDITIONED', 'SCRAPPED', 'RETURNED_TO_SERVICE'].map((o) => (
                    <option key={o} value={o}>
                      {o}
                    </option>
                  ))}
                </select>
              </FormField>
              <button className="btn-primary" disabled={busy} onClick={() => completeRepair(openRepair.id)} style={{ marginBottom: 14 }}>
                Complete Repair
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Installation History</h3>
        {(asset.installations ?? []).length === 0 && <EmptyState label="No installations yet." />}
        {(asset.installations ?? []).map((i) => (
          <div key={i.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {i.vehicle?.registration_number ?? i.vehicle_id} — {i.position_location ?? '—'} — installed {new Date(i.installed_at).toLocaleDateString()}
            {i.removed_at && ` — removed ${new Date(i.removed_at).toLocaleDateString()}`}
          </div>
        ))}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Removal / Repair History</h3>
        {(asset.removals ?? []).length === 0 && <EmptyState label="No removals yet." />}
        {(asset.removals ?? []).map((r) => (
          <div key={r.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {new Date(r.removed_at).toLocaleDateString()} — {r.removal_reason} — {r.disposition}
          </div>
        ))}
      </div>
    </div>
  );
}
