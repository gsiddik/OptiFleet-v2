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

  useBreadcrumbLabel(inspection?.id, inspection ? `${inspection.inspection_type} Inspection` : undefined);

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
      <BackButton fallbackTo="/app/inspections" label="← Back to Inspections" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {inspection.inspection_type} Inspection <span style={{ color: '#9ca3af', fontWeight: 400 }}>({inspection.vehicle?.registration_number})</span>
        </h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <StatusBadge status={inspection.status} />
          {inspection.status === 'CREATED' && hasPermission('inspection.perform') && (
            <button className="btn-primary" disabled={busy} onClick={start}>
              Start
            </button>
          )}
          {inspection.status === 'STARTED' && hasPermission('inspection.submit') && (
            <button className="btn-primary" disabled={busy} onClick={submit}>
              Submit
            </button>
          )}
          {['FAILED', 'WARNING'].includes(inspection.status) && hasPermission('maintenance_request.create') && (
            <button className="btn-secondary" disabled={busy} onClick={createMaintenanceRequest}>
              Create Maintenance Request
            </button>
          )}
        </div>
      </div>

      {error && <ErrorState message={error} />}

      {['PASSED', 'WARNING', 'FAILED'].includes(inspection.status) && (inspection.reviewed_at || hasPermission('inspection.review')) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Supervisor Review</h3>
          {inspection.reviewed_at ? (
            <p style={{ fontSize: 13, margin: 0 }}>
              Reviewed on {new Date(inspection.reviewed_at).toLocaleString()}
              {inspection.review_notes ? <> — {inspection.review_notes}</> : null}
            </p>
          ) : (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', flexWrap: 'wrap' }}>
              <textarea
                aria-label="Review notes"
                placeholder="Review notes (optional)"
                value={reviewNotes}
                maxLength={2000}
                onChange={(e) => setReviewNotes(e.target.value)}
                style={{ ...inputStyle, flex: 1, minWidth: 240, minHeight: 50 }}
              />
              <button className="btn-primary" disabled={busy} onClick={review}>
                Mark Reviewed
              </button>
            </div>
          )}
        </div>
      )}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Checklist</h3>
        {items.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>No checklist items on this template.</p>}
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
                    <option value="pass">Pass</option>
                    <option value="fail">Fail</option>
                  </select>
                ) : (
                  <span>{existing?.passed === null ? '—' : existing?.passed ? 'Pass' : 'Fail'}</span>
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
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Findings (optional)</h3>
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
                placeholder="Description"
                value={f.description}
                onChange={(e) => setFindings((prev) => prev.map((x, i) => (i === idx ? { ...x, description: e.target.value } : x)))}
                style={inputStyle}
              />
              <button className="btn-secondary" onClick={() => setFindings((prev) => prev.filter((_, i) => i !== idx))}>
                Remove
              </button>
            </div>
          ))}
          <button className="btn-secondary" onClick={() => setFindings((prev) => [...prev, { severity: 'MEDIUM', description: '' }])}>
            + Add Finding
          </button>
        </div>
      )}

      {(inspection.findings?.length ?? 0) > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Recorded Findings</h3>
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
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Log</h3>
      {logs === null && <p style={{ color: '#9ca3af', fontSize: 13 }}>Loading…</p>}
      {logs?.length === 0 && <p style={{ color: '#9ca3af', fontSize: 13 }}>No history yet.</p>}
      {logs?.map((log) => (
        <div key={log.id} style={{ padding: '8px 0', borderBottom: '1px solid #f3f4f6', fontSize: 13, display: 'flex', gap: 12, alignItems: 'baseline' }}>
          <span style={{ color: '#9ca3af', minWidth: 160 }}>{new Date(log.created_at).toLocaleString()}</span>
          <span style={{ flex: 1 }}>
            {log.action === 'maintenance_request_created' ? (
              <>
                Maintenance Request created from this inspection's result
                {typeof log.new_values?.maintenance_request_id === 'string' && (
                  <>
                    {' '}
                    (
                    <Link to={`/app/maintenance-requests/${log.new_values.maintenance_request_id}`}>view</Link>
                    )
                  </>
                )}
              </>
            ) : typeof log.new_values?.reviewed_at === 'string' ? (
              <>Inspection reviewed</>
            ) : log.action === 'created' && typeof log.new_values?.status === 'string' ? (
              <>Inspection created (status: {log.new_values.status})</>
            ) : typeof log.old_values?.status === 'string' && typeof log.new_values?.status === 'string' ? (
              <>
                Status changed from {log.old_values.status} to {log.new_values.status}
              </>
            ) : (
              log.action
            )}
          </span>
          <span style={{ color: '#6b7280' }}>{log.actor_name ?? 'System'}</span>
        </div>
      ))}
    </div>
  );
}
