import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { useAuth } from '../../../auth/AuthContext';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { NumericInput } from '../../../components/NumericInput';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { Table, type Column } from '../../../components/Table';
import { useApiList } from '../../../hooks/useApiList';
import { t as tt } from '../../../i18n/i18n';
import type { TelematicsLinkItem } from '../../../types';
import { formatDateTime } from '../../../utils/date';
import { formatNumber } from '../../../utils/number';

type Method = 'actual' | 'offset';

const pill = (background: string, color: string) => ({ background, color, padding: '2px 8px', borderRadius: 10, fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' as const });

/**
 * Vehicles linked to a telematics (OptiRadar) device. GPS distance is not an odometer: until a link is
 * calibrated against the odometer already recorded here, GPS readings are kept but never applied.
 */
export function TelematicsLinkPage() {
  const { hasPermission } = useAuth();
  const canManage = hasPermission('telematics_link.manage');
  const [onlyNeedsCalibration, setOnlyNeedsCalibration] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const { data, loading, error } = useApiList<TelematicsLinkItem>('/app/telematics-links', { needs_calibration: onlyNeedsCalibration ? 'true' : undefined, per_page: 100 }, reloadKey);
  const [editing, setEditing] = useState<TelematicsLinkItem | null>(null);

  const columns: Column<TelematicsLinkItem>[] = [
    { key: 'vehicle', header: tt('common.fields.vehicle'), render: (l) => <Link to={`/app/vehicles/${l.vehicle_id}`}>{l.registration_number}</Link> },
    { key: 'device', header: tt('telematics.fields.device'), render: (l) => l.device_ref },
    { key: 'odometer', header: tt('telematics.fields.fleetOdometer'), render: (l) => formatNumber(l.current_odometer) },
    {
      key: 'latest',
      header: tt('telematics.fields.latestReading'),
      render: (l) =>
        l.latest_reading ? (
          <span title={formatDateTime(l.latest_reading.recorded_at)}>
            {formatNumber(l.latest_reading.reported_km)}{' '}
            <small style={{ color: '#6b7280' }}>
              {l.latest_reading.kind === 'DEVICE_ODOMETER' ? tt('telematics.kind.device') : tt('telematics.kind.gps')}
            </small>
          </span>
        ) : (
          '—'
        ),
    },
    {
      key: 'calibration',
      header: tt('telematics.fields.calibration'),
      render: (l) => {
        if (l.calibrated) return <span style={pill('#dcfce7', '#15803d')}>{tt('telematics.status.calibrated')}</span>;
        return l.needs_calibration ? (
          <span style={pill('#fef3c7', '#a16207')}>{tt('telematics.status.needsCalibration')}</span>
        ) : (
          <span style={pill('#f3f4f6', '#6b7280')}>{tt('telematics.status.notNeeded')}</span>
        );
      },
    },
    {
      key: 'applied',
      header: tt('telematics.fields.lastReadingResult'),
      render: (l) => {
        if (!l.latest_reading) return '—';
        if (l.latest_reading.applied) return <span style={pill('#dcfce7', '#15803d')}>{tt('telematics.status.applied')}</span>;
        return l.latest_reading.kind === 'GPS_DISTANCE' && !l.calibrated ? (
          <span style={pill('#fef3c7', '#a16207')}>{tt('telematics.status.held')}</span>
        ) : (
          <span style={pill('#f3f4f6', '#6b7280')}>{tt('telematics.status.notHigher')}</span>
        );
      },
    },
  ];
  if (canManage) {
    columns.push({
      key: 'actions',
      header: '',
      render: (l) => (
        <button className="btn-secondary" style={{ padding: '4px 10px', fontSize: 13 }} onClick={() => setEditing(l)}>
          {tt('telematics.actions.calibrate')}
        </button>
      ),
    });
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('telematics.titles.links')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginBottom: 14, maxWidth: 760 }}>{tt('telematics.help.intro')}</p>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14 }}>
        <button onClick={() => setOnlyNeedsCalibration(false)} className={!onlyNeedsCalibration ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
          {tt('common.actions.all')}
        </button>
        <button onClick={() => setOnlyNeedsCalibration(true)} className={onlyNeedsCalibration ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
          {tt('telematics.filters.needsCalibration')}
        </button>
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('telematics.empty.noLinks')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      {editing && (
        <CalibrateModal
          link={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function CalibrateModal({ link, onClose, onSaved }: { link: TelematicsLinkItem; onClose: () => void; onSaved: () => void }) {
  const [method, setMethod] = useState<Method>('actual');
  const [actual, setActual] = useState(link.current_odometer);
  const [offset, setOffset] = useState(link.odometer_offset_km ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const value = method === 'actual' ? actual : offset;
  const valid = value !== '' && value !== '-' && value !== '.' && !Number.isNaN(Number(value));

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!valid) return;
    setSaving(true);
    setError(null);
    try {
      await apiClient.put(`/app/telematics-links/${link.id}/calibration`, method === 'actual' ? { actual_odometer_km: actual } : { odometer_offset_km: offset });
      onSaved();
    } catch (err) {
      setError(extractApiError(err).message);
      setSaving(false);
    }
  }

  return (
    <Modal open title={tt('telematics.calibrate.title', { registration_number: link.registration_number })} onClose={onClose}>
      <form onSubmit={submit}>
        <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0 }}>{tt('telematics.calibrate.help')}</p>
        {error && <div style={{ background: '#fef2f2', color: '#b91c1c', padding: 10, borderRadius: 6, marginBottom: 14, fontSize: 13 }}>{error}</div>}

        <div role="radiogroup" style={{ display: 'flex', gap: 16, marginBottom: 14, fontSize: 14, flexWrap: 'wrap' }}>
          <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <input type="radio" name="method" checked={method === 'actual'} onChange={() => setMethod('actual')} />
            {tt('telematics.calibrate.methodActual')}
          </label>
          <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <input type="radio" name="method" checked={method === 'offset'} onChange={() => setMethod('offset')} />
            {tt('telematics.calibrate.methodOffset')}
          </label>
        </div>

        {method === 'actual' ? (
          <FormField label={tt('telematics.fields.actualOdometer')} required hint={tt('telematics.calibrate.actualHint')}>
            <NumericInput value={actual} onChange={(e) => setActual(e.target.value)} style={inputStyle} autoFocus />
          </FormField>
        ) : (
          <FormField label={tt('telematics.fields.offset')} required hint={tt('telematics.calibrate.offsetHint')}>
            <NumericInput allowNegative value={offset} onChange={(e) => setOffset(e.target.value)} style={inputStyle} autoFocus />
          </FormField>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 8 }}>
          <button type="button" className="btn-secondary" onClick={onClose}>
            {tt('common.actions.cancel')}
          </button>
          <button type="submit" className="btn-primary" disabled={saving || !valid}>
            {saving ? tt('common.actions.saving') : tt('common.actions.save')}
          </button>
        </div>
      </form>
    </Modal>
  );
}
