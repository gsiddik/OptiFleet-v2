import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../../api/client';
import { ErrorState, LoadingState } from '../../../../components/States';
import { formatDate, formatDateTime } from '../../../../utils/date';
import { bodyStyleFor, truckConfigurationTypeOption, vehicleTypeOption, type VehicleType } from './vehicleTypes';
import { WheelConfigurationPreview } from './WheelConfigurationPreview';
import { PositionLabel } from '../../../../components/tires/PositionLabel';
import { PositionPanel } from './PositionPanel';
import type { PositionInstallation, VehicleWheelConfiguration, VersionPosition } from './masterTypes';
import { message } from '../../../../i18n/messages';
import { t } from '../../../../i18n/i18n';

/**
 * Vehicle Detail → Wheels Configuration. Shows the configuration VERSION mapped to this vehicle
 * (assigned only through the configuration's Vehicle Mapping page) and lets the user pick a
 * position on the preview to see — or register — the tire on it.
 */
export function VehicleWheelsConfigurationTab({ vehicleId }: { vehicleId: string }) {
  const navigate = useNavigate();
  const [data, setData] = useState<VehicleWheelConfiguration | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);

  const load = useCallback(() => {
    apiClient
      .get(`/app/vehicles/${vehicleId}/wheel-configuration`)
      .then((res) => setData(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [vehicleId]);
  useEffect(load, [load]);

  const installed = useMemo(() => new Set((data?.installations ?? []).map((i) => i.position_code)), [data]);

  if (error) return <ErrorState message={error} />;
  if (!data) return <LoadingState />;

  const mapping = data.mapping;
  if (!mapping) {
    const missing = [!data.vehicle.vehicle_type && t('tire.fields.vehicleType'), data.vehicle.axle_count == null && t('tire.fields.axles'), data.vehicle.wheel_count == null && t('tire.fields.wheels')].filter(Boolean);
    return (
      <div className="card" data-wheels-empty style={{ textAlign: 'center', padding: '36px 20px' }}>
        <h3 style={{ margin: '0 0 6px', fontSize: 16 }}>{t('tire.sections.noWheelsConfigurationAssigned')}</h3>
        <p style={{ fontSize: 13, color: '#6b7280', margin: '0 auto 14px', maxWidth: 520 }}>
          {t('tire.help.wheelsConfigurationAssignedConfigurationSVehicle')}
          {missing.length > 0 && <> {t('common.actions.complete')} {missing.join(', ')} {t('tire.help.overviewEditSpecificationsFirstSoVehicle')}</>}
        </p>
        <button className="btn-primary" onClick={() => navigate(`/app/wheel-configurations?vehicle=${vehicleId}`)}>
          {t('tire.actions.openWheelsConfigurationList')}
        </button>
      </div>
    );
  }

  const version = mapping.version;
  const master = mapping.master;
  const selectedPosition = version.positions.find((p) => p.position_code === selected) ?? null;
  const installation = data.installations.find((i) => i.position_code === selected) ?? null;

  return (
    <div data-wheels-mapped>
      <div className="card" data-wheels-summary style={{ marginBottom: 16 }}>
        <div style={{ display: 'flex', gap: 28, flexWrap: 'wrap', fontSize: 13, alignItems: 'flex-end' }}>
          <Stat label={t('tire.sections.configCode')} value={<span style={{ fontFamily: 'monospace' }}>{version.config_code}</span>} />
          <Stat label={t('tire.fields.vehicleType')} value={vehicleTypeOption(master.vehicle_type)?.label ?? master.vehicle_type} />
          {master.truck_configuration_type && <Stat label={t('tire.fields.truckConfigurationType')} value={truckConfigurationTypeOption(master.truck_configuration_type)?.label ?? master.truck_configuration_type} />}
          <Stat label={t('tire.fields.totalAxles')} value={version.total_axles} />
          <Stat label={t('tire.fields.totalWheels')} value={version.total_wheels} />
          <Stat label={t('tire.sections.spareTires')} value={version.spare_tires} />
          <Stat label={t('configuration.fields.version')} value={`v${version.version_number}`} />
          <Link to={`/app/wheel-configurations/${master.id}`} style={{ fontSize: 13, marginLeft: 'auto' }}>
            {t('tire.actions.openConfiguration')}
          </Link>
        </div>
        {!mapping.is_current_version && (
          <p style={{ fontSize: 12, color: '#92400e', margin: '10px 0 0' }}>
            {t('tire.help.configurationNewerVersionConfigCodeVehicle', { config_code: master.config_code, version_number: version.version_number })}
          </p>
        )}
      </div>

      <div className="vehicle-wheels-layout split-layout">
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tire.sections.vehiclePreview')}</h3>
          <WheelConfigurationPreview
            bodyStyle={bodyStyleFor(master.vehicle_type as VehicleType, master.truck_configuration_type)}
            input={{ front: version.front_axles, rear: version.rear_axles, spareTires: version.spare_tires }}
            selectedCode={selected}
            onPositionSelect={setSelected}
            installedCodes={installed}
          />
          <p style={{ fontSize: 11, color: '#6b7280', marginBottom: 0 }}>
            {t('tire.help.tapTireViewRegister')} <span style={{ color: '#16a34a' }}>●</span> {t('tire.help.greenOutlineTireRegisteredInstalledCount', { installedCount: installed.size, positionsCount: version.positions.length })}
          </p>
        </div>
        <div className="card" data-position-panel-card>
          {selectedPosition ? (
            <>
              <button type="button" className="btn-link" data-all-positions onClick={() => setSelected(null)} style={{ fontSize: 12, marginBottom: 6 }}>
                {t('tire.actions.allPositions')}
              </button>
              <PositionPanel key={selectedPosition.position_code} vehicleId={vehicleId} position={selectedPosition} installation={installation} onSaved={load} />
            </>
          ) : (
            <PositionSummary positions={version.positions} installations={data.installations} onSelect={setSelected} />
          )}
        </div>
      </div>

      {data.history.length > 0 && (
        <details style={{ marginTop: 16 }} data-mapping-history>
          <summary style={{ cursor: 'pointer', fontSize: 13, fontWeight: 600, color: '#374151' }}>{t('tire.sections.configurationHistoryHistoryCount', { historyCount: data.history.length })}</summary>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginTop: 8 }}>
            <tbody>
              {data.history.map((h) => (
                <tr key={h.id} style={{ borderTop: '1px solid #f3f4f6' }}>
                  <td style={{ padding: '4px 6px', fontFamily: 'monospace' }}>{h.config_code}</td>
                  <td style={{ padding: '4px 6px' }}>v{h.version_number}</td>
                  <td style={{ padding: '4px 6px' }}>{formatDateTime(h.mapped_at)}</td>
                  <td style={{ padding: '4px 6px' }}>{h.status === 'ACTIVE'
                      ? message('tire.help.current')
                      : message(h.end_reason === 'VERSION_UPDATED' ? 'tire.wheelConfiguration.historyEndedUpdated' : 'tire.wheelConfiguration.historyEndedUnmapped', { endedAt: formatDate(h.ended_at) })}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <div style={{ color: '#6b7280', fontSize: 12 }}>{label}</div>
      <div style={{ fontWeight: 600, fontSize: 15 }}>{value}</div>
    </div>
  );
}

/**
 * Shown while no position is selected: every position with its registered tire, so the panel next
 * to the preview carries information instead of empty space. A row selects that position.
 */
function PositionSummary({ positions, installations, onSelect }: { positions: VersionPosition[]; installations: PositionInstallation[]; onSelect: (code: string) => void }) {
  const byCode = new Map(installations.map((i) => [i.position_code, i]));
  return (
    <div data-position-summary>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tire.sections.positions')}</h3>
      <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 8px' }}>{t('tire.help.selectPositionPreviewListViewRegister')}</p>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: '#374151', background: '#f9fafb' }}>
              <th style={{ padding: '6px 8px' }}>{t('inventory.placeholders.position')}</th>
              <th style={{ padding: '6px 8px' }}>{t('common.fields.serialNumber')}</th>
              <th style={{ padding: '6px 8px' }}>{t('tire.fields.installationKm')}</th>
            </tr>
          </thead>
          <tbody>
            {positions.map((p) => {
              const inst = byCode.get(p.position_code);
              return (
                <tr
                  key={p.position_code}
                  tabIndex={0}
                  onClick={() => onSelect(p.position_code)}
                  onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onSelect(p.position_code))}
                  style={{ borderTop: '1px solid #f3f4f6', cursor: 'pointer' }}
                  data-summary-position={p.position_code}
                >
                  <td style={{ padding: '6px 8px' }}>
                    <PositionLabel code={p.position_code} />
                  </td>
                  <td style={{ padding: '6px 8px', fontFamily: inst ? 'monospace' : undefined, color: inst ? undefined : '#9ca3af' }}>{inst?.tire.serial_number ?? t('tire.help.notRegistered')}</td>
                  <td style={{ padding: '6px 8px' }}>{inst?.installation_odometer ?? '—'}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
