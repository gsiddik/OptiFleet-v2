import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { InspectionItem, InspectionTemplateItem } from '../../../types';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDateTime } from '../../../utils/date';
import { t as tt } from '../../../i18n/i18n';

const TYPES = ['', 'PRE_TRIP', 'POST_TRIP', 'PERIODIC', 'WORKSHOP', 'MAINTENANCE'];
const STATUSES = ['', 'CREATED', 'ASSIGNED', 'STARTED', 'SUBMITTED', 'PASSED', 'WARNING', 'FAILED'];

export function InspectionListPage() {
  const { hasPermission } = useAuth();
  const [searchParams] = useSearchParams();
  const [type, setType] = useState(searchParams.get('type') ?? '');
  const [status, setStatus] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const { data, loading, error } = useApiList<InspectionItem>('/app/inspections', {
    inspection_type: type || undefined,
    status: status || undefined,
  }, reloadKey);

  const columns: Column<InspectionItem>[] = [
    { key: 'vehicle', header: tt('common.fields.vehicle'), render: (i) => <Link to={`/app/inspections/${i.id}`}>{i.vehicle?.registration_number ?? i.vehicle_id}</Link> },
    { key: 'type', header: tt('common.fields.type'), render: (i) => i.inspection_type },
    { key: 'template', header: tt('configuration.fields.template'), render: (i) => i.template?.name ?? '—' },
    { key: 'inspection_date', header: tt('inspection.fields.inspectionDate'), render: (i) => formatDateTime(i.created_at) },
    { key: 'status', header: tt('common.fields.status'), render: (i) => <StatusBadge status={i.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{tt('inspection.titles.inspections')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 10, flexWrap: 'wrap' }}>
        {TYPES.map((t) => (
          <button key={t} onClick={() => setType(t)} className={type === t ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {t || tt('inspection.actions.allTypes')}
          </button>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s ? statusLabel(s) : tt('inspection.actions.allStatus')}
          </button>
        ))}
      </div>
      <Toolbar
        actions={
          hasPermission('inspection.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              {tt('inspection.actions.newInspection')}
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={tt('inspection.empty.noInspectionsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateInspectionModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateInspectionModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [vehicles, setVehicles] = useState<{ id: string; registration_number: string }[]>([]);
  const [templates, setTemplates] = useState<InspectionTemplateItem[]>([]);
  const [vehicleId, setVehicleId] = useState('');
  const [templateId, setTemplateId] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/vehicles', { params: { per_page: 100 } }).then((res) => setVehicles(res.data.data));
    apiClient.get('/app/inspection-templates', { params: { status: 'ACTIVE', per_page: 100 } }).then((res) => setTemplates(res.data.data));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/inspections', { vehicle_id: vehicleId, inspection_template_id: templateId });
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
    <Modal open={open} title={tt('inspection.modals.newInspection')} onClose={onClose}>
      <FormField label={tt('common.fields.vehicle')} errors={errors.vehicle_id} required>
        <select value={vehicleId} onChange={(e) => setVehicleId(e.target.value)} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {vehicles.map((v) => (
            <option key={v.id} value={v.id}>
              {v.registration_number}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label={tt('configuration.fields.template')} errors={errors.inspection_template_id} required>
        <select value={templateId} onChange={(e) => setTemplateId(e.target.value)} style={inputStyle}>
          <option value="">{tt('common.fields.select')}</option>
          {templates.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name} ({t.inspection_type})
            </option>
          ))}
        </select>
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-primary" disabled={submitting || !vehicleId || !templateId} onClick={submit}>
          {tt('common.actions.create')}
        </button>
      </div>
    </Modal>
  );
}
