import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { BackButton } from '../../../../components/BackButton';
import { FormField, inputStyle } from '../../../../components/FormField';
import { NumericInput } from '../../../../components/NumericInput';
import { ErrorState, LoadingState } from '../../../../components/States';
import { TRUCK_CONFIGURATION_TYPES, VEHICLE_TYPES, bodyStyleFor, configCodePrefix, requiresTruckConfigurationType, vehicleTypeOption, type BodyStyle } from './vehicleTypes';
import { LIMITS, configCode, parseCount, saveErrors, totalAxles, totalWheels, type AxleGroups, type Range } from './wheelLayout';
import { WheelConfigurationPreview } from './WheelConfigurationPreview';
import { SaveConfigurationDialog } from './SaveConfigurationDialog';
import type { ConfigurationMaster, ConfigurationVersion, SaveRequest } from './masterTypes';
import { t as tt } from '../../../../i18n/i18n';

/** Route entry for /new and /:id/edit — keyed so switching between them starts a fresh form. */
export function AddWheelConfigurationPage() {
  const { id } = useParams();
  return <WheelConfigurationFormPage key={id ?? 'new'} masterId={id ?? null} />;
}

/**
 * Create / edit a Wheel Configuration master (a reusable template — no vehicle is selected or
 * involved here). Form state lives here; every number shown is derived from it on each render (no
 * "Generate" step) through wheelLayout (calculations) and WheelConfigurationPreview (drawing). Save
 * shows a configuration-level confirmation (SaveConfigurationDialog) and creates a configuration
 * version on the server. When editing, Vehicle Type / Truck Configuration Type are fixed (they are
 * part of the configuration's identity) and the form starts from the current version.
 */
function WheelConfigurationFormPage({ masterId }: { masterId: string | null }) {
  const navigate = useNavigate();
  const [master, setMaster] = useState<ConfigurationMaster | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [vehicleType, setVehicleType] = useState('');
  const [truckType, setTruckType] = useState('');
  const editing = masterId !== null;
  const option = vehicleTypeOption(vehicleType);
  const needsTruckType = requiresTruckConfigurationType(vehicleType);
  const ready = option && (!needsTruckType || truckType !== '');

  useEffect(() => {
    if (!masterId) return;
    let cancelled = false;
    apiClient
      .get(`/app/wheel-configuration-masters/${masterId}`)
      .then((res) => {
        if (cancelled) return;
        const loaded: ConfigurationMaster = res.data.data;
        setMaster(loaded);
        setVehicleType(loaded.vehicle_type);
        setTruckType(loaded.truck_configuration_type ?? '');
      })
      .catch((e) => !cancelled && setLoadError(extractApiError(e).message));
    return () => {
      cancelled = true;
    };
  }, [masterId]);

  function onSaved(saved: ConfigurationMaster, version: ConfigurationVersion) {
    navigate('/app/wheel-configurations', { state: { saved: { id: saved.id, config_code: version.config_code, version_number: version.version_number } } });
  }

  if (editing && loadError) return <ErrorState message={loadError} />;
  if (editing && !master) return <LoadingState />;
  const current = master?.current_version ?? null;

  return (
    <div>
      <BackButton fallbackTo="/app/wheel-configurations" label={tt('tire.actions.backToWheelConfiguration')} />
      <h1 style={{ fontSize: 22, margin: '0 0 14px' }}>{editing ? tt('tire.titles.editWheelsConfiguration') : tt('tire.titles.addNewWheelsConfiguration')}</h1>

      <div className="card" style={{ marginBottom: 16, maxWidth: 420 }}>
        <FormField label={tt('tire.fields.vehicleType')} required>
          <select
            aria-label={tt('tire.fields.vehicleType')}
            value={vehicleType}
            disabled={editing}
            onChange={(e) => {
              setVehicleType(e.target.value);
              setTruckType('');
            }}
            style={inputStyle}
          >
            <option value="">{tt('tire.fields.selectVehicleType')}</option>
            {VEHICLE_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </select>
        </FormField>
        {needsTruckType && (
          <FormField label={tt('tire.fields.truckConfigurationType')} required>
            <select aria-label={tt('tire.fields.truckConfigurationType')} value={truckType} disabled={editing} onChange={(e) => setTruckType(e.target.value)} style={inputStyle}>
              <option value="">{tt('tire.fields.selectTruckConfigurationType')}</option>
              {TRUCK_CONFIGURATION_TYPES.map((t) => (
                <option key={t.value} value={t.value}>
                  {t.label}
                </option>
              ))}
            </select>
          </FormField>
        )}
        {editing && current && (
          <p data-editing-version style={{ fontSize: 12, color: '#6b7280', margin: 0 }}>
            Editing version {current.version_number} ({current.config_code}). Saving a change creates version {current.version_number + 1}. Vehicle Type and Truck Configuration Type identify this configuration and cannot be changed — create a new configuration for another type.
          </p>
        )}
        {editing && (master?.mapped_vehicle_count ?? 0) > 0 && (
          <p data-mapped-impact style={{ fontSize: 12, color: '#92400e', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 6, padding: '6px 8px', margin: '8px 0 0' }}>
            {tt('tire.help.mappedVehicleCountVehicleSMapped', { count: master?.mapped_vehicle_count ?? 0 })}
          </p>
        )}
      </div>

      {!option && <p style={{ fontSize: 13, color: '#6b7280' }}>{tt('tire.help.selectVehicleTypeConfigureAxlesWheels')}</p>}
      {option && !ready && <p style={{ fontSize: 13, color: '#6b7280' }}>{tt('tire.help.selectTruckConfigurationTypeConfigureAxles')}</p>}
      {/* Every vehicle type uses the same form and rules; only the preview body and the Config Code
          prefix (Truck · Trailer "+", Truck · Semi Trailer "-") differ. Keyed so a type change
          starts a fresh form. */}
      {option && ready && (
        <AxleConfigurationForm
          key={`${option.value}:${truckType}`}
          bodyStyle={bodyStyleFor(option.value, needsTruckType ? truckType : null)}
          codePrefix={configCodePrefix(option.value, needsTruckType ? truckType : null)}
          identity={{ vehicle_type: option.value, ...(needsTruckType ? { truck_configuration_type: truckType } : {}) }}
          initial={current ? { front: current.front_axles, rear: current.rear_axles, spare: current.spare_tires } : null}
          masterId={masterId}
          onSaved={onSaved}
        />
      )}
    </div>
  );
}

/**
 * The wheels configuration form shared by every vehicle type (owner decision: same inputs,
 * validation, calculations and code rules). Field values are kept as typed (strings) so partial or
 * invalid input can be shown and explained.
 */
function AxleConfigurationForm({
  bodyStyle,
  codePrefix = '',
  identity,
  initial,
  masterId,
  onSaved,
}: {
  bodyStyle: BodyStyle;
  codePrefix?: string;
  identity: Pick<SaveRequest, 'vehicle_type' | 'truck_configuration_type'>;
  /** the current version's axle pattern when editing */
  initial: { front: number[]; rear: number[]; spare: number } | null;
  masterId: string | null;
  onSaved: (master: ConfigurationMaster, version: ConfigurationVersion) => void;
}) {
  const [frontAxles, setFrontAxles] = useState(initial ? String(initial.front.length) : '');
  const [rearAxles, setRearAxles] = useState(initial ? String(initial.rear.length) : '');
  // One slot per possible axle; values survive when the axle count goes down and up again.
  const [frontWheels, setFrontWheels] = useState<string[]>(() => slots(initial?.front));
  const [rearWheels, setRearWheels] = useState<string[]>(() => slots(initial?.rear));
  const [spareTires, setSpareTires] = useState(initial ? String(initial.spare) : '');
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
  const [saveRequest, setSaveRequest] = useState<SaveRequest | null>(null);
  const openSave = () => {
    if (!saveReady || !groups || code === null || spare.value === null) return;
    setSaveRequest({ ...identity, front_axles: groups.front, rear_axles: groups.rear, spare_tires: spare.value, config_code: code });
  };

  const setRow = (setter: typeof setFrontWheels, index: number, value: string) => setter((rows) => rows.map((r, i) => (i === index ? value : r)));
  const errorOf = (field: string, parsed: { error: string | null }, raw: string) => (parsed.error && (touched[field] || raw.trim() !== '') ? [parsed.error] : undefined);

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: 16, alignItems: 'start' }}>
      <div>
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{tt('tire.sections.configuration')}</h3>
          <AxleGroupFields
            title={tt('tire.sections.front')}
            countLabel={tt('tire.fields.numberOfFrontAxles')}
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
            title={tt('tire.sections.rear')}
            countLabel={tt('tire.fields.numberOfRearAxles')}
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
          <FormField label={tt('tire.fields.spareTires')} required errors={errorOf('spare', spare, spareTires)}>
            <IntegerField label={tt('tire.fields.spareTires')} value={spareTires} onChange={setSpareTires} onBlur={() => touch('spare')} range={LIMITS.spareTires} />
          </FormField>
        </div>

        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{tt('tire.sections.summary')}</h3>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 10 }}>
            <SummaryField label={tt('tire.fields.totalAxles')} value={countsValid ? String(totalAxles(front.value as number, rear.value as number)) : ''} />
            <SummaryField label={tt('tire.fields.totalWheels')} value={groups && spare.value !== null ? String(totalWheels(groups, spare.value)) : ''} />
            <SummaryField label={tt('tire.fields.configCode')} value={code ?? ''} />
          </div>
          <p style={{ fontSize: 12, color: saveReady ? '#166534' : '#6b7280', marginBottom: 0 }}>
            {saveReady
              ? tt('tire.help.validConfiguration')
              : tt('tire.help.configCodeAppearsWhenEveryField')}
          </p>
          <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: 10, marginTop: 14 }}>
            <button className="btn-primary" disabled={!saveReady} onClick={openSave}>
              {tt('tire.actions.saveConfiguration')}
            </button>
          </div>
          {saveRequest && (
            <SaveConfigurationDialog
              request={saveRequest}
              masterId={masterId}
              onClose={() => setSaveRequest(null)}
              onSaved={(savedMaster, version) => {
                setSaveRequest(null);
                onSaved(savedMaster, version);
              }}
            />
          )}
        </div>
      </div>

      <div className="card" style={{ position: 'sticky', top: 12 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{tt('tire.sections.vehiclePreview')}</h3>
        <WheelConfigurationPreview
          bodyStyle={bodyStyle}
          input={{
            front: frontWheels.slice(0, front.value ?? 0).map((raw) => parseCount(raw, LIMITS.wheelsPerSide).value),
            rear: rearWheels.slice(0, rear.value ?? 0).map((raw) => parseCount(raw, LIMITS.wheelsPerSide).value),
            spareTires: spare.value ?? 0,
          }}
        />
        <p style={{ fontSize: 11, color: '#6b7280', marginBottom: 0 }}>
          {tt('tire.empty.positionCodeAxleNumberGroupF')}
        </p>
      </div>
    </div>
  );
}

function slots(values: number[] | undefined): string[] {
  return Array.from({ length: LIMITS.axlesPerGroup.max }, (_, i) => (values && i < values.length ? String(values[i]) : ''));
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
          <FormField label={tt('tire.fields.titleAxleValueWheelsSide', { title: props.title, value: i + 1 })} required errors={props.rowErrors[i]}>
            <IntegerField label={tt('tire.fields.titleAxleValueWheelsPerSide', { title: props.title, value: i + 1 })} value={raw} onChange={(v) => props.onRow(i, v)} onBlur={() => props.onRowBlur(i)} range={LIMITS.wheelsPerSide} />
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
