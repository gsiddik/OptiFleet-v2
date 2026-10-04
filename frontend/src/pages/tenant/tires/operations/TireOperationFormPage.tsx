import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { BackButton } from '../../../../components/BackButton';
import { ConfirmDialog } from '../../../../components/ConfirmDialog';
import { FormField, inputStyle } from '../../../../components/FormField';
import { NumericInput } from '../../../../components/NumericInput';
import { SearchableSelect, type SearchableOption } from '../../../../components/SearchableSelect';
import { ErrorState, LoadingState } from '../../../../components/States';
import { StatusBadge } from '../../../../components/StatusBadge';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { useBreadcrumbLabel } from '../../../../navigation/BreadcrumbLabelContext';
import { TIME_PATTERN, autoColon, todayIso } from '../../../../utils/timeInput';
import { WheelConfigurationPreview } from '../wheel-configuration/WheelConfigurationPreview';
import { bodyStyleFor, type VehicleType } from '../wheel-configuration/vehicleTypes';
import { ReplacementArrow, RotationArrows, TireOperationCard } from './TireOperationParts';
import { INSPECTION_COLOR, REPLACEMENT_COLOR, ROTATION_PALETTE } from './tireOperationFormat';
import {
  OPERATION_TYPES,
  OPERATION_TYPE_LABEL,
  type OperationContext,
  type ReplacementCandidate,
  type TireOperationDetail,
  type TireOperationPayload,
  type TireOperationType,
} from './tireOperationTypes';
import { UsageRestrictionWarnings } from './UsageRestrictionWarnings';
import { outsideAllowedPositions, restrictionText } from './usageRestrictions';

interface Pair {
  from: string;
  to: string;
  color: string;
}

/**
 * Add New / Edit Tire Operation. Left: Tire Operations info and the section of the chosen
 * operation (cards per selected position); right: the vehicle's Wheels Configuration preview
 * (the existing renderer), where positions are picked. The backend validates everything again;
 * the client only guides the selection.
 *
 *   Replacement  click positions to select / unselect (brick red); each gets Installed Tire ↓ Replacing With
 *   Rotation     Step 1 pick the position to rotate, Step 2 the one it rotates with → one coloured pair;
 *                clicking a paired position again removes its pair
 *   Inspection   every position is selected when the operation is chosen; click to unselect
 */
export function TireOperationFormPage() {
  const { id } = useParams<{ id: string }>();
  const editing = id !== undefined;
  const navigate = useNavigate();

  const [existing, setExisting] = useState<TireOperationDetail | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [vehicle, setVehicle] = useState<SearchableOption | null>(null);
  const [context, setContext] = useState<OperationContext | null>(null);
  const [contextError, setContextError] = useState<string | null>(null);
  const [type, setType] = useState<TireOperationType | ''>('');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [km, setKm] = useState('');
  const [workshopId, setWorkshopId] = useState('');
  const [workshops, setWorkshops] = useState<{ id: string; name: string }[]>([]);
  const [selected, setSelected] = useState<string[]>([]);
  const [replacements, setReplacements] = useState<Record<string, string>>({});
  const [treads, setTreads] = useState<Record<string, string>>({});
  const [pairs, setPairs] = useState<Pair[]>([]);
  const [pending, setPending] = useState<string | null>(null);
  const [candidates, setCandidates] = useState<Record<string, ReplacementCandidate[]>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);

  useBreadcrumbLabel(id, existing ? `Edit ${existing.work_order?.wo_number ?? ''}` : null);

  // Edit: load the operation and pre-fill (the vehicle is fixed).
  useEffect(() => {
    if (!editing) return;
    apiClient
      .get(`/app/tire-operations/${id}`)
      .then((res) => {
        const op: TireOperationDetail = res.data.data;
        setExisting(op);
        setVehicle({ value: op.vehicle.id, label: op.vehicle.registration_number ?? op.vehicle.id });
        setType(op.operation_type);
        setDate(op.operated_date);
        setTime(op.operated_time);
        setKm(op.odometer);
        if (op.operation_type === 'ROTATION') {
          const byPair = new Map<number, string[]>();
          op.items.forEach((i) => byPair.set(i.pair_number ?? 0, [...(byPair.get(i.pair_number ?? 0) ?? []), i.position_code]));
          setPairs([...byPair.values()].map(([from, to], n) => ({ from, to, color: ROTATION_PALETTE[n % ROTATION_PALETTE.length] })));
        } else {
          setSelected(op.items.map((i) => i.position_code));
          setReplacements(Object.fromEntries(op.items.filter((i) => i.replacement_tire).map((i) => [i.position_code, i.replacement_tire!.id])));
          setTreads(Object.fromEntries(op.items.filter((i) => i.tread_depth_mm != null).map((i) => [i.position_code, String(i.tread_depth_mm)])));
        }
      })
      .catch((e) => setLoadError(extractApiError(e).message));
  }, [editing, id]);

  // Vehicle → its mapped configuration, positions and tire cards.
  useEffect(() => {
    if (!vehicle) return;
    let cancelled = false;
    apiClient
      .get(`/app/vehicles/${vehicle.value}/tire-operation-context`, { params: { operation_id: id } })
      .then((res) => {
        if (cancelled) return;
        setContext(res.data.data);
        setContextError(null);
      })
      .catch((e) => !cancelled && setContextError(extractApiError(e).message));
    return () => {
      cancelled = true;
    };
  }, [vehicle, id]);

  const needsWorkshop = !editing && context !== null && context.mapping !== null && !context.vehicle.default_workshop_id;
  useEffect(() => {
    if (!needsWorkshop) return;
    apiClient
      .get('/app/workshops', { params: { per_page: 100 } })
      .then((res) => setWorkshops(res.data.data))
      .catch(() => setWorkshops([]));
  }, [needsWorkshop]);

  const positions = useMemo(() => new Map((context?.positions ?? []).map((p) => [p.position_code, p])), [context]);
  const order = (codes: string[]) => [...codes].sort((a, b) => (context?.positions.findIndex((p) => p.position_code === a) ?? 0) - (context?.positions.findIndex((p) => p.position_code === b) ?? 0));

  // Replacing With options per tire product (same product only — the backend enforces it too).
  const loadCandidates = useCallback(
    (productId: string) => {
      if (candidates[productId]) return;
      apiClient
        .get('/app/tire-operations/replacement-candidates', { params: { product_id: productId, operation_id: id } })
        .then((res) => setCandidates((prev) => ({ ...prev, [productId]: res.data.data })))
        .catch(() => setCandidates((prev) => ({ ...prev, [productId]: [] })));
    },
    [candidates, id],
  );
  useEffect(() => {
    if (type !== 'REPLACEMENT') return;
    selected.forEach((code) => {
      const productId = positions.get(code)?.tire?.product?.id;
      if (productId) loadCandidates(productId);
    });
  }, [type, selected, positions, loadCandidates]);

  function chooseVehicle(option: SearchableOption | null) {
    setVehicle(option);
    setContext(null);
    resetSelection(type);
  }

  function resetSelection(next: TireOperationType | '') {
    setSelected(next === 'INSPECTION' ? inspectionDefaults(context) : []);
    setReplacements({});
    setTreads({});
    setPairs([]);
    setPending(null);
    setNotice(null);
  }

  function chooseType(next: TireOperationType | '') {
    setType(next);
    resetSelection(next);
  }

  // Inspection: all positions (spare included) are selected as soon as the vehicle's positions arrive.
  useEffect(() => {
    if (type === 'INSPECTION' && context && !editing && selected.length === 0 && context.positions.length > 0) {
      setSelected(inspectionDefaults(context));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [context]);

  function clickPosition(code: string) {
    setNotice(null);
    if (!type) {
      setNotice('Choose the Tire Operations first.');
      return;
    }
    const position = positions.get(code);
    if (!position) return;
    const isSelected = type === 'ROTATION' ? pairs.some((p) => p.from === code || p.to === code) || pending === code : selected.includes(code);
    if (!isSelected && position.open_operation) {
      setNotice(`${code} is already in an open ${OPERATION_TYPE_LABEL[position.open_operation.operation_type]} (Work Order ${position.open_operation.wo_number ?? '—'}).`);
      return;
    }
    if (!isSelected && !position.tire) {
      setNotice(`${code} has no tire data yet — complete it in Vehicle Details → Wheels Configuration first.`);
      return;
    }

    if (type === 'ROTATION') {
      const paired = pairs.find((p) => p.from === code || p.to === code);
      if (paired) {
        setPairs(pairs.filter((p) => p !== paired));
      } else if (pending === null) {
        setPending(code);
      } else if (pending === code) {
        setPending(null);
      } else {
        setPairs([...pairs, { from: pending, to: code, color: nextColor(pairs) }]);
        setPending(null);
      }
      return;
    }

    if (selected.includes(code)) {
      setSelected(selected.filter((c) => c !== code));
      setReplacements(({ [code]: _r, ...rest }) => rest);
      setTreads(({ [code]: _t, ...rest }) => rest);
    } else {
      setSelected([...selected, code]);
    }
  }

  const colors = useMemo(() => {
    const map: Record<string, string> = {};
    if (type === 'ROTATION') {
      pairs.forEach((p) => {
        map[p.from] = p.color;
        map[p.to] = p.color;
      });
      if (pending) map[pending] = nextColor(pairs);
    } else {
      selected.forEach((c) => (map[c] = type === 'REPLACEMENT' ? REPLACEMENT_COLOR : INSPECTION_COLOR));
    }
    return map;
  }, [type, pairs, pending, selected]);

  const chosenSerials = Object.values(replacements);
  const missingTire = (type === 'ROTATION' ? pairs.flatMap((p) => [p.from, p.to]) : selected).filter((c) => !positions.get(c)?.tire);
  const selectionReady =
    type === 'ROTATION'
      ? pairs.length > 0 && pending === null
      : selected.length > 0 && (type !== 'REPLACEMENT' || selected.every((c) => replacements[c]));
  const ready =
    vehicle !== null &&
    context?.mapping != null &&
    type !== '' &&
    date !== '' &&
    TIME_PATTERN.test(time) &&
    km.trim() !== '' &&
    (!needsWorkshop || workshopId !== '') &&
    selectionReady &&
    missingTire.length === 0;

  async function save() {
    if (!vehicle || !type) return;
    setSaving(true);
    setErrors({});
    setMessage(null);
    const payload: TireOperationPayload = {
      vehicle_id: vehicle.value,
      operation_type: type,
      operated_date: date,
      operated_time: time,
      odometer: km,
      ...(needsWorkshop ? { workshop_id: workshopId } : {}),
      ...(type === 'ROTATION'
        ? { rotation_pairs: pairs.map(({ from, to }) => ({ from, to })) }
        : {
            items: order(selected).map((code) => ({
              position_code: code,
              ...(type === 'REPLACEMENT' ? { replacement_tire_id: replacements[code] } : {}),
              ...(type === 'INSPECTION' && treads[code] ? { tread_depth_mm: treads[code] } : {}),
            })),
          }),
    };
    try {
      const res = editing ? await apiClient.put(`/app/tire-operations/${id}`, payload) : await apiClient.post('/app/tire-operations', payload);
      const saved: TireOperationDetail = res.data.data;
      navigate('/app/tire-operations', { state: { saved: { wo_number: saved.work_order?.wo_number ?? null, edited: editing } } });
    } catch (e) {
      const err = extractApiError(e);
      setErrors(err.errors ?? {});
      setMessage(err.message);
    } finally {
      setSaving(false);
    }
  }

  if (loadError) return <ErrorState message={loadError} />;
  if (editing && !existing) return <LoadingState />;

  const mapping = context?.mapping ?? null;
  const fieldErrors = (key: string) => errors[key] ?? (key === 'operated_time' && time && !TIME_PATTERN.test(time) ? ['Use HH:mm (24-hour), e.g. 07:30.'] : undefined);
  const selectionErrors = [...(errors.items ?? []), ...(errors.rotation_pairs ?? [])];

  return (
    <div data-tire-operation-form>
      <BackButton fallbackTo="/app/tire-operations" label="← Back to Tire Operations" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', margin: '8px 0 16px' }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{editing ? 'Edit Tire Operation' : 'Add New Tire Operations'}</h1>
        {existing?.work_order && (
          <span style={{ fontSize: 13, display: 'inline-flex', gap: 8, alignItems: 'center' }}>
            Work Order <Link to={`/app/work-orders/${existing.work_order.id}`}>{existing.work_order.wo_number}</Link> <StatusBadge status={existing.status} />
          </span>
        )}
      </div>

      <div className="split-layout">
        <div style={{ display: 'grid', gap: 16, minWidth: 0 }}>
          <section className="card" data-operation-info>
            <h3 style={{ marginTop: 0, fontSize: 15 }}>Tire Operations Info</h3>
            <FormField label="Registration" required errors={errors.vehicle_id}>
              <SearchableSelect
                ariaLabel="Registration"
                value={vehicle?.value ?? ''}
                selectedLabel={vehicle?.label ?? null}
                onChange={(_, option) => chooseVehicle(option)}
                loadOptions={(search) =>
                  apiClient
                    .get('/app/vehicles', { params: { search: search || undefined, per_page: 25 } })
                    .then((res) => (res.data.data as { id: string; registration_number: string; brand: string | null; model: string | null }[]).map((v) => ({ value: v.id, label: v.registration_number, hint: [v.brand, v.model].filter(Boolean).join(' ') || null })))
                }
                placeholder="Select vehicle registration…"
                searchPlaceholder="Search registration number…"
                width="100%"
                disabled={editing}
              />
            </FormField>
            <FormField label="Config Code">
              {vehicle && context && !mapping ? (
                <div role="alert" data-no-configuration style={{ fontSize: 13, color: '#92400e', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 8, padding: '8px 10px' }}>
                  This vehicle does not have a Wheels Configuration. Please map a Wheels Configuration first.{' '}
                  <Link to={`/app/wheel-configurations?vehicle=${vehicle.value}`}>Find a configuration</Link>
                </div>
              ) : (
                <input aria-label="Config Code" value={mapping ? `${mapping.config_code} (v${mapping.version_number})` : ''} placeholder="Shown after choosing the vehicle" readOnly disabled style={{ ...inputStyle, background: '#f3f4f6', fontFamily: 'monospace', fontWeight: 700 }} />
              )}
              {contextError && <ErrorState message={contextError} />}
            </FormField>
            {needsWorkshop && (
              <FormField label="Workshop" required errors={errors.workshop_id}>
                <select aria-label="Workshop" value={workshopId} onChange={(e) => setWorkshopId(e.target.value)} style={inputStyle}>
                  <option value="">Select the workshop for the Work Order…</option>
                  {workshops.map((w) => (
                    <option key={w.id} value={w.id}>
                      {w.name}
                    </option>
                  ))}
                </select>
              </FormField>
            )}
            <FormField label="Tire Operations" required errors={errors.operation_type}>
              <select aria-label="Tire Operations" value={type} disabled={!mapping} onChange={(e) => chooseType(e.target.value as TireOperationType | '')} style={inputStyle}>
                <option value="">Select…</option>
                {OPERATION_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {OPERATION_TYPE_LABEL[t]}
                  </option>
                ))}
              </select>
            </FormField>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '0 12px' }}>
              <FormField label="Tire Operations Date" required errors={fieldErrors('operated_date')}>
                <input aria-label="Tire Operations Date" type="date" value={date} max={todayIso()} onChange={(e) => setDate(e.target.value)} style={inputStyle} />
              </FormField>
              <FormField label="Tire Operations Time" required errors={fieldErrors('operated_time')}>
                <input aria-label="Tire Operations Time" type="text" inputMode="numeric" placeholder="HH:mm" maxLength={5} autoComplete="off" value={time} onChange={(e) => setTime(autoColon(e.target.value))} style={inputStyle} />
              </FormField>
            </div>
            <FormField label="KM at Tire Operations" required errors={errors.odometer}>
              <NumericInput aria-label="KM at Tire Operations" placeholder="Physical odometer reading, e.g. 15250.5" value={km} onChange={(e) => setKm(e.target.value)} style={inputStyle} />
            </FormField>
          </section>

          {type && mapping && (
            <section className="card" data-operation-section={type}>
              <h3 style={{ marginTop: 0, fontSize: 15 }}>{OPERATION_TYPE_LABEL[type]}</h3>
              <OperationSection
                type={type}
                vehicleId={vehicle?.value}
                positions={positions}
                selected={order(selected)}
                pairs={pairs}
                replacements={replacements}
                treads={treads}
                candidates={candidates}
                chosenSerials={chosenSerials}
                onUnselect={clickPosition}
                onRemovePair={(pair) => setPairs(pairs.filter((p) => p !== pair))}
                onReplacement={(code, tireId) => setReplacements({ ...replacements, [code]: tireId })}
                onTread={(code, value) => setTreads({ ...treads, [code]: value })}
              />
              {selectionErrors.length > 0 && (
                <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginTop: 10 }}>
                  {selectionErrors[0]}
                </div>
              )}
            </section>
          )}
        </div>

        <section className="card" data-operation-preview style={{ position: 'sticky', top: 12 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Preview</h3>
          {mapping ? (
            <>
              {type === 'ROTATION' && (
                <p data-rotation-step style={{ fontSize: 13, fontWeight: 600, color: pending ? nextColor(pairs) : '#374151', margin: '0 0 8px' }}>
                  {pending ? `Step 2 — Rotating the tire with… (${pending} selected)` : 'Step 1 — Choose your Tire Position to be rotated'}
                </p>
              )}
              {type === 'REPLACEMENT' && <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 8px' }}>Click the positions to replace (one or more). Click again to unselect.</p>}
              {type === 'INSPECTION' && <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 8px' }}>All positions are selected for inspection. Click a position to leave it out.</p>}
              <WheelConfigurationPreview
                bodyStyle={bodyStyleFor(mapping.vehicle_type as VehicleType, mapping.truck_configuration_type)}
                input={{ front: mapping.front_axles, rear: mapping.rear_axles, spareTires: mapping.spare_tires }}
                selectedCode={null}
                onPositionSelect={clickPosition}
                positionColors={colors}
                installedCodes={new Set(context?.positions.filter((p) => p.tire).map((p) => p.position_code))}
              />
              {notice && (
                <div role="status" data-selection-notice style={{ marginTop: 8, fontSize: 13, color: '#92400e', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 8, padding: '6px 10px' }}>
                  {notice}{' '}
                  {notice.includes('no tire data') && vehicle && <Link to={`/app/vehicles/${vehicle.value}?tab=wheels`}>Open Vehicle Details</Link>}
                </div>
              )}
            </>
          ) : (
            <p style={{ fontSize: 13, color: '#6b7280', margin: 0 }}>Choose a vehicle with a Wheels Configuration to see its tire positions.</p>
          )}
        </section>
      </div>

      {message && Object.keys(errors).length === 0 && (
        <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginTop: 12 }}>
          {message}
        </div>
      )}
      {missingTire.length > 0 && (
        <div role="alert" style={{ color: '#b91c1c', fontSize: 13, marginTop: 12 }}>
          Positions without tire data: {missingTire.join(', ')}. Complete them in <Link to={`/app/vehicles/${vehicle?.value}?tab=wheels`}>Vehicle Details → Wheels Configuration</Link> or unselect them.
        </div>
      )}
      <div className="form-action-bar" style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
        <button type="button" className="btn-secondary" onClick={() => setConfirmCancel(true)}>
          Cancel
        </button>
        <button type="button" className="btn-primary" disabled={!ready || saving} onClick={save}>
          {saving ? 'Saving…' : 'Save'}
        </button>
      </div>
      <ConfirmDialog
        open={confirmCancel}
        title="Discard this Tire Operation?"
        message="The information entered on this page will be discarded."
        confirmLabel="Discard"
        onCancel={() => setConfirmCancel(false)}
        onConfirm={() => navigate(-1)}
      />
    </div>
  );
}

/** Inspection starts with every position selected (spare included), except positions already in another open operation. */
function inspectionDefaults(context: OperationContext | null): string[] {
  return (context?.positions ?? []).filter((p) => !p.open_operation).map((p) => p.position_code);
}

function nextColor(pairs: Pair[]): string {
  return ROTATION_PALETTE.find((c) => !pairs.some((p) => p.color === c)) ?? ROTATION_PALETTE[pairs.length % ROTATION_PALETTE.length];
}

function OperationSection({
  type,
  vehicleId,
  positions,
  selected,
  pairs,
  replacements,
  treads,
  candidates,
  chosenSerials,
  onUnselect,
  onRemovePair,
  onReplacement,
  onTread,
}: {
  type: TireOperationType;
  vehicleId?: string;
  positions: Map<string, OperationContext['positions'][number]>;
  selected: string[];
  pairs: Pair[];
  replacements: Record<string, string>;
  treads: Record<string, string>;
  candidates: Record<string, ReplacementCandidate[]>;
  chosenSerials: string[];
  onUnselect: (code: string) => void;
  onRemovePair: (pair: Pair) => void;
  onReplacement: (code: string, tireId: string) => void;
  onTread: (code: string, value: string) => void;
}) {
  const empty = (text: string) => <p style={{ fontSize: 13, color: '#6b7280', margin: 0 }}>{text}</p>;

  if (type === 'ROTATION') {
    if (pairs.length === 0) return empty('Choose the positions on the Vehicle Preview: first the tire to rotate, then the tire it rotates with.');
    return (
      <div style={{ display: 'grid', gap: 18 }}>
        {pairs.map((pair, n) => (
          <div key={`${pair.from}-${pair.to}`} data-rotation-pair={n + 1}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
              <strong style={{ fontSize: 13, color: pair.color }}>
                Pair {n + 1}: <PositionLabel code={pair.from} /> ↔ <PositionLabel code={pair.to} />
              </strong>
              <button type="button" className="btn-link" style={{ fontSize: 12 }} onClick={() => onRemovePair(pair)}>
                Remove pair
              </button>
            </div>
            <TireOperationCard title="To be Rotated" code={pair.from} tire={positions.get(pair.from)?.tire ?? null} accent={pair.color} vehicleId={vehicleId} />
            <RotationArrows color={pair.color} />
            <TireOperationCard title="Rotating With" code={pair.to} tire={positions.get(pair.to)?.tire ?? null} accent={pair.color} vehicleId={vehicleId} />
          </div>
        ))}
      </div>
    );
  }

  if (selected.length === 0) return empty(type === 'REPLACEMENT' ? 'Choose one or more positions to replace on the Vehicle Preview.' : 'No position selected.');

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      {selected.map((code) => {
        const tire = positions.get(code)?.tire ?? null;
        if (type === 'INSPECTION') {
          return (
            <TireOperationCard key={code} title="Installed Tire" code={code} tire={tire} accent={INSPECTION_COLOR} vehicleId={vehicleId} onRemove={() => onUnselect(code)}>
              {tire && (
                <FormField label="Tread Depth (mm)">
                  <NumericInput aria-label={`Tread Depth ${code}`} placeholder="Measured tread depth, e.g. 7.5 (optional)" value={treads[code] ?? ''} onChange={(e) => onTread(code, e.target.value)} style={inputStyle} />
                </FormField>
              )}
            </TireOperationCard>
          );
        }
        const options = tire?.product ? candidates[tire.product.id] : undefined;
        return (
          <div key={code} data-replacement={code}>
            <TireOperationCard title="Installed Tire" code={code} tire={tire} accent={REPLACEMENT_COLOR} vehicleId={vehicleId} onRemove={() => onUnselect(code)} />
            {tire && (
              <>
                <ReplacementArrow />
                <section data-replacing-with={code} style={{ border: `1px dashed ${REPLACEMENT_COLOR}`, borderRadius: 12, padding: '10px 14px', background: '#fff' }}>
                  <h4 style={{ margin: '0 0 8px', fontSize: 13, color: REPLACEMENT_COLOR, textTransform: 'uppercase', letterSpacing: 0.4 }}>Replacing With</h4>
                  <FormField label="Serial Number" required>
                    <select aria-label={`Serial Number ${code}`} value={replacements[code] ?? ''} onChange={(e) => onReplacement(code, e.target.value)} style={inputStyle}>
                      <option value="">{options === undefined ? 'Loading…' : options.length === 0 ? `No New Stock or Reuse serial of ${tire.product?.name ?? 'this product'}` : 'Select serial number…'}</option>
                      {(options ?? [])
                        .filter((c) => c.id === replacements[code] || !chosenSerials.includes(c.id))
                        .map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.serial_number} — {c.source === 'NEW_STOCK' ? 'New Stock' : `Reuse${c.warehouse ? ` · ${c.warehouse}` : ''}`}
                          </option>
                        ))}
                    </select>
                  </FormField>
                  {(() => {
                    const limits = options?.find((c) => c.id === replacements[code])?.usage_restrictions;
                    if (!limits) return null;
                    return (
                      <div style={{ marginBottom: 8 }}>
                        <p style={{ fontSize: 12, color: '#374151', margin: '0 0 6px' }}>
                          Usage restrictions (from its inspection): {restrictionText(limits)}
                        </p>
                        {outsideAllowedPositions(limits, code) && (
                          <div data-usage-warning={code}>
                            <UsageRestrictionWarnings
                              warnings={[`This Reuse tire is restricted to position(s) ${(limits.positions ?? []).join(', ')}, not ${code}. You can still save — check the restriction before fitting.`]}
                            />
                          </div>
                        )}
                      </div>
                    );
                  })()}
                  <p style={{ fontSize: 12, color: '#6b7280', margin: 0 }}>Only serials of {tire.product?.name ?? 'the same tire product'} (New Stock, or Reuse — used tires back in stock after inspection in Used Tire Management) are listed. Both are requested and issued through the Work Order's Part Request (Reuse as a Used line).</p>
                </section>
              </>
            )}
          </div>
        );
      })}
    </div>
  );
}
