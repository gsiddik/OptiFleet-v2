import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { BreakdownItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDateTime } from '../../../utils/date';

const STATUSES = ['', 'REPORTED', 'VERIFIED', 'ASSESSED', 'REPAIR_REQUIRED', 'WORK_ORDER_CREATED', 'RESOLVED'];

export function BreakdownListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<BreakdownItem>('/app/breakdowns', { status: status || undefined }, reloadKey);

  const columns: Column<BreakdownItem>[] = [
    { key: 'vehicle', header: 'Vehicle', render: (b) => <Link to={`/app/breakdowns/${b.id}`}>{b.vehicle?.registration_number ?? b.vehicle_id}</Link> },
    { key: 'severity', header: 'Severity', render: (b) => <StatusBadge status={b.severity} /> },
    { key: 'reported_at', header: 'Reported At', render: (b) => formatDateTime(b.reported_at) },
    { key: 'location', header: 'Location', render: (b) => b.location ?? '—' },
    { key: 'status', header: 'Status', render: (b) => <StatusBadge status={b.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Breakdowns</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('breakdown.report') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + Report Breakdown
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No breakdowns found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <ReportBreakdownModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function ReportBreakdownModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [vehicles, setVehicles] = useState<{ id: string; registration_number: string }[]>([]);
  const [vehicleId, setVehicleId] = useState('');
  const [severity, setSeverity] = useState('MINOR');
  const [location, setLocation] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/breakdowns', { vehicle_id: vehicleId, severity, location: location || null, description });
      setDescription('');
      setLocation('');
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="Report Breakdown" onClose={onClose}>
      <FormField label="Vehicle" errors={errors.vehicle_id} required>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Severity" errors={errors.severity} required>
        <select value={severity} onChange={(e) => setSeverity(e.target.value)} style={inputStyle}>
          {['MINOR', 'MAJOR', 'IMMOBILIZED'].map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Location (optional)" errors={errors.location}>
        <input value={location} onChange={(e) => setLocation(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Description" errors={errors.description} required>
        <textarea value={description} onChange={(e) => setDescription(e.target.value)} style={{ ...inputStyle, minHeight: 80 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId || !description} onClick={submit}>
          Report
        </button>
      </div>
    </Modal>
  );
}
