import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { FormField, inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState, EmptyState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { TireItem, VehicleItem, WheelConfigurationItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { InstalledTireSection } from './InstalledTireSection';
import { RetreadHistory } from './retread/RetreadHistory';
import { formatTimestampDate } from '../../../utils/date';
import { t } from '../../../i18n/i18n';

export function TireDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [tire, setTire] = useState<TireItem | null>(null);
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [vehicleId, setVehicleId] = useState('');
  const [wheelPosition, setWheelPosition] = useState('');
  const [availablePositions, setAvailablePositions] = useState<WheelConfigurationItem[]>([]);
  const [odometer, setOdometer] = useState('');

  // G-23: legacy onboarding — a normal live install leaves these untouched (undefined = "install now, known").
  const [showLegacyFields, setShowLegacyFields] = useState(false);
  const [installedAt, setInstalledAt] = useState('');
  const [installedAtSource, setInstalledAtSource] = useState('KNOWN');
  const [baselineTreadDepth, setBaselineTreadDepth] = useState('');
  const [baselineCondition, setBaselineCondition] = useState('');

  function load() {
    apiClient.get(`/app/tires/${id}`).then((res) => setTire(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);
  useEffect(() => {
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data)).catch(() => setVehicles([]));
  }, []);

  useBreadcrumbLabel(tire?.id, tire?.serial_number);

  // Deep links from Tire Operations / Used Tire Management (#install, #used-inspection, #retread, #scrap).
  const { hash } = useLocation();
  const loaded = tire !== null;
  useEffect(() => {
    if (loaded && hash) document.getElementById(hash.slice(1))?.scrollIntoView({ block: 'start' });
  }, [loaded, hash]);
  // Wheel position dropdown, sourced from the selected vehicle's own Wheel Configuration —
  // falls back to free text when the category has no configured positions (backend stays permissive there too).
  useEffect(() => {
    setWheelPosition('');
    const vehicle = vehicles.find((v) => v.id === vehicleId);
    if (!vehicle) {
      setAvailablePositions([]);
      return;
    }
    apiClient
      .get('/app/wheel-configurations', { params: { vehicle_category_id: vehicle.vehicle_category_id } })
      .then((res) => setAvailablePositions(res.data.data))
      .catch(() => setAvailablePositions([]));
  }, [vehicleId, vehicles]);

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

  if (error && !tire) return <ErrorState message={error} />;
  if (!tire) return <LoadingState />;

  const canInstall = hasPermission('tire.install');

  return (
    <div>
      <BackButton fallbackTo="/app/tires" label={t('tire.actions.backToTireList')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{tire.serial_number}</h1>
        <StatusBadge status={tire.current_status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.product')}:</strong> {tire.product?.name ?? tire.product_id} &nbsp; <strong>{t('tire.fields.size')}:</strong> {tire.tire_size ?? '—'} &nbsp;
          <strong>{t('inventory.fields.manufacturer')}:</strong> {tire.manufacturer ?? '—'} &nbsp;
          <strong>{t('tire.fields.dateCode')}:</strong> {tire.manufacture_date_code ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.vehicle')}:</strong> {tire.current_vehicle?.registration_number ?? '—'} &nbsp; <strong>{t('inventory.placeholders.position')}:</strong> {tire.current_position ?? '—'} &nbsp;
          <strong>{t('common.fields.warehouse')}:</strong> {tire.current_warehouse?.name ?? '—'}
        </p>
        {(tire.section_width_mm || tire.aspect_ratio || tire.rim_diameter_inch || tire.load_index || tire.speed_rating || tire.ply_rating || tire.construction_type || tire.tube_type) && (
          <p style={{ fontSize: 13, color: '#6b7280' }}>
            <strong>{t('tire.fields.sectionWidth')}:</strong> {tire.section_width_mm ? `${tire.section_width_mm}mm` : '—'} &nbsp;
            <strong>{t('tire.fields.aspectRatio')}:</strong> {tire.aspect_ratio ? `${tire.aspect_ratio}%` : '—'} &nbsp;
            <strong>{t('inventory.fields.rimDiameter')}:</strong> {tire.rim_diameter_inch ? `${tire.rim_diameter_inch}"` : '—'} &nbsp;
            <strong>{t('tire.fields.loadIndex')}:</strong> {tire.load_index ?? '—'} &nbsp;
            <strong>{t('inventory.fields.speedRating')}:</strong> {tire.speed_rating ?? '—'} &nbsp;
            <strong>{t('inventory.fields.plyRating')}:</strong> {tire.ply_rating ?? '—'} &nbsp;
            <strong>{t('inventory.fields.construction')}:</strong> {tire.construction_type ?? '—'} &nbsp;
            <strong>{t('common.fields.type')}:</strong> {tire.tube_type ?? '—'}
          </p>
        )}
      </div>

      {['IN_STOCK', 'RESERVED'].includes(tire.current_status) && canInstall && (
        <div className="card" id="install" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.actions.install')}</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <FormField label={t('common.fields.vehicle')} required>
              <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={{ ...inputStyle, width: 220 }}>
                <option value="">{t('common.fields.select')}</option>
                {vehicles.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.registration_number}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label={t('tire.fields.wheelPosition')} required>
              {availablePositions.length > 0 ? (
                <select value={wheelPosition} onChange={(e) => setWheelPosition(e.target.value)} style={{ ...inputStyle, width: 180 }}>
                  <option value="">{t('common.fields.select')}</option>
                  {availablePositions.map((p) => (
                    <option key={p.id} value={p.position_code}>
                      {p.label} ({p.position_code})
                    </option>
                  ))}
                </select>
              ) : (
                <input value={wheelPosition} onChange={(e) => setWheelPosition(e.target.value)} placeholder="FRONT_LEFT" style={{ ...inputStyle, width: 150 }} />
              )}
            </FormField>
            <FormField label={t('tire.fields.odometer')}>
              <NumericInput value={odometer} onChange={(e) => setOdometer(e.target.value)} style={{ ...inputStyle, width: 120 }} />
            </FormField>
            <button className="btn-primary" disabled={busy || !vehicleId || !wheelPosition} onClick={install} style={{ marginBottom: 14 }}>
              {t('tenantComponents.actions.install')}
            </button>
          </div>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, marginTop: 10 }}>
            <input type="checkbox" checked={showLegacyFields} onChange={(e) => setShowLegacyFields(e.target.checked)} />
            {t('tire.fields.tireAlreadyMountedBeforeTodayLegacy')}
          </label>
          {showLegacyFields && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', marginTop: 10 }}>
              <FormField label={t('tire.fields.actualEstimatedInstallDate')}>
                <input type="date" value={installedAt} onChange={(e) => setInstalledAt(e.target.value)} style={{ ...inputStyle, width: 160 }} />
              </FormField>
              <FormField label={t('tire.fields.dateConfidence')}>
                <select value={installedAtSource} onChange={(e) => setInstalledAtSource(e.target.value)} style={{ ...inputStyle, width: 130 }}>
                  {['KNOWN', 'ESTIMATED', 'UNKNOWN'].map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              </FormField>
              <FormField label={t('tire.fields.baselineTreadDepthMm')}>
                <NumericInput step="0.1" value={baselineTreadDepth} onChange={(e) => setBaselineTreadDepth(e.target.value)} style={{ ...inputStyle, width: 150 }} />
              </FormField>
              <FormField label={t('tire.fields.baselineCondition')}>
                <input value={baselineCondition} onChange={(e) => setBaselineCondition(e.target.value)} style={{ ...inputStyle, width: 150 }} />
              </FormField>
            </div>
          )}
        </div>
      )}

      {tire.installed && <InstalledTireSection installed={tire.installed} />}
      {['REMOVED', 'HOLD'].includes(tire.current_status) && (
        <div className="card" id="used-inspection" style={{ marginBottom: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <span style={{ fontSize: 14 }}>
            {tire.current_status === 'REMOVED' ? t('tire.help.tireRemovedVehicleWaitsUsedTire') : t('tire.help.tireHoldInspectAgainOnceOpen')}
          </span>
          <Link to={`/app/tires/${tire.id}/inspection`} className="btn-primary" style={{ textDecoration: 'none' }}>
            {t('tire.actions.inspect')}
          </Link>
        </div>
      )}

      {/* Retread / repair cycles run from Used Tire Management → Retread; here only their history (when any). */}
      <RetreadHistory tireId={tire.id} />

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tenantComponents.sections.installationHistory')}</h3>
        {(tire.installations ?? []).length === 0 && <EmptyState label={t('tenantComponents.empty.noInstallationsYet')} />}
        {(tire.installations ?? []).map((i) => (
          <div key={i.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {i.vehicle?.registration_number ?? i.vehicle_id} — {i.wheel_position} — installed {formatTimestampDate(i.installed_at)}
            {i.installation_date_source !== 'KNOWN' && ` (${i.installation_date_source})`}
            {i.removed_at && ` — removed ${formatTimestampDate(i.removed_at)}`}
          </div>
        ))}
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tire.sections.rotationHistory')}</h3>
        {(tire.rotations ?? []).length === 0 && <EmptyState label={t('tire.empty.noRotationsYet')} />}
        {(tire.rotations ?? []).map((r) => (
          <div key={r.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {r.from_position ?? '—'} → {r.to_position} — {formatTimestampDate(r.occurred_at)}
          </div>
        ))}
      </div>

      <div className="card">
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('tire.sections.inspectionHistory')}</h3>
        {(tire.inspections ?? []).length === 0 && <EmptyState label={t('tire.empty.noInspectionsYet')} />}
        {(tire.inspections ?? []).map((i) => (
          <div key={i.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13 }}>
            {formatTimestampDate(i.inspected_at)} — tread {i.tread_depth_mm ?? '—'}{t('tire.fields.mmPressure')} {i.pressure_psi ?? '—'}{t('tire.fields.psi')}
            {i.recommendation && ` — ${i.recommendation}`}
          </div>
        ))}
      </div>
    </div>
  );
}
