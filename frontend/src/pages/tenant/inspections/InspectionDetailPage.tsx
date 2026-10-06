import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { inputStyle } from '../../../components/FormField';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { InspectionItem, InspectionLogEntry } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { statusLabel } from '../../../i18n/statusRegistry';
import { formatDateTime } from '../../../utils/date';
import { t } from '../../../i18n/i18n';

export function InspectionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [inspection, setInspection] = useState<InspectionItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [results, setResults] = useState<Record<string, { passed?: boolean; value_text?: string; value_number?: string }>>({});
  const [findings, setFindings] = useState<{ severity: string; description: string }[]>([]);
  const [reviewNotes, setReviewNotes] = useState('');

  function load() {
    apiClient
      .get(`/app/inspections/${id}`)
      .then((res) => setInspection(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(inspection?.id, inspection ? t('breadcrumb.usebreadcrumblabel', { inspection_type: inspection.inspection_type }) : undefined);

  async function start() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/inspections/${id}/start`);
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const payload = Object.entries(results).map(([itemId, r]) => ({
        inspection_template_item_id: itemId,
        passed: r.passed,
        value_text: r.value_text,
        value_number: r.value_number,
      }));
      await apiClient.post(`/app/inspections/${id}/submit`, { results: payload, findings });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function review() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/inspections/${id}/review`, { review_notes: reviewNotes || null });
      setReviewNotes('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function createMaintenanceRequest() {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post(`/app/inspections/${id}/maintenance-request`);
      navigate(`/app/maintenance-requests/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !inspection) return <ErrorState message={error} />;
  if (!inspection) return <LoadingState />;

  // Section 9 (historical snapshot): the checklist actually used by this
  // inspection is frozen at creation time — prefer it over the live
  // template, which may have since gained or lost items.
  const items = inspection.template_snapshot ?? inspection.template?.items ?? [];

  return (
    <div>
      <BackButton fallbackTo="/app/inspections" label={t('inspection.actions.backToInspections')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {inspection.inspection_type} {t('breadcrumb.inspection')} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({inspection.vehicle?.registration_number})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <StatusBadge status={inspection.status} />
          {inspection.status === 'CREATED' && hasPermission('inspection.perform') && (
            <button className="btn-primary" disabled={busy} onClick={start}>
              {t('inspection.actions.start')}
            </button>
          )}
          {inspection.status === 'STARTED' && hasPermission('inspection.submit') && (
            <button className="btn-primary" disabled={busy} onClick={submit}>
              {t('common.actions.submit')}
            </button>
          )}
          {['FAILED', 'WARNING'].includes(inspection.status) && hasPermission('maintenance_request.create') && (
            <button className="btn-secondary" disabled={busy} onClick={createMaintenanceRequest}>
              {t('inspection.actions.createMaintenanceRequest')}
            </button>
          )}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      {['PASSED', 'WARNING', 'FAILED'].includes(inspection.status) && (inspection.reviewed_at || hasPermission('inspection.review')) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inspection.sections.supervisorReview')}</h3>
          {inspection.reviewed_at ? (
            <p style={{ fontSize: 13, margin: 0 }}>
              {t('inspection.help.reviewedOn', { date: formatDateTime(inspection.reviewed_at) })}
              {inspection.review_notes ? <> — {inspection.review_notes}</> : null}
            </p>
          ) : (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', flexWrap: 'wrap' }}>
              <textarea
                aria-label={t('inspection.fields.reviewNotes')}
                placeholder={t('inspection.placeholders.reviewNotesOptional')}
                value={reviewNotes}
                maxLength={2000}
                onChange={(e) => setReviewNotes(e.target.value)}
                style={{ ...inputStyle, flex: 1, minWidth: 240, minHeight: 50 }}
              />
              <button className="btn-primary" disabled={busy} onClick={review}>
                {t('inspection.actions.markReviewed')}
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inspection.sections.checklist')}</h3>
        {items.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>{t('inspection.empty.noChecklistItemsTemplate')}</p>}
        {items.map((item) => {
          const existing = inspection.results?.find((r) => r.inspection_template_item_id === item.id);
          const editable = inspection.status === 'STARTED';
          return (
            <div key={item.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', display: 'flex', alignItems: 'center', gap: 12 }}>
              <span style={{ flex: 1, fontSize: 13 }}>{item.item_text}</span>
              {(item.input_type === 'PASS_FAIL' || item.input_type === 'CHECKBOX') && (
                editable ? (
                  <select
                    style={{ ...inputStyle, width: 120 }}
                    value={results[item.id]?.passed === undefined ? '' : results[item.id]?.passed ? 'pass' : 'fail'}
                    onChange={(e) => setResults((prev) => ({ ...prev, [item.id]: { passed: e.target.value === 'pass' } }))}
                  >
                    <option value="">—</option>
                    <option value="pass">{t('inspection.fields.pass')}</option>
                    <option value="fail">{t('inspection.fields.fail')}</option>
                  </select>
                ) : (
                  <span>{existing?.passed === null ? '—' : existing?.passed ? t('inspection.fields.pass') : t('inspection.fields.fail')}</span>
                )
              )}
              {item.input_type === 'NUMBER' && (
                editable ? (
                  <NumericInput
                    style={{ ...inputStyle, width: 120 }}
                    onChange={(e) => setResults((prev) => ({ ...prev, [item.id]: { value_number: e.target.value } }))}
                  />
                ) : (
                  <span>{existing?.value_number ?? '—'}</span>
                )
              )}
              {(item.input_type === 'TEXT' || item.input_type === 'SELECT' || item.input_type === 'PHOTO') && (
                editable ? (
                  <input
                    style={{ ...inputStyle, width: 200 }}
                    onChange={(e) => setResults((prev) => ({ ...prev, [item.id]: { value_text: e.target.value } }))}
                  />
                ) : (
                  <span>{existing?.value_text ?? '—'}</span>
                )
              )}
            </div>
          );
        })}
      </div>

      {inspection.status === 'STARTED' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inspection.sections.findingsOptional')}</h3>
          {findings.map((f, idx) => (
            <div key={idx} style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
              <select
                value={f.severity}
                onChange={(e) => setFindings((prev) => prev.map((x, i) => (i === idx ? { ...x, severity: e.target.value } : x)))}
                style={{ ...inputStyle, width: 140 }}
              >
                {['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </select>
              <input
                placeholder={t('inspection.placeholders.description')}
                value={f.description}
                onChange={(e) => setFindings((prev) => prev.map((x, i) => (i === idx ? { ...x, description: e.target.value } : x)))}
                style={inputStyle}
              />
              <button className="btn-secondary" onClick={() => setFindings((prev) => prev.filter((_, i) => i !== idx))}>
                {t('common.actions.remove')}
              </button>
            </div>
          ))}
          <button className="btn-secondary" onClick={() => setFindings((prev) => [...prev, { severity: 'MEDIUM', description: '' }])}>
            {t('inspection.actions.addFinding')}
          </button>
        </div>
      )}

      {(inspection.findings?.length ?? 0) > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inspection.sections.recordedFindings')}</h3>
          {inspection.findings!.map((f) => (
            <div key={f.id} style={{ padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 10 }}>
              <StatusBadge status={f.severity} />
              <span>{f.description}</span>
            </div>
          ))}
        </div>
      )}

      <InspectionLog inspectionId={inspection.id} />
    </div>
  );
}

function InspectionLog({ inspectionId }: { inspectionId: string }) {
  const [logs, setLogs] = useState<InspectionLogEntry[] | null>(null);

  useEffect(() => {
    apiClient.get(`/app/inspections/${inspectionId}/logs`).then((res) => setLogs(res.data.data));
  }, [inspectionId]);

  return (
    <div className="card">
      <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('inspection.sections.log')}</h3>
      {logs === null && <p style={{ color: '#9ca3af', fontSize: 13 }}>{t('common.actions.loading')}</p>}
      {logs?.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>{t('inspection.empty.noHistoryYet')}</p>}
      {logs?.map((log) => (
        <div key={log.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 12, alignItems: 'baseline' }}>
          <span style={{ color: '#9ca3af', minWidth: 160 }}>{formatDateTime(log.created_at)}</span>
          <span style={{ flex: 1 }}>
            {log.action === 'maintenance_request_created' ? (
              <>
                {t('inspection.help.maintenanceRequestCreatedInspectionSResult')}
                {typeof log.new_values?.maintenance_request_id === 'string' && (
                  <>
                    {' '}
                    (
                    <Link to={`/app/maintenance-requests/${log.new_values.maintenance_request_id}`}>{t('inspection.actions.view')}</Link>
                    )
                  </>
                )}
              </>
            ) : typeof log.new_values?.reviewed_at === 'string' ? (
              <>{t('inspection.fields.inspectionReviewed')}</>
            ) : log.action === 'created' && typeof log.new_values?.status === 'string' ? (
              <>{t('inspection.help.inspectionCreatedStatusStatus', { status: statusLabel(log.new_values.status) })}</>
            ) : typeof log.old_values?.status === 'string' && typeof log.new_values?.status === 'string' ? (
              <>
                {t('inspection.help.statusChangedFromTo', { from: statusLabel(log.old_values.status), to: statusLabel(log.new_values.status) })}
              </>
            ) : (
              log.action
            )}
          </span>
          <span style={{ color: '#6b7280' }}>{log.actor_name ?? t('common.fields.system')}</span>
        </div>
      ))}
    </div>
  );
}
