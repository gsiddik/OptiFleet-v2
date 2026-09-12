import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { TireItem, VehicleItem } from '../../../types';

export function TireDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [tire, setTire] = useState<TireItem | null>(null);
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [vehicleId, setVehicleId] = useState('');
  const [wheelPosition, setWheelPosition] = useState('');
  const [odometer, setOdometer] = useState('');
  const [toPosition, setToPosition] = useState('');
  const [treadDepth, setTreadDepth] = useState('');
  const [pressure, setPressure] = useState('');
  const [recommendation, setRecommendation] = useState('');
  const [removalReason, setRemovalReason] = useState('');
  const [disposition, setDisposition] = useState('REUSE');
  const [scrapReason, setScrapReason] = useState('');

  // G-23: legacy onboarding — a normal live install leaves these untouched (undefined = "install now, known").
  const [showLegacyFields, setShowLegacyFields] = useState(false);
  const [installedAt, setInstalledAt] = useState('');
  const [installedAtSource, setInstalledAtSource] = useState('KNOWN');
  const [baselineTreadDepth, setBaselineTreadDepth] = useState('');
  const [baselineCondition, setBaselineCondition] = useState('');

  // G-24: swap positions with another currently-installed tire on the same vehicle.
  const [otherTires, setOtherTires] = useState<TireItem[]>([]);
  const [swapTireId, setSwapTireId] = useState('');

  // G-28: replace with a caller-chosen disposition for the outgoing tire.
  const [availableTires, setAvailableTires] = useState<TireItem[]>([]);
  const [replaceTireId, setReplaceTireId] = useState('');
  const [replaceReason, setReplaceReason] = useState('');
  const [replaceDisposition, setReplaceDisposition] = useState('REUSE');

  function load() {
    apiClient.get(`/app/tires/${id}`).then((res) => setTire(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data)).catch(() => setVehicles([]));
  }, []);
  useEffect(() => {
    if (!tire?.current_vehicle_id || !['INSTALLED', 'IN_USE'].includes(tire.current_status)) return;
    apiClient
      .get('/app/tires', { params: { current_vehicle_id: tire.current_vehicle_id, per_page: 100 } })
      .then((res) => setOtherTires((res.data.data as TireItem[]).filter((t) => t.id !== tire.id)))
      .catch(() => setOtherTires([]));
  }, [tire?.current_vehicle_id, tire?.current_status, tire?.id]);
  useEffect(() => {
    if (!['INSTALLED', 'IN_USE', 'UNDER_INSPECTION'].includes(tire?.current_status ?? '')) return;
    apiClient
      .get('/app/tires', { params: { current_status: 'IN_STOCK', per_page: 100 } })
      .then((res) => setAvailableTires(res.data.data))
      .catch(() => setAvailableTires([]));
  }, [tire?.current_status]);

  async function install() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/install`, {
        vehicle_id: vehicleId, wheel_position: wheelPosition, odometer: odometer || undefined,
        installed_at: showLegacyFields && installedAt ? installedAt : undefined,
        installed_at_source: showLegacyFields && installedAt ? installedAtSource : undefined,
        baseline_tread_depth_mm: showLegacyFields && baselineTreadDepth ? baselineTreadDepth : undefined,
        baseline_condition: showLegacyFields && baselineCondition ? baselineCondition : undefined,
      });
      setInstalledAt('');
      setInstalledAtSource('KNOWN');
      setBaselineTreadDepth('');
      setBaselineCondition('');
      setShowLegacyFields(false);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function rotate() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/rotate`, { to_position: toPosition, odometer: odometer || undefined });
      setToPosition('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function swapPositions() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/swap-positions`, { other_tire_id: swapTireId, odometer: odometer || undefined });
      setSwapTireId('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function replace() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/replace`, {
        new_tire_id: replaceTireId, reason: replaceReason, disposition: replaceDisposition, odometer: odometer || undefined,
      });
      setReplaceTireId('');
      setReplaceReason('');
      setReplaceDisposition('REUSE');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function inspect() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/inspect`, {
        tread_depth_mm: treadDepth || undefined, pressure_psi: pressure || undefined, recommendation: recommendation || undefined,
      });
      setTreadDepth('');
      setPressure('');
      setRecommendation('');
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
      await apiClient.post(`/app/tires/${id}/remove`, { removal_reason: removalReason, disposition, odometer: odometer || undefined });
      setRemovalReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function scrap() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/scrap`, { reason: scrapReason || undefined });
      setScrapReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function sendForRetread() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/retread`, {});
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function receiveRetread(retreadId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/retreads/${retreadId}/receive`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !tire) return <ErrorState message={error} />;
  if (!tire) return <LoadingState />;

  const canManage = hasPermission('tire.manage');
  const canInstall = hasPermission('tire.install');
  const canRotate = hasPermission('tire.rotate');
  const canInspect = hasPermission('tire.inspect');
  const canRemove = hasPermission('tire.remove');
  const canScrap = hasPermission('tire.scrap');

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{tire.serial_number}</h1>
        <StatusBadge status={tire.current_status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Product:</strong> {tire.product?.name ?? tire.product_id} &nbsp; <strong>Size:</strong> {tire.tire_size ?? '—'} &nbsp;
          <strong>Manufacturer:</strong> {tire.manufacturer ?? '—'} &nbsp;
          <strong>Date Code:</strong> {tire.manufacture_date_code ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Vehicle:</strong> {tire.current_vehicle?.registration_number ?? '—'} &nbsp; <strong>Position:</strong> {tire.current_position ?? '—'} &nbsp;
          <strong>Warehouse:</strong> {tire.current_warehouse?.name ?? '—'}
        </p>
      </div>

      {['IN_STOCK', 'RESERVED'].includes(tire.current_status) && canInstall && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Install</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Vehicle">
              <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">Select…</option>
                {vehicles.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.registration_number}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Wheel Position">
              <input value={wheelPosition} onChange={(e) => setWheelPosition(e.target.value)} placeholder="FRONT_LEFT" style={{ ...inputStyle, width: 150 }} />
            </FormField>
            <FormField label="Odometer">
              <input type="number" value={odometer} onChange={(e) => setOdometer(e.target.value)} style={{ ...inputStyle, width: 120 }} />
            </FormField>
            <button className="btn-primary" disabled={busy || !vehicleId || !wheelPosition} onClick={install} style={{ marginBottom: 14 }}>
              Install
            </button>
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, marginTop: 10 }}>
            <input type="checkbox" checked={showLegacyFields} onChange={(e) => setShowLegacyFields(e.target.checked)} />
            This tire was already mounted before today (legacy onboarding)
          </label>
          {showLegacyFields && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginTop: 10 }}>
              <FormField label="Actual/Estimated Install Date">
                <input type="date" value={installedAt} onChange={(e) => setInstalledAt(e.target.value)} style={{ ...inputStyle, width: 160 }} />
              </FormField>
              <FormField label="Date Confidence">
                <select value={installedAtSource} onChange={(e) => setInstalledAtSource(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                  {['KNOWN', 'ESTIMATED', 'UNKNOWN'].map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label="Baseline Tread Depth (mm)">
                <input type="number" step="0.1" value={baselineTreadDepth} onChange={(e) => setBaselineTreadDepth(e.target.value)} style={{ ...inputStyle, width: 150 }} />
              </FormField>
              <FormField label="Baseline Condition">
                <input value={baselineCondition} onChange={(e) => setBaselineCondition(e.target.value)} style={{ ...inputStyle, width: 150 }} />
              </FormField>
            </div>
          )}
        </div>
      )}

      {['INSTALLED', 'IN_USE'].includes(tire.current_status) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>In-Service Actions</h3>
          {canRotate && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
              <FormField label="Rotate To Position">
                <input value={toPosition} onChange={(e) => setToPosition(e.target.value)} placeholder="REAR_RIGHT" style={{ ...inputStyle, width: 150 }} />
              </FormField>
              <FormField label="Odometer">
                <input type="number" value={odometer} onChange={(e) => setOdometer(e.target.value)} style={{ ...inputStyle, width: 120 }} />
              </FormField>
              <button className="btn-secondary" disabled={busy || !toPosition} onClick={rotate} style={{ marginBottom: 14 }}>
                Rotate
              </button>
            </div>
          )}
          {canRotate && otherTires.length > 0 && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
              <FormField label="Swap Position With">
                <select value={swapTireId} onChange={(e) => setSwapTireId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                  <option value="">Select another installed tire…</option>
                  {otherTires.map((t) => (
                    <option key={t.id} value={t.id}>
                      {t.serial_number} ({t.current_position})
                    </option>
                  ))}
                </select>
              </FormField>
              <button className="btn-secondary" disabled={busy || !swapTireId} onClick={swapPositions} style={{ marginBottom: 14 }}>
                Swap Positions
              </button>
            </div>
          )}
          {canInspect && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
              <FormField label="Tread Depth (mm)">
                <input type="number" step="0.1" value={treadDepth} onChange={(e) => setTreadDepth(e.target.value)} style={{ ...inputStyle, width: 130 }} />
              </FormField>
              <FormField label="Pressure (psi)">
                <input type="number" step="0.1" value={pressure} onChange={(e) => setPressure(e.target.value)} style={{ ...inputStyle, width: 130 }} />
              </FormField>
              <FormField label="Recommendation">
                <input value={recommendation} onChange={(e) => setRecommendation(e.target.value)} style={{ ...inputStyle, width: 220 }} />
              </FormField>
              <button className="btn-secondary" disabled={busy} onClick={inspect} style={{ marginBottom: 14 }}>
                Record Inspection
              </button>
            </div>
          )}
          {canRemove && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <FormField label="Removal Reason">
                <input value={removalReason} onChange={(e) => setRemovalReason(e.target.value)} style={{ ...inputStyle, width: 220 }} />
              </FormField>
              <FormField label="Disposition">
                <select value={disposition} onChange={(e) => setDisposition(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                  {['REUSE', 'RETREAD', 'SCRAP'].map((d) => (
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
          )}
        </div>
      )}

      {['INSTALLED', 'IN_USE', 'UNDER_INSPECTION'].includes(tire.current_status) && canRemove && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Replace Tire</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Replacement Tire (from stock)">
              <select value={replaceTireId} onChange={(e) => setReplaceTireId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">Select…</option>
                {availableTires.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.serial_number}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Reason">
              <input value={replaceReason} onChange={(e) => setReplaceReason(e.target.value)} style={{ ...inputStyle, width: 220 }} />
            </FormField>
            <FormField label="Outgoing Tire Disposition">
              <select value={replaceDisposition} onChange={(e) => setReplaceDisposition(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                {['REUSE', 'RETREAD', 'SCRAP'].map((d) => (
                  <option key={d} value={d}>
                    {d}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Odometer">
              <input type="number" value={odometer} onChange={(e) => setOdometer(e.target.value)} style={{ ...inputStyle, width: 120 }} />
            </FormField>
            <button className="btn-secondary" disabled={busy || !replaceTireId || !replaceReason} onClick={replace} style={{ marginBottom: 14 }}>
              Replace
            </button>
          </div>
        </div>
      )}

      {tire.current_status === 'RETREAD' && canManage && (
        <div className="card" style={{ marginBottom: 16 }}>
          <button className="btn-secondary" disabled={busy} onClick={sendForRetread}>
            Send For Retread
          </button>
        </div>
      )}

      {['IN_STOCK', 'REMOVED', 'UNDER_INSPECTION'].includes(tire.current_status) && canScrap && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Scrap</h3>
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
            <FormField label="Reason">
              <input value={scrapReason} onChange={(e) => setScrapReason(e.target.value)} style={{ ...inputStyle, width: 260 }} />
            </FormField>
            <button className="btn-secondary" disabled={busy} onClick={scrap} style={{ marginBottom: 14 }}>
              Scrap Tire
            </button>
          </div>
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Retread History</h3>
        {(tire.retreads ?? []).length === 0 && <EmptyState label="No retread cycles." />}
        {(tire.retreads ?? []).map((r) => (
          <div key={r.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            <span>
              Cycle {r.cycle_number} — sent {r.sent_at} {r.received_at ? `— received ${r.received_at}` : '— pending'}
            </span>
            {!r.received_at && canManage && (
              <button className="btn-link" disabled={busy} onClick={() => receiveRetread(r.id)}>
                Receive
              </button>
            )}
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Installation History</h3>
        {(tire.installations ?? []).length === 0 && <EmptyState label="No installations yet." />}
        {(tire.installations ?? []).map((i) => (
          <div key={i.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {i.vehicle?.registration_number ?? i.vehicle_id} — {i.wheel_position} — installed {new Date(i.installed_at).toLocaleDateString()}
            {i.installation_date_source !== 'KNOWN' && ` (${i.installation_date_source})`}
            {i.removed_at && ` — removed ${new Date(i.removed_at).toLocaleDateString()}`}
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Rotation History</h3>
        {(tire.rotations ?? []).length === 0 && <EmptyState label="No rotations yet." />}
        {(tire.rotations ?? []).map((r) => (
          <div key={r.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {r.from_position ?? '—'} → {r.to_position} — {new Date(r.occurred_at).toLocaleDateString()}
          </div>
        ))}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Inspection History</h3>
        {(tire.inspections ?? []).length === 0 && <EmptyState label="No inspections yet." />}
        {(tire.inspections ?? []).map((i) => (
          <div key={i.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {new Date(i.inspected_at).toLocaleDateString()} — tread {i.tread_depth_mm ?? '—'}mm — pressure {i.pressure_psi ?? '—'}psi
            {i.recommendation && ` — ${i.recommendation}`}
          </div>
        ))}
      </div>
    </div>
  );
}
