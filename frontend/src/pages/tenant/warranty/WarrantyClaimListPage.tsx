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
import type { VehicleItem, WarrantyClaimItem } from '../../../types';

const STATUSES = ['', 'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'REPLACEMENT', 'REPAIR', 'SETTLED', 'CLOSED'];

export function WarrantyClaimListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<WarrantyClaimItem>('/app/warranty-claims', { status: status || undefined }, reloadKey);

  const columns: Column<WarrantyClaimItem>[] = [
    { key: 'number', header: 'Claim #', render: (c) => <Link to={`/app/warranty-claims/${c.id}`}>{c.claim_number}</Link> },
    { key: 'vehicle', header: 'Vehicle', render: (c) => c.vehicle?.registration_number ?? c.vehicle_id },
    { key: 'failure_date', header: 'Failure Date', render: (c) => c.failure_date },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Warranty Claims</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('warranty_claim.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + New Claim
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No warranty claims found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [vehicles, setVehicles] = useState<VehicleItem[]>([]);
  const [vehicleId, setVehicleId] = useState('');
  const [failureDate, setFailureDate] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data)).catch(() => setVehicles([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/warranty-claims', { vehicle_id: vehicleId, failure_date: failureDate, reason });
      setReason('');
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
    <Modal open={open} title="New Warranty Claim" onClose={onClose}>
      <FormField label="Vehicle" errors={errors.vehicle_id}>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Failure Date" errors={errors.failure_date}>
        <input type="date" value={failureDate} onChange={(e) => setFailureDate(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Reason" errors={errors.reason}>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId || !failureDate || !reason} onClick={submit}>
          Create
        </button>
      </div>
    </Modal>
  );
}
