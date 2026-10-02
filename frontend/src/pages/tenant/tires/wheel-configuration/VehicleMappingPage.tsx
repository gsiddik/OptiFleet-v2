import { useCallback, useEffect, useMemo, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { BackButton } from '../../../../components/BackButton';
import { Modal } from '../../../../components/Modal';
import { ErrorState, LoadingState } from '../../../../components/States';
import { useAuth } from '../../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../../navigation/BreadcrumbLabelContext';
import { truckConfigurationTypeOption, vehicleTypeOption } from './vehicleTypes';

interface MappingVehicle {
  id: string;
  registration_number: string;
  brand: string | null;
  model: string | null;
  branch: string | null;
  vehicle_type: string | null;
  axle_count: number | null;
  wheel_count: number | null;
  status: string;
  version_number?: number;
  mapped_config_code?: string;
  is_current_version?: boolean;
}

interface MappingData {
  configuration: {
    id: string;
    vehicle_type: string;
    truck_configuration_type: string | null;
    config_code: string;
    version_number: number;
    total_axles: number;
    total_wheels: number;
    spare_tires: number;
  };
  available_vehicles: MappingVehicle[];
  mapped_vehicles: MappingVehicle[];
  excluded: { incomplete_vehicle_data: number; mapped_to_other_configuration: number };
}

interface Blocker {
  vehicle_registration_number: string;
  position_code: string;
  tire_serial_number: string;
}

const EMPTY = new Set<string>();

/**
 * Vehicle Mapping of one wheel configuration — the only place vehicles get a configuration. Opens
 * in VIEW mode; Edit lets rows move between the tables (click / Enter), which is a local draft
 * until Save. Eligibility, compatibility and tire conflicts are decided by the backend: the left
 * table is exactly what the server returned as eligible, and Save is validated again server-side.
 */
export function VehicleMappingPage() {
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const focusVehicleId = searchParams.get('vehicle');
  const { hasPermission } = useAuth();
  const canEdit = hasPermission('wheel_configuration.map_vehicle');
  const [data, setData] = useState<MappingData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [added, setAdded] = useState<Set<string>>(EMPTY);
  const [removed, setRemoved] = useState<Set<string>>(EMPTY);
  const [updated, setUpdated] = useState<Set<string>>(EMPTY);
  const [confirming, setConfirming] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<{ message: string; blockers: Blocker[]; details: string[] } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    apiClient
      .get(`/app/wheel-configuration-masters/${id}/vehicle-mappings`)
      .then((res) => setData(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [id]);

  useEffect(load, [load]);
  useBreadcrumbLabel(id, data?.configuration.config_code);

  const left = useMemo(() => {
    if (!data) return [];
    const back = data.mapped_vehicles.filter((v) => removed.has(v.id));
    return [...data.available_vehicles.filter((v) => !added.has(v.id)), ...back].sort(byRegistration);
  }, [data, added, removed]);
  const right = useMemo(() => {
    if (!data) return [];
    const incoming = data.available_vehicles.filter((v) => added.has(v.id));
    return [...data.mapped_vehicles.filter((v) => !removed.has(v.id)), ...incoming].sort(byRegistration);
  }, [data, added, removed]);

  if (error) return <ErrorState message={error} />;
  if (!data) return <LoadingState />;

  const config = data.configuration;
  const changes = added.size + removed.size + updated.size;

  function resetDraft() {
    setAdded(EMPTY);
    setRemoved(EMPTY);
    setUpdated(EMPTY);
    setSaveError(null);
  }
  function toggle(set: Set<string>, setter: (s: Set<string>) => void, vehicleId: string, on: boolean) {
    const next = new Set(set);
    if (on) next.add(vehicleId);
    else next.delete(vehicleId);
    setter(next);
  }
  function clickLeft(v: MappingVehicle) {
    if (removed.has(v.id)) toggle(removed, setRemoved, v.id, false); // undo a draft removal
    else toggle(added, setAdded, v.id, true);
  }
  function clickRight(v: MappingVehicle) {
    if (added.has(v.id)) toggle(added, setAdded, v.id, false); // undo a draft addition
    else {
      toggle(removed, setRemoved, v.id, true);
      toggle(updated, setUpdated, v.id, false);
    }
  }

  async function save() {
    setSaving(true);
    setSaveError(null);
    try {
      const res = await apiClient.put(`/app/wheel-configuration-masters/${id}/vehicle-mappings`, {
        add_vehicle_ids: [...added],
        remove_vehicle_ids: [...removed],
        update_vehicle_ids: [...updated],
      });
      const { summary, ...next } = res.data.data;
      setData(next);
      resetDraft();
      setEditing(false);
      setConfirming(false);
      setNotice(`Mapping saved — added ${summary.added}, removed ${summary.removed}, updated ${summary.updated}.`);
    } catch (e) {
      const err = extractApiError(e) as { message: string; errors?: Record<string, string[]>; blockers?: Blocker[] };
      setSaveError({ message: err.message, blockers: err.blockers ?? [], details: err.errors?.vehicles ?? [] });
      setConfirming(false);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <BackButton fallbackTo="/app/wheel-configurations" label="← Back to Wheel Configuration" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>Vehicle Mapping</h1>
        <div style={{ display: 'flex', gap: 8 }} data-mapping-actions>
          {!editing && canEdit && (
            <button
              className="btn-primary"
              onClick={() => {
                setNotice(null);
                setEditing(true);
              }}
            >
              Edit
            </button>
          )}
          {editing && (
            <>
              <button
                className="btn-secondary"
                onClick={() => {
                  resetDraft();
                  setEditing(false);
                }}
              >
                Cancel
              </button>
              <button className="btn-primary" disabled={saving} onClick={() => (changes ? setConfirming(true) : setEditing(false))}>
                Save
              </button>
            </>
          )}
        </div>
      </div>

      <div className="card" data-mapping-header style={{ marginBottom: 16, display: 'flex', gap: 28, flexWrap: 'wrap', fontSize: 13 }}>
        <Stat label="Wheels Configuration Code" value={<span style={{ fontFamily: 'monospace' }}>{config.config_code}</span>} />
        <Stat label="Vehicle Type" value={vehicleTypeOption(config.vehicle_type)?.label ?? config.vehicle_type} />
        {config.truck_configuration_type && <Stat label="Truck Configuration Type" value={truckConfigurationTypeOption(config.truck_configuration_type)?.label ?? config.truck_configuration_type} />}
        <Stat label="Total Axles" value={config.total_axles} />
        <Stat label="Total Wheels" value={config.total_wheels} />
        <Stat label="Total Spare Tire" value={config.spare_tires} />
        <Stat label="Version" value={config.version_number} />
      </div>

      {notice && (
        <div role="status" data-mapping-notice style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, padding: '8px 12px', marginBottom: 12 }}>
          {notice}
        </div>
      )}
      {saveError && (
        <div role="alert" data-mapping-error style={{ fontSize: 13, color: '#991b1b', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6, padding: '8px 12px', marginBottom: 12 }}>
          <div style={{ fontWeight: 600 }}>{saveError.blockers.length ? 'Vehicle cannot be remapped because active tires are installed on positions removed by the target configuration.' : saveError.message}</div>
          {saveError.blockers.map((b) => (
            <div key={`${b.vehicle_registration_number}-${b.position_code}`} style={{ fontFamily: 'monospace' }}>
              {b.vehicle_registration_number} · {b.position_code} — Tire {b.tire_serial_number}
            </div>
          ))}
          {saveError.details.map((d) => (
            <div key={d}>{d}</div>
          ))}
          {saveError.blockers.length > 0 && <div style={{ marginTop: 4 }}>Remove or transfer these tires first.</div>}
        </div>
      )}
      {editing && (
        <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 10px' }}>
          Click a vehicle to move it between the tables. Changes are saved only when you press Save.
        </p>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(380px, 100%), 1fr))', gap: 16, alignItems: 'start' }}>
        <VehicleTable
          testId="available"
          title={`Available Vehicles (${left.length})`}
          hint={`Same vehicle type, ${config.total_axles} axles and ${config.total_wheels} wheels (incl. spare), not mapped to any configuration.`}
          rows={left}
          editing={editing}
          focusId={focusVehicleId}
          pendingIds={removed}
          pendingLabel="Will be unmapped"
          onRowClick={clickLeft}
          emptyLabel="No eligible vehicles."
        />
        <VehicleTable
          testId="mapped"
          title={`Mapped Vehicles (${right.length})`}
          rows={right}
          editing={editing}
          focusId={focusVehicleId}
          pendingIds={added}
          pendingLabel="Will be mapped"
          onRowClick={clickRight}
          emptyLabel="No vehicles mapped to this configuration."
          currentVersion={config.version_number}
          updatedIds={updated}
          onToggleUpdate={(v) => toggle(updated, setUpdated, v.id, !updated.has(v.id))}
        />
      </div>

      {(data.excluded.incomplete_vehicle_data > 0 || data.excluded.mapped_to_other_configuration > 0) && (
        <p data-mapping-excluded style={{ fontSize: 12, color: '#6b7280', marginTop: 12 }}>
          Not listed: {data.excluded.incomplete_vehicle_data} vehicle(s) without Vehicle Type, Axles or Wheels on their Vehicle Detail, and {data.excluded.mapped_to_other_configuration} vehicle(s) mapped to another configuration (remove them there first).
        </p>
      )}

      {confirming && (
        <Modal open title="Save Vehicle Mapping" onClose={() => setConfirming(false)} width={420}>
          <div data-mapping-confirm style={{ fontSize: 14, display: 'grid', gap: 4 }}>
            <div>Vehicles Added: {added.size}</div>
            <div>Vehicles Removed: {removed.size}</div>
            {updated.size > 0 && <div>Vehicles Changed (to version {config.version_number}): {updated.size}</div>}
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 18 }}>
            <button className="btn-secondary" onClick={() => setConfirming(false)}>
              Back
            </button>
            <button className="btn-primary" disabled={saving} onClick={save}>
              {saving ? 'Saving…' : 'Confirm Save'}
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function byRegistration(a: MappingVehicle, b: MappingVehicle) {
  return a.registration_number.localeCompare(b.registration_number);
}

function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <div style={{ color: '#6b7280', fontSize: 12 }}>{label}</div>
      <div style={{ fontWeight: 600, fontSize: 15 }}>{value}</div>
    </div>
  );
}

function VehicleTable(props: {
  testId: string;
  title: string;
  hint?: string;
  rows: MappingVehicle[];
  editing: boolean;
  focusId: string | null;
  pendingIds: Set<string>;
  pendingLabel: string;
  onRowClick: (v: MappingVehicle) => void;
  emptyLabel: string;
  currentVersion?: number;
  updatedIds?: Set<string>;
  onToggleUpdate?: (v: MappingVehicle) => void;
}) {
  const showVersion = props.currentVersion !== undefined;
  const onKey = (e: KeyboardEvent<HTMLTableRowElement>, v: MappingVehicle) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      props.onRowClick(v);
    }
  };

  return (
    <div className="card" data-mapping-table={props.testId} style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ padding: '12px 14px', borderBottom: '1px solid #e5e7eb' }}>
        <h3 style={{ margin: 0, fontSize: 15 }}>{props.title}</h3>
        {props.hint && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{props.hint}</div>}
      </div>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 12, background: '#f9fafb' }}>
              <th style={th}>Registration</th>
              <th style={th}>Brand / Model</th>
              <th style={th}>Type</th>
              <th style={th}>Axles</th>
              <th style={th}>Wheels</th>
              {showVersion && <th style={th}>Version</th>}
            </tr>
          </thead>
          <tbody>
            {props.rows.length === 0 && (
              <tr>
                <td colSpan={showVersion ? 6 : 5} style={{ padding: 14, color: '#9ca3af', textAlign: 'center' }}>
                  {props.emptyLabel}
                </td>
              </tr>
            )}
            {props.rows.map((v) => {
              const pending = props.pendingIds.has(v.id);
              const outdated = showVersion && v.version_number !== undefined && v.is_current_version === false;
              const willUpdate = props.updatedIds?.has(v.id) ?? false;
              return (
                <tr
                  key={v.id}
                  data-vehicle-row={v.registration_number}
                  className={props.editing ? 'mapping-row-editable' : undefined}
                  role={props.editing ? 'button' : undefined}
                  tabIndex={props.editing ? 0 : undefined}
                  aria-label={props.editing ? `${v.registration_number} — move to the other table` : undefined}
                  onClick={props.editing ? () => props.onRowClick(v) : undefined}
                  onKeyDown={props.editing ? (e) => onKey(e, v) : undefined}
                  style={{
                    borderTop: '1px solid #f3f4f6',
                    background: pending ? '#fefce8' : v.id === props.focusId ? '#eff6ff' : undefined,
                    transition: 'background-color 120ms ease',
                  }}
                >
                  <td style={td}>
                    <strong>{v.registration_number}</strong>
                    {pending && <div style={{ fontSize: 11, color: '#a16207' }}>{props.pendingLabel}</div>}
                  </td>
                  <td style={td}>{[v.brand, v.model].filter(Boolean).join(' ') || '—'}</td>
                  <td style={td}>{vehicleTypeOption(v.vehicle_type ?? '')?.label ?? v.vehicle_type ?? '—'}</td>
                  <td style={td}>{v.axle_count ?? '—'}</td>
                  <td style={td}>{v.wheel_count ?? '—'}</td>
                  {showVersion && (
                    <td style={td}>
                      {v.version_number !== undefined ? `v${v.version_number}` : `v${props.currentVersion}`}
                      {outdated && !willUpdate && <span style={{ fontSize: 11, color: '#b45309' }}> (current: v{props.currentVersion})</span>}
                      {outdated && props.editing && props.onToggleUpdate && (
                        <button
                          className="btn-link"
                          style={{ display: 'block', fontSize: 12 }}
                          onClick={(e) => {
                            e.stopPropagation();
                            props.onToggleUpdate?.(v);
                          }}
                        >
                          {willUpdate ? `Keep v${v.version_number}` : `Update to v${props.currentVersion}`}
                        </button>
                      )}
                      {willUpdate && <div style={{ fontSize: 11, color: '#a16207' }}>Will update to v{props.currentVersion}</div>}
                    </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

const th = { padding: '8px 12px', fontWeight: 600 } as const;
const td = { padding: '8px 12px', verticalAlign: 'top' } as const;
