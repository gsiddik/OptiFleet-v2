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
import { statusLabel } from '../../../i18n/statusRegistry';
import { t } from '../../../i18n/i18n';
import { formatDate } from '../../../utils/date';

const STATUSES = ['', 'DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'REPLACEMENT', 'REPAIR', 'SETTLED', 'CLOSED'];

export function WarrantyClaimListPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<WarrantyClaimItem>('/app/warranty-claims', { status: status || undefined }, reloadKey);

  const columns: Column<WarrantyClaimItem>[] = [
    { key: 'number', header: t('warranty.fields.claimNumber'), render: (c) => <Link to={`/app/warranty-claims/${c.id}`}>{c.claim_number}</Link> },
    { key: 'vehicle', header: t('common.fields.vehicle'), render: (c) => c.vehicle?.registration_number ?? c.vehicle_id },
    { key: 'failure_date', header: t('warranty.fields.failureDate'), render: (c) => formatDate(c.failure_date) },
    { key: 'status', header: t('common.fields.status'), render: (c) => <StatusBadge status={c.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('warranty.titles.warrantyClaims')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {s ? statusLabel(s) : t('common.actions.all')}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('warranty_claim.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {t('warranty.actions.newClaim')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('warranty.empty.noWarrantyClaimsFound')} />}
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
    <Modal open={open} title={t('warranty.modals.newWarrantyClaim')} onClose={onClose}>
      <FormField label={t('common.fields.vehicle')} errors={errors.vehicle_id} required>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={inputStyle}>
          <option value="">{t('common.fields.select')}</option>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={t('warranty.fields.failureDate')} errors={errors.failure_date} required>
        <input type="date" value={failureDate} onChange={(e) => setFailureDate(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label={t('common.fields.reason')} errors={errors.reason} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {t('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId || !failureDate || !reason} onClick={submit}>
          {t('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}
