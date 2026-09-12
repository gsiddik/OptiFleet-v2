import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { PartnerItem, TireItem, TireRepairItem, TireRetreadItem, VehicleItem } from '../../../types';

type CycleItem = TireRetreadItem | TireRepairItem;

/**
 * Phase E: one send -> receive -> final-inspect -> approve governance panel,
 * shared by the Retread and Repair sections below — they are two distinct
 * lifecycles (separate tables/endpoints, G-27) but an identical UI shape.
 */
function CycleGovernancePanel({
  label, tireStatus, sendReadyStatus, activeCycle, history, partners,
  canSend, canReceive, canInspect, canApprove, busy,
  sendState, onReceive, inspectState, approveState,
}: {
  label: string;
  tireStatus: string;
  sendReadyStatus: string;
  activeCycle: CycleItem | undefined;
  history: CycleItem[];
  partners: PartnerItem[];
  canSend: boolean;
  canReceive: boolean;
  canInspect: boolean;
  canApprove: boolean;
  busy: boolean;
  sendState: { partnerId: string; setPartnerId: (v: string) => void; cost: string; setCost: (v: string) => void; notes: string; setNotes: (v: string) => void; onSend: () => void };
  onReceive: (cycleId: string) => void;
  inspectState: { result: string; setResult: (v: string) => void; notes: string; setNotes: (v: string) => void; onInspect: (cycleId: string) => void };
  approveState: { disposition: string; setDisposition: (v: string) => void; reason: string; setReason: (v: string) => void; onApprove: (cycleId: string) => void };
}) {
  const showSendForm = tireStatus === sendReadyStatus && ! activeCycle && canSend;

  return (
    <div className="card" style={{ marginBottom: 16 }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>{label}</h3>

      {showSendForm && (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
          <FormField label="Partner (EXTERNAL_WORKSHOP or TIRE_SUPPLIER, ACTIVE)">
            <select value={sendState.partnerId} onChange={(e) => sendState.setPartnerId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
              <option value="">Select…</option>
              {partners.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} ({p.partner_type})
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Cost">
            <input type="number" value={sendState.cost} onChange={(e) => sendState.setCost(e.target.value)} style={{ ...inputStyle, width: 120 }} />
          </FormField>
          <FormField label="Notes">
            <input value={sendState.notes} onChange={(e) => sendState.setNotes(e.target.value)} style={{ ...inputStyle, width: 220 }} />
          </FormField>
          <button className="btn-primary" disabled={busy || !sendState.partnerId} onClick={sendState.onSend} style={{ marginBottom: 14 }}>
            Send For {label}
          </button>
        </div>
      )}

      {activeCycle && activeCycle.status === 'SENT' && (
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <span style={{ fontSize: 13 }}>Cycle {activeCycle.cycle_number} is out for {label.toLowerCase()} — sent {activeCycle.sent_at}.</span>
          {canReceive && (
            <button className="btn-secondary" disabled={busy} onClick={() => onReceive(activeCycle.id)}>
              Receive
            </button>
          )}
        </div>
      )}

      {activeCycle && activeCycle.status === 'RECEIVED' && (
        <div>
          <p style={{ fontSize: 13 }}>Cycle {activeCycle.cycle_number} was received {activeCycle.received_at} — pending final inspection. It will not return to stock until inspected and approved.</p>
          {canInspect && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
              <FormField label="Final Inspection Result (critical safety evaluation)">
                <select value={inspectState.result} onChange={(e) => inspectState.setResult(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                  <option value="SAFE">SAFE</option>
                  <option value="UNSAFE">UNSAFE</option>
                </select>
              </FormField>
              <FormField label="Notes">
                <input value={inspectState.notes} onChange={(e) => inspectState.setNotes(e.target.value)} style={{ ...inputStyle, width: 220 }} />
              </FormField>
              <button className="btn-secondary" disabled={busy} onClick={() => inspectState.onInspect(activeCycle.id)} style={{ marginBottom: 14 }}>
                Record Final Inspection
              </button>
            </div>
          )}
        </div>
      )}

      {activeCycle && activeCycle.status === 'FINAL_INSPECTED' && (
        <div>
          <p style={{ fontSize: 13 }}>
            Cycle {activeCycle.cycle_number} final inspection: <strong>{activeCycle.final_inspection_result}</strong>
            {activeCycle.final_inspection_notes && ` — ${activeCycle.final_inspection_notes}`}. Approval must come from an actor other than whoever received it.
          </p>
          {canApprove && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
              <FormField label="Disposition">
                <select value={approveState.disposition} onChange={(e) => approveState.setDisposition(e.target.value)} style={{ ...inputStyle, width: 170 }}>
                  <option value="RETURN_TO_SERVICE" disabled={activeCycle.final_inspection_result === 'UNSAFE'}>RETURN_TO_SERVICE</option>
                  <option value="SCRAP">SCRAP</option>
                  <option value="QUARANTINE">QUARANTINE</option>
                </select>
              </FormField>
              <FormField label="Reason (required, persisted)">
                <input value={approveState.reason} onChange={(e) => approveState.setReason(e.target.value)} style={{ ...inputStyle, width: 260 }} />
              </FormField>
              <button className="btn-secondary" disabled={busy || !approveState.reason} onClick={() => approveState.onApprove(activeCycle.id)} style={{ marginBottom: 14 }}>
                Approve
              </button>
            </div>
          )}
        </div>
      )}

      <h4 style={{ fontSize: 13, marginBottom: 6 }}>{label} History</h4>
      {history.length === 0 && <EmptyState label={`No ${label.toLowerCase()} cycles.`} />}
      {history.map((c) => (
        <div key={c.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
          Cycle {c.cycle_number} — {c.status} — sent {c.sent_at}
          {c.received_at && ` — received ${c.received_at}`}
          {c.final_inspection_result && ` — final inspection: ${c.final_inspection_result}`}
          {c.approval_disposition && ` — approved: ${c.approval_disposition}`}
          {c.approval_reason && ` (${c.approval_reason})`}
        </div>
      ))}
    </div>
  );
}

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

  // Phase E: retread/repair governance — send/receive/inspect/approve.
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [retreadPartnerId, setRetreadPartnerId] = useState('');
  const [retreadCost, setRetreadCost] = useState('');
  const [retreadNotes, setRetreadNotes] = useState('');
  const [repairPartnerId, setRepairPartnerId] = useState('');
  const [repairCost, setRepairCost] = useState('');
  const [repairNotes, setRepairNotes] = useState('');
  const [inspectResult, setInspectResult] = useState('SAFE');
  const [inspectNotes, setInspectNotes] = useState('');
  const [approveDisposition, setApproveDisposition] = useState('RETURN_TO_SERVICE');
  const [approveReason, setApproveReason] = useState('');

  // Phase F: structured scoring and the three-way sell split.
  const [scoringInspectionId, setScoringInspectionId] = useState('');
  const [scoringType, setScoringType] = useState('RETREAD');
  const [kaScore, setKaScore] = useState('');
  const [criticalSafetyFail, setCriticalSafetyFail] = useState(false);
  const [criticalSafetyReasons, setCriticalSafetyReasons] = useState('');
  const [sellType, setSellType] = useState('SELL_FOR_OPERATIONAL_REUSE');
  const [sellReason, setSellReason] = useState('');

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
  useEffect(() => {
    if (!['RETREAD', 'REPAIR'].includes(tire?.current_status ?? '')) return;
    apiClient.get('/app/partners', { params: { status: 'ACTIVE', per_page: 100 } }).then((res) => setPartners(res.data.data)).catch(() => setPartners([]));
  }, [tire?.current_status]);

  // A tire has at most one non-terminal cycle open at a time (G-29) — this is the one the UI acts on.
  const activeRetread = (tire?.retreads ?? []).find((r) => !['APPROVED', 'REJECTED'].includes(r.status));
  const activeRepair = (tire?.repairs ?? []).find((r) => !['APPROVED', 'REJECTED'].includes(r.status));

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
      await apiClient.post(`/app/tires/${id}/retread`, { partner_id: retreadPartnerId, cost: retreadCost || undefined, notes: retreadNotes || undefined });
      setRetreadPartnerId('');
      setRetreadCost('');
      setRetreadNotes('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function sendForRepair() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/repair`, { partner_id: repairPartnerId, cost: repairCost || undefined, notes: repairNotes || undefined });
      setRepairPartnerId('');
      setRepairCost('');
      setRepairNotes('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function receiveCycle(kind: 'retreads' | 'repairs', cycleId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/${kind}/${cycleId}/receive`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function finalInspectCycle(kind: 'retreads' | 'repairs', cycleId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/${kind}/${cycleId}/final-inspect`, { result: inspectResult, notes: inspectNotes || undefined });
      setInspectResult('SAFE');
      setInspectNotes('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function approveCycle(kind: 'retreads' | 'repairs', cycleId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/${kind}/${cycleId}/approve`, { disposition: approveDisposition, reason: approveReason });
      setApproveDisposition('RETURN_TO_SERVICE');
      setApproveReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function calculateScoring() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/scoring`, {
        tire_inspection_id: scoringInspectionId, scoring_type: scoringType,
        ka_score: kaScore || undefined, critical_safety_fail: criticalSafetyFail,
        critical_safety_reasons: criticalSafetyReasons || undefined,
        tire_retread_id: activeRetread?.id, tire_repair_id: activeRepair?.id,
      });
      setScoringInspectionId('');
      setKaScore('');
      setCriticalSafetyFail(false);
      setCriticalSafetyReasons('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function finalizeScoring(scoringResultId: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/scoring/${scoringResultId}/finalize`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function sell() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/tires/${id}/sell`, { sell_type: sellType, reason: sellReason });
      setSellReason('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !tire) return <ErrorState message={error} />;
  if (!tire) return <LoadingState />;

  const canInstall = hasPermission('tire.install');
  const canRotate = hasPermission('tire.rotate');
  const canInspect = hasPermission('tire.inspect');
  const canRemove = hasPermission('tire.remove');
  const canScrap = hasPermission('tire.scrap');
  const canRetreadSend = hasPermission('tire_retread.send');
  const canRetreadReceive = hasPermission('tire_retread.receive');
  const canRetreadInspect = hasPermission('tire_retread.inspect');
  const canRetreadApprove = hasPermission('tire_retread.approve');
  const canRepairSend = hasPermission('tire_repair.send');
  const canRepairReceive = hasPermission('tire_repair.receive');
  const canRepairInspect = hasPermission('tire_repair.inspect');
  const canRepairApprove = hasPermission('tire_repair.approve');
  const canScoringCalculate = hasPermission('tire_scoring.calculate');
  const canScoringFinalize = hasPermission('tire_scoring.finalize');
  const canSell = hasPermission('tire.sell');

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
                  {['REUSE', 'RETREAD', 'REPAIR', 'SCRAP'].map((d) => (
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
                {['REUSE', 'RETREAD', 'REPAIR', 'SCRAP'].map((d) => (
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

      {(tire.current_status === 'RETREAD' || (tire.retreads ?? []).length > 0) && (
        <CycleGovernancePanel
          label="Retread"
          tireStatus={tire.current_status}
          sendReadyStatus="RETREAD"
          activeCycle={activeRetread}
          history={tire.retreads ?? []}
          partners={partners}
          canSend={canRetreadSend}
          canReceive={canRetreadReceive}
          canInspect={canRetreadInspect}
          canApprove={canRetreadApprove}
          busy={busy}
          sendState={{ partnerId: retreadPartnerId, setPartnerId: setRetreadPartnerId, cost: retreadCost, setCost: setRetreadCost, notes: retreadNotes, setNotes: setRetreadNotes, onSend: sendForRetread }}
          onReceive={(cycleId) => receiveCycle('retreads', cycleId)}
          inspectState={{ result: inspectResult, setResult: setInspectResult, notes: inspectNotes, setNotes: setInspectNotes, onInspect: (cycleId) => finalInspectCycle('retreads', cycleId) }}
          approveState={{ disposition: approveDisposition, setDisposition: setApproveDisposition, reason: approveReason, setReason: setApproveReason, onApprove: (cycleId) => approveCycle('retreads', cycleId) }}
        />
      )}

      {(tire.current_status === 'REPAIR' || (tire.repairs ?? []).length > 0) && (
        <CycleGovernancePanel
          label="Repair"
          tireStatus={tire.current_status}
          sendReadyStatus="REPAIR"
          activeCycle={activeRepair}
          history={tire.repairs ?? []}
          partners={partners}
          canSend={canRepairSend}
          canReceive={canRepairReceive}
          canInspect={canRepairInspect}
          canApprove={canRepairApprove}
          busy={busy}
          sendState={{ partnerId: repairPartnerId, setPartnerId: setRepairPartnerId, cost: repairCost, setCost: setRepairCost, notes: repairNotes, setNotes: setRepairNotes, onSend: sendForRepair }}
          onReceive={(cycleId) => receiveCycle('repairs', cycleId)}
          inspectState={{ result: inspectResult, setResult: setInspectResult, notes: inspectNotes, setNotes: setInspectNotes, onInspect: (cycleId) => finalInspectCycle('repairs', cycleId) }}
          approveState={{ disposition: approveDisposition, setDisposition: setApproveDisposition, reason: approveReason, setReason: setApproveReason, onApprove: (cycleId) => approveCycle('repairs', cycleId) }}
        />
      )}

      {['IN_STOCK', 'REMOVED', 'UNDER_INSPECTION', 'QUARANTINED'].includes(tire.current_status) && canScrap && (
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

      {canScoringCalculate && !['INSTALLED', 'IN_USE', 'SOLD'].includes(tire.current_status) && (tire.inspections ?? []).length > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Structured Scoring</h3>
          <p style={{ fontSize: 12, color: '#6b7280' }}>
            Calculates SPA/KA/KF from a published REPAIR or RETREAD scoring configuration. If none is published for this tenant, this will fail —
            no scoring is invented without an approved configuration.
          </p>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginBottom: 12 }}>
            <FormField label="Source Inspection">
              <select value={scoringInspectionId} onChange={(e) => setScoringInspectionId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">Select…</option>
                {(tire.inspections ?? []).map((i) => (
                  <option key={i.id} value={i.id}>
                    {new Date(i.inspected_at).toLocaleDateString()} — tread {i.tread_depth_mm ?? '—'}mm
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Scoring Type">
              <select value={scoringType} onChange={(e) => setScoringType(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                <option value="RETREAD">RETREAD</option>
                <option value="REPAIR">REPAIR</option>
              </select>
            </FormField>
            <FormField label="KA Score">
              <input type="number" step="0.01" value={kaScore} onChange={(e) => setKaScore(e.target.value)} style={{ ...inputStyle, width: 110 }} />
            </FormField>
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, marginBottom: 10 }}>
            <input type="checkbox" checked={criticalSafetyFail} onChange={(e) => setCriticalSafetyFail(e.target.checked)} />
            Critical safety failure observed (overrides every score — tire can never be eligible for operational reuse)
          </label>
          {criticalSafetyFail && (
            <FormField label="Critical Safety Reason (required)">
              <input value={criticalSafetyReasons} onChange={(e) => setCriticalSafetyReasons(e.target.value)} style={{ ...inputStyle, width: 320, marginBottom: 10 }} />
            </FormField>
          )}
          <button className="btn-primary" disabled={busy || !scoringInspectionId} onClick={calculateScoring}>
            Calculate Score
          </button>

          <h4 style={{ fontSize: 13, marginTop: 16, marginBottom: 6 }}>Scoring History</h4>
          {(tire.scoringResults ?? []).length === 0 && <EmptyState label="No scoring results yet." />}
          {(tire.scoringResults ?? []).map((s) => (
            <div key={s.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
              <span>
                {s.scoring_type} — SPA {s.spa_raw_percent}% ({s.spa_normalized_score}) — {s.classification}
                {s.critical_safety_fail && ' — CRITICAL SAFETY FAIL'}
                {s.kf_score !== null && ` — KF ${s.kf_score}`}
                {s.finalized_at ? ` — finalized ${new Date(s.finalized_at).toLocaleDateString()}` : ' — draft'}
              </span>
              {!s.finalized_at && canScoringFinalize && (
                <button className="btn-link" disabled={busy} onClick={() => finalizeScoring(s.id)}>
                  Finalize
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {canSell && !['INSTALLED', 'IN_USE', 'SOLD'].includes(tire.current_status) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Sell</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label="Sell Type">
              <select value={sellType} onChange={(e) => setSellType(e.target.value)} style={{ ...inputStyle, width: 260 }}>
                <option value="SELL_FOR_OPERATIONAL_REUSE">SELL_FOR_OPERATIONAL_REUSE (requires an eligible score)</option>
                <option value="SELL_AS_RETREADABLE_CASING">SELL_AS_RETREADABLE_CASING</option>
                <option value="SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL">SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL</option>
              </select>
            </FormField>
            <FormField label="Reason">
              <input value={sellReason} onChange={(e) => setSellReason(e.target.value)} style={{ ...inputStyle, width: 260 }} />
            </FormField>
            <button className="btn-secondary" disabled={busy || !sellReason} onClick={sell} style={{ marginBottom: 14 }}>
              Sell Tire
            </button>
          </div>
          {(tire.sales ?? []).length > 0 && (
            <>
              <h4 style={{ fontSize: 13, marginBottom: 6 }}>Sale History</h4>
              {(tire.sales ?? []).map((s) => (
                <div key={s.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
                  {s.sell_type} — {new Date(s.sold_at).toLocaleDateString()} — {s.reason}
                </div>
              ))}
            </>
          )}
        </div>
      )}

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
