import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { MaintenanceRequestItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDateTime } from '../../../utils/date';

// NEED_INFORMATION is retired (see MaintenanceRequestService docblock) — omitted from the
// filter bar since no request can be in that status going forward, but the status itself
// still displays correctly via StatusBadge if a legacy record ever surfaces.
const STATUSES = ['', 'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'WORK_ORDER_CREATED', 'REJECTED', 'CANCELLED'];

export function MaintenanceRequestListPage() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [convertError, setConvertError] = useState<string | null>(null);
  const { data, loading, error } = useApiList<MaintenanceRequestItem>('/app/maintenance-requests', { status: status || undefined }, reloadKey);

  async function convertToWorkOrder(r: MaintenanceRequestItem) {
    setConvertError(null);
    try {
      const res = await apiClient.post(`/app/maintenance-requests/${r.id}/work-order`);
      navigate(`/app/work-orders/${res.data.data.id}`);
    } catch (err) {
      setConvertError(extractApiError(err).message);
    }
  }

  const columns: Column<MaintenanceRequestItem>[] = [
    { key: 'request_number', header: 'Request #', render: (r) => <Link to={`/app/maintenance-requests/${r.id}`}>{r.request_number}</Link> },
    { key: 'vehicle', header: 'Vehicle', render: (r) => r.vehicle?.registration_number ?? r.vehicle_id },
    { key: 'source_type', header: 'Source', render: (r) => r.source_type },
    { key: 'priority', header: 'Priority', render: (r) => r.priority },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'created_at', header: 'Created At', render: (r) => formatDateTime(r.created_at) },
    { key: 'submitted_by', header: 'Submitted by', render: (r) => r.requested_by_user?.name ?? '—' },
    {
      key: 'actions',
      header: 'Actions',
      render: (r) =>
        r.status === 'APPROVED' && hasPermission('maintenance_request.convert_work_order') ? (
          <button className="btn-primary" style={{ padding: '4px 10px', fontSize: 12 }} onClick={() => convertToWorkOrder(r)}>
            Create Work Order
          </button>
        ) : (
          '—'
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Maintenance Requests</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? statusLabel(s) : 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('maintenance_request.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Request
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {convertError && <ErrorState message={convertError} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No maintenance requests found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateRequestModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateRequestModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [vehicles, setVehicles] = useState<{ id: string; registration_number: string }[]>([]);
  const [vehicleId, setVehicleId] = useState('');
  const [priority, setPriority] = useState('MEDIUM');
  const [complaint, setComplaint] = useState('');
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
      await apiClient.post('/app/maintenance-requests', { vehicle_id: vehicleId, priority, complaint });
      setComplaint('');
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
    <Modal open={open} title="New Maintenance Request" onClose={onClose}>
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
      <FormField label="Priority" errors={errors.priority}>
        <select value={priority} onChange={(e) => setPriority(e.target.value)} style={inputStyle}>
          {['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => (
            <option key={p} value={p}>
              {p}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Complaint" errors={errors.complaint} required>
        <textarea value={complaint} onChange={(e) => setComplaint(e.target.value)} style={{ ...inputStyle, minHeight: 80 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId || !complaint} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
