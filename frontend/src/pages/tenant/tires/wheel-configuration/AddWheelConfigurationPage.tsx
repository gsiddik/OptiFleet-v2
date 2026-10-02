import { useState } from 'react';
import { BackButton } from '../../../../components/BackButton';
import { FormField, inputStyle } from '../../../../components/FormField';
import { NumericInput } from '../../../../components/NumericInput';
import { VEHICLE_TYPES, bodyStyleFor, vehicleTypeOption, type BodyStyle } from './vehicleTypes';
import { LIMITS, configCode, parseCount, saveErrors, totalAxles, totalWheels, type AxleGroups, type Range } from './wheelLayout';
import { WheelConfigurationPreview } from './WheelConfigurationPreview';

/**
 * PROTOTYPE — Add New Wheels Configuration (vehicle bodies awaiting owner review); nothing is
 * saved yet. Form state
 * lives here, every number shown is derived from it on each render (no "Generate" step) through
 * wheelLayout (calculations) and WheelConfigurationPreview (drawing).
 */
export function AddWheelConfigurationPage() {
  const [vehicleType, setVehicleType] = useState('');
  const option = vehicleTypeOption(vehicleType);

  return (
    <div>
      <BackButton fallbackTo="/app/wheel-configurations" label="← Back to Wheel Configuration" />
      <h1 style={{ fontSize: 22, margin: '0 0 6px' }}>Add New Wheels Configuration</h1>
      <div style={{ fontSize: 12, color: '#92400e', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 6, padding: '6px 10px', marginBottom: 14, display: 'inline-block' }}>
        Prototype for review — configurations are not saved yet.
      </div>

      <div className="card" style={{ marginBottom: 16, maxWidth: 420 }}>
        <FormField label="Vehicle Type" required>
          <select aria-label="Vehicle Type" value={vehicleType} onChange={(e) => setVehicleType(e.target.value)} style={inputStyle}>
            <option value="">Select vehicle type…</option>
            {VEHICLE_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </FormField>
      </div>

      {!option && <p style={{ fontSize: 13, color: '#6b7280' }}>Select a vehicle type to configure its axles and wheels.</p>}
      {option && option.value === 'TRUCK' && (
        <div className="card" style={{ fontSize: 13, color: '#6b7280' }}>
          Configuration form for this vehicle type will be added after prototype approval.
        </div>
      )}
      {/* Every vehicle type uses the same form and rules; only the preview body differs. Keyed by
          type so switching type starts a fresh form. */}
      {option && option.value !== 'TRUCK' && <AxleConfigurationForm key={option.value} bodyStyle={bodyStyleFor(option.value, null)} />}
    </div>
  );
}

/**
 * The wheels configuration form shared by every vehicle type (owner decision: same inputs,
 * validation, calculations and code rules). Field values are kept as typed (strings) so partial or
 * invalid input can be shown and explained.
 */
function AxleConfigurationForm({ bodyStyle, codePrefix = '' }: { bodyStyle: BodyStyle; codePrefix?: string }) {
  const [frontAxles, setFrontAxles] = useState('');
  const [rearAxles, setRearAxles] = useState('');
  // One slot per possible axle; values survive when the axle count goes down and up again.
  const [frontWheels, setFrontWheels] = useState<string[]>(() => Array(LIMITS.axlesPerGroup.max).fill(''));
  const [rearWheels, setRearWheels] = useState<string[]>(() => Array(LIMITS.axlesPerGroup.max).fill(''));
  const [spareTires, setSpareTires] = useState('');
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const touch = (field: string) => setTouched((t) => (t[field] ? t : { ...t, [field]: true }));

  const front = parseCount(frontAxles, LIMITS.axlesPerGroup);
  const rear = parseCount(rearAxles, LIMITS.axlesPerGroup);
  const spare = parseCount(spareTires, LIMITS.spareTires);
  const frontRows = frontWheels.slice(0, front.value ?? 0).map((raw) => parseCount(raw, LIMITS.wheelsPerSide));
  const rearRows = rearWheels.slice(0, rear.value ?? 0).map((raw) => parseCount(raw, LIMITS.wheelsPerSide));
  // 0 axles in a group is a temporary editing state: the preview follows it, but there is no
  // Config Code and the configuration cannot be saved until both groups have an axle.
  const countsValid = front.value !== null && rear.value !== null;
  const rowsValid = [...frontRows, ...rearRows].every((r) => r.value !== null);
  const groups: AxleGroups | null = countsValid && rowsValid ? { front: frontRows.map((r) => r.value as number), rear: rearRows.map((r) => r.value as number) } : null;
  const groupErrors = groups ? saveErrors(groups) : [];
  const code = groups ? configCode(groups, codePrefix) : null;
  const saveReady = groups !== null && spare.value !== null && groupErrors.length === 0;

  const setRow = (setter: typeof setFrontWheels, index: number, value: string) => setter((rows) => rows.map((r, i) => (i === index ? value : r)));
  const errorOf = (field: string, parsed: { error: string | null }, raw: string) => (parsed.error && (touched[field] || raw.trim() !== '') ? [parsed.error] : undefined);

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: 16, alignItems: 'start' }}>
      <div>
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Configuration</h3>
          <AxleGroupFields
            title="Front"
            countLabel="Number of Front Axles"
            countRaw={frontAxles}
            onCount={setFrontAxles}
            countErrors={errorOf('frontAxles', front, frontAxles)}
            onCountBlur={() => touch('frontAxles')}
            rows={frontWheels.slice(0, front.value ?? 0)}
            rowErrors={frontRows.map((r, i) => errorOf(`front-${i}`, r, frontWheels[i]))}
            onRow={(i, v) => setRow(setFrontWheels, i, v)}
            onRowBlur={(i) => touch(`front-${i}`)}
          />
          <AxleGroupFields
            title="Rear"
            countLabel="Number of Rear Axles"
            countRaw={rearAxles}
            onCount={setRearAxles}
            countErrors={errorOf('rearAxles', rear, rearAxles)}
            onCountBlur={() => touch('rearAxles')}
            rows={rearWheels.slice(0, rear.value ?? 0)}
            rowErrors={rearRows.map((r, i) => errorOf(`rear-${i}`, r, rearWheels[i]))}
            onRow={(i, v) => setRow(setRearWheels, i, v)}
            onRowBlur={(i) => touch(`rear-${i}`)}
          />
          {groupErrors.map((e) => (
            <div key={e} style={{ color: '#b91c1c', fontSize: 12, marginBottom: 6 }}>
              {e}
            </div>
          ))}
          <FormField label="Spare Tires" required errors={errorOf('spare', spare, spareTires)}>
            <IntegerField label="Spare Tires" value={spareTires} onChange={setSpareTires} onBlur={() => touch('spare')} range={LIMITS.spareTires} />
          </FormField>
        </div>

        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Summary</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 10 }}>
            <SummaryField label="Total Axles" value={countsValid ? String(totalAxles(front.value as number, rear.value as number)) : ''} />
            <SummaryField label="Total Wheels" value={groups && spare.value !== null ? String(totalWheels(groups, spare.value)) : ''} />
            <SummaryField label="Config Code" value={code ?? ''} />
          </div>
          <p style={{ fontSize: 12, color: saveReady ? '#166534' : '#6b7280', marginBottom: 0 }}>
            {saveReady
              ? 'Valid configuration.'
              : 'The Config Code appears when every field is valid and there is at least one front and one rear axle.'}
          </p>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 14 }}>
            <button className="btn-primary" disabled title="Saving will be added after the prototype is approved">
              Save Configuration
            </button>
          </div>
        </div>
      </div>

      <div className="card" style={{ position: 'sticky', top: 12 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Vehicle Preview</h3>
        <WheelConfigurationPreview
          bodyStyle={bodyStyle}
          input={{
            front: frontWheels.slice(0, front.value ?? 0).map((raw) => parseCount(raw, LIMITS.wheelsPerSide).value),
            rear: rearWheels.slice(0, rear.value ?? 0).map((raw) => parseCount(raw, LIMITS.wheelsPerSide).value),
            spareTires: spare.value ?? 0,
          }}
        />
        <p style={{ fontSize: 11, color: '#6b7280', marginBottom: 0 }}>
          Position code = axle number in its group + F/R (front/rear) + L/R (side) + wheel number counted from the body outwards, e.g. 1FL1, 2RR2. Dashed axle = wheels per side not entered yet.
        </p>
      </div>
    </div>
  );
}

function AxleGroupFields(props: {
  title: string;
  countLabel: string;
  countRaw: string;
  onCount: (v: string) => void;
  countErrors?: string[];
  onCountBlur: () => void;
  rows: string[];
  rowErrors: (string[] | undefined)[];
  onRow: (index: number, value: string) => void;
  onRowBlur: (index: number) => void;
}) {
  return (
    <div style={{ marginBottom: 12 }}>
      <FormField label={props.countLabel} required errors={props.countErrors}>
        <IntegerField label={props.countLabel} value={props.countRaw} onChange={props.onCount} onBlur={props.onCountBlur} range={LIMITS.axlesPerGroup} />
      </FormField>
      {props.rows.map((raw, i) => (
        <div key={i} style={{ paddingLeft: 14, borderLeft: '2px solid #e5e7eb' }}>
          <FormField label={`${props.title} Axle ${i + 1} — Wheels / Side`} required errors={props.rowErrors[i]}>
            <IntegerField label={`${props.title} Axle ${i + 1} wheels per side`} value={raw} onChange={(v) => props.onRow(i, v)} onBlur={() => props.onRowBlur(i)} range={LIMITS.wheelsPerSide} />
          </FormField>
        </div>
      ))}
    </div>
  );
}

/** Whole numbers only (no spinbox, no sign, no decimals); the allowed range is shown as a hint. */
function IntegerField({ label, value, onChange, onBlur, range }: { label: string; value: string; onChange: (v: string) => void; onBlur: () => void; range: Range }) {
  return (
    <NumericInput
      integer
      aria-label={label}
      placeholder={`${range.min}–${range.max}`}
      maxLength={2}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      onBlur={onBlur}
      style={{ ...inputStyle, width: 120 }}
    />
  );
}

function SummaryField({ label, value }: { label: string; value: string }) {
  return (
    <label style={{ fontSize: 12, color: '#374151', fontWeight: 600 }}>
      {label}
      <input aria-label={label} value={value || '—'} disabled readOnly style={{ ...inputStyle, marginTop: 4, background: '#f3f4f6', fontWeight: 700, color: '#111827' }} />
    </label>
  );
}
