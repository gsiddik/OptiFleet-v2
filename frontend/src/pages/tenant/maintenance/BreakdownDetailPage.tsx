import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useWorkflowTransitions, workflowButtons } from '../../../hooks/useWorkflowTransitions';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { BreakdownItem } from '../../../types';
import { formatDateTime } from '../../../utils/date';
import { t } from '../../../i18n/i18n';

const ACTIONS: Record<string, { action: string; label: string; labelKey?: string; permission: string }[]> = {
  REPORTED: [{ action: 'verify', label: 'Verify', labelKey: 'platform.payments.actions.verify', permission: 'breakdown.review' }],
  VERIFIED: [{ action: 'assess', label: 'Mark Assessed', labelKey: 'maintenance.actions.markAssessed', permission: 'breakdown.review' }],
  ASSESSED: [{ action: 'require-repair', label: 'Require Repair', labelKey: 'maintenance.actions.requireRepair', permission: 'breakdown.review' }],
};

/** The module action that moves a breakdown into each status (the workflow decides when it is offered). */
const ACTIONS_BY_TARGET: Record<string, { action: string; label: string; labelKey?: string; permission: string }> = {
  VERIFIED: { action: 'verify', label: 'Verify', labelKey: 'platform.payments.actions.verify', permission: 'breakdown.review' },
  ASSESSED: { action: 'assess', label: 'Mark Assessed', labelKey: 'maintenance.actions.markAssessed', permission: 'breakdown.review' },
  REPAIR_REQUIRED: { action: 'require-repair', label: 'Require Repair', labelKey: 'maintenance.actions.requireRepair', permission: 'breakdown.review' },
};

export function BreakdownDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [breakdown, setBreakdown] = useState<BreakdownItem | null>(null);
  const available = useWorkflowTransitions('breakdown', id, breakdown?.status);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');
  const [complaint, setComplaint] = useState('');

  function load() {
    apiClient
      .get(`/app/breakdowns/${id}`)
      .then((res) => setBreakdown(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(breakdown?.id, breakdown ? t('breadcrumb.usebreadcrumblabel2', { value: breakdown.vehicle?.registration_number ?? '' }) : undefined);

  async function act(action: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/breakdowns/${id}/${action}`, { note: note || undefined });
      setNote('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function resolve() {
    await act('resolve');
  }

  async function convertToRequest() {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post(`/app/breakdowns/${id}/convert-to-request`, { complaint: complaint || breakdown?.description, priority: 'HIGH' });
      navigate(`/app/maintenance-requests/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !breakdown) return <ErrorState message={error} />;
  if (!breakdown) return <LoadingState />;

  const actions = workflowButtons(available, ACTIONS_BY_TARGET, ACTIONS[breakdown.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <BackButton fallbackTo="/app/breakdowns" label={t('maintenance.actions.backToBreakdown')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {t('maintenance.titles.breakdown')} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({breakdown.vehicle?.registration_number})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <StatusBadge status={breakdown.severity} />
          <StatusBadge status={breakdown.status} />
        </div>
      </div>

      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('common.sections.details')}</h3>
        <p style={{ fontSize: 13 }}>
          <strong>{t('maintenance.fields.reported')}:</strong> {formatDateTime(breakdown.reported_at)} &nbsp; <strong>{t('maintenance.fields.location')}:</strong> {breakdown.location ?? '—'}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.description')}:</strong> {breakdown.description}
        </p>
        {breakdown.response_notes && (
          <p style={{ fontSize: 13 }}>
            <strong>{t('common.fields.notes')}:</strong> {breakdown.response_notes}
          </p>
        )}
      </div>

      {(actions.length > 0 || (breakdown.status === 'REPAIR_REQUIRED' && hasPermission('breakdown.resolve'))) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('maintenance.sections.workflowActions')}</h3>
          <textarea
            placeholder={t('inventory.placeholders.noteOptional')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            style={{ width: '100%', minHeight: 50, marginBottom: 10, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
          />
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {actions.map((a) => (
              <button key={a.action} className="btn-secondary" disabled={busy} onClick={() => act(a.action)}>
                {a.label}
              </button>
            ))}
            {breakdown.status === 'REPAIR_REQUIRED' && hasPermission('breakdown.resolve') && (
              <button className="btn-secondary" disabled={busy} onClick={resolve}>
                {t('maintenance.actions.markResolved')}
              </button>
            )}
          </div>
        </div>
      )}

      {breakdown.status === 'REPAIR_REQUIRED' && !breakdown.maintenance_request_id && hasPermission('breakdown.review') && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('maintenance.sections.convertToMaintenanceRequest')}</h3>
          <textarea
            placeholder={t('maintenance.placeholders.complaintDefaultsBreakdownDescription')}
            value={complaint}
            onChange={(e) => setComplaint(e.target.value)}
            style={{ width: '100%', minHeight: 60, marginBottom: 10, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
          />
          <button className="btn-primary" disabled={busy} onClick={convertToRequest}>
            {t('inspection.actions.createMaintenanceRequest')}
          </button>
        </div>
      )}

      {breakdown.maintenance_request_id && (
        <div className="card">
          <p style={{ fontSize: 13 }}>
            {t('maintenance.fields.maintenanceRequestCreated')}:{' '}
            <button className="btn-link" onClick={() => navigate(`/app/maintenance-requests/${breakdown.maintenance_request_id}`)}>
              {t('maintenance.actions.viewRequest')}
            </button>
          </p>
        </div>
      )}

      {breakdown.work_order_id && (
        <div className="card" style={{ marginTop: 16 }}>
          <p style={{ fontSize: 13 }}>
            {t('common.fields.workOrder')}:{' '}
            <button className="btn-link" onClick={() => navigate(`/app/work-orders/${breakdown.work_order_id}`)}>
              {t('maintenance.actions.viewWorkOrder')}
            </button>
          </p>
        </div>
      )}
    </div>
  );
}
