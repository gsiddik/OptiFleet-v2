import { useCallback, useEffect, useMemo, useState, type KeyboardEvent, type ReactNode } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { BackButton } from '../../../../components/BackButton';
import { Modal } from '../../../../components/Modal';
import { ErrorState, LoadingState } from '../../../../components/States';
import { useAuth } from '../../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../../navigation/BreadcrumbLabelContext';
import { truckConfigurationTypeOption, vehicleTypeOption } from './vehicleTypes';
import { t } from '../../../../i18n/i18n';

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
      setNotice(t('tire.messages.mappingSavedAddedAddedRemovedRemoved', { added: summary.added, removed: summary.removed, updated: summary.updated }));
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
      <BackButton fallbackTo="/app/wheel-configurations" label={t('tire.actions.backToWheelConfiguration')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{t('tire.titles.vehicleMapping')}</h1>
        <div style={{ display: 'flex', gap: 8 }} data-mapping-actions>
          {!editing && canEdit && (
            <button
              className="btn-primary"
              onClick={() => {
                setNotice(null);
                setEditing(true);
              }}
            >
              {t('common.actions.edit')}
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
                {t('common.actions.cancel')}
              </button>
              <button className="btn-primary" disabled={saving} onClick={() => (changes ? setConfirming(true) : setEditing(false))}>
                {t('common.actions.save')}
              </button>
            </>
          )}
        </div>
      </div>

      <div className="card" data-mapping-header style={{ marginBottom: 16, display: 'flex', gap: 28, flexWrap: 'wrap', fontSize: 13 }}>
        <Stat label={t('tire.sections.wheelsConfigurationCode')} value={<span style={{ fontFamily: 'monospace' }}>{config.config_code}</span>} />
        <Stat label={t('tire.sections.vehicleType')} value={vehicleTypeOption(config.vehicle_type)?.label ?? config.vehicle_type} />
        {config.truck_configuration_type && <Stat label={t('tire.sections.truckConfigurationType')} value={truckConfigurationTypeOption(config.truck_configuration_type)?.label ?? config.truck_configuration_type} />}
        <Stat label={t('tire.sections.totalAxles')} value={config.total_axles} />
        <Stat label={t('tire.sections.totalWheels')} value={config.total_wheels} />
        <Stat label={t('tire.sections.totalSpareTire')} value={config.spare_tires} />
        <Stat label={t('tire.sections.version')} value={config.version_number} />
      </div>

      {notice && (
        <div role="status" data-mapping-notice style={{ fontSize: 13, color: '#166534', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 6, padding: '8px 12px', marginBottom: 12 }}>
          {notice}
        </div>
      )}
      {saveError && (
        <div role="alert" data-mapping-error style={{ fontSize: 13, color: '#991b1b', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6, padding: '8px 12px', marginBottom: 12 }}>
          <div style={{ fontWeight: 600 }}>{saveError.blockers.length ? t('tire.help.vehicleCannotRemappedBecauseActiveTires') : saveError.message}</div>
          {saveError.blockers.map((b) => (
            <div key={`${b.vehicle_registration_number}-${b.position_code}`} style={{ fontFamily: 'monospace' }}>
              {t('tire.help.vehicleRegistrationNumberPositionCodeTire', { vehicle_registration_number: b.vehicle_registration_number, position_code: b.position_code, tire_serial_number: b.tire_serial_number })}
            </div>
          ))}
          {saveError.details.map((d) => (
            <div key={d}>{d}</div>
          ))}
          {saveError.blockers.length > 0 && <div style={{ marginTop: 4 }}>{t('tire.help.removeTransferTheseTiresFirst')}</div>}
        </div>
      )}
      {editing && (
        <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 10px' }}>
          {t('tire.help.clickVehicleMoveBetweenTablesChanges')}
        </p>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(380px, 100%), 1fr))', gap: 16, alignItems: 'start' }}>
        <VehicleTable
          testId="available"
          title={t('tire.sections.availableVehiclesLeftCount', { leftCount: left.length })}
          hint={t('tire.help.sameVehicleTypeTotalAxlesAxles', { total_axles: config.total_axles, total_wheels: config.total_wheels })}
          rows={left}
          editing={editing}
          focusId={focusVehicleId}
          pendingIds={removed}
          pendingLabel={t('tire.actions.willBeUnmapped')}
          onRowClick={clickLeft}
          emptyLabel={t('tire.empty.noEligibleVehicles')}
        />
        <VehicleTable
          testId="mapped"
          title={t('tire.sections.mappedVehiclesRightCount', { rightCount: right.length })}
          rows={right}
          editing={editing}
          focusId={focusVehicleId}
          pendingIds={added}
          pendingLabel={t('tire.actions.willBeMapped')}
          onRowClick={clickRight}
          emptyLabel={t('tire.empty.noVehiclesMappedConfiguration')}
          currentVersion={config.version_number}
          updatedIds={updated}
          onToggleUpdate={(v) => toggle(updated, setUpdated, v.id, !updated.has(v.id))}
        />
      </div>

      {(data.excluded.incomplete_vehicle_data > 0 || data.excluded.mapped_to_other_configuration > 0) && (
        <p data-mapping-excluded style={{ fontSize: 12, color: '#6b7280', marginTop: 12 }}>
          {t('tire.help.notListedIncompleteVehicleDataVehicle', { incomplete_vehicle_data: data.excluded.incomplete_vehicle_data, mapped_to_other_configuration: data.excluded.mapped_to_other_configuration })}
        </p>
      )}

      {confirming && (
        <Modal open title={t('tire.modals.saveVehicleMapping')} onClose={() => setConfirming(false)} width={420}>
          <div data-mapping-confirm style={{ fontSize: 14, display: 'grid', gap: 4 }}>
            <div>{t('tire.fields.vehiclesAddedAddedCount', { addedCount: added.size })}</div>
            <div>{t('tire.fields.vehiclesRemovedRemovedCount', { removedCount: removed.size })}</div>
            {updated.size > 0 && <div>{t('tire.help.vehiclesChangedVersionVersionNumberUpdated', { version_number: config.version_number, updatedCount: updated.size })}</div>}
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 18 }}>
            <button className="btn-secondary" onClick={() => setConfirming(false)}>
              {t('common.actions.back')}
            </button>
            <button className="btn-primary" disabled={saving} onClick={save}>
              {saving ? t('common.actions.saving') : t('tire.actions.confirmSave')}
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
              <th style={th}>{t('tire.fields.registration')}</th>
              <th style={th}>{t('inventory.fields.brandModel')}</th>
              <th style={th}>{t('common.fields.type')}</th>
              <th style={th}>{t('tire.fields.axles')}</th>
              <th style={th}>{t('tire.fields.wheels')}</th>
              {showVersion && <th style={th}>{t('tire.sections.version')}</th>}
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
                  aria-label={props.editing ? t('tire.tooltips.registrationNumberMoveOtherTable', { registration_number: v.registration_number }) : undefined}
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
                      {outdated && !willUpdate && <span style={{ fontSize: 11, color: '#b45309' }}> {t('tire.help.currentVCurrentVersion', { currentVersion: props.currentVersion })}</span>}
                      {outdated && props.editing && props.onToggleUpdate && (
                        <button
                          className="btn-link"
                          style={{ display: 'block', fontSize: 12 }}
                          onClick={(e) => {
                            e.stopPropagation();
                            props.onToggleUpdate?.(v);
                          }}
                        >
                          {willUpdate ? t('tire.actions.keepVVersionNumber', { version_number: v.version_number }) : t('tire.actions.updateToVCurrentVersion', { currentVersion: props.currentVersion })}
                        </button>
                      )}
                      {willUpdate && <div style={{ fontSize: 11, color: '#a16207' }}>{t('tire.help.updateVCurrentVersion', { currentVersion: props.currentVersion })}</div>}
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
