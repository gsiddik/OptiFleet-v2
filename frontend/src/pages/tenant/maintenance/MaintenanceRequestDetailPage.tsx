import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import { INSPECTION_GROUP_CODES } from '../../../types';
import type { InspectionGroupCode, InspectionGroupStatus, MaintenanceRequestAssessmentItem, MaintenanceRequestItem } from '../../../types';

// Reviewer actions are Approve/Reject only — Request Info / NEED_INFORMATION retired
// at the application level (legacy records remain readable, but no request can enter
// or be acted on via NEED_INFORMATION anymore).
const ACTIONS: Record<string, { action: string; label: string; permission: string; needsNote?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'maintenance_request.create' }, { action: 'cancel', label: 'Cancel', permission: 'maintenance_request.create' }],
  SUBMITTED: [{ action: 'review', label: 'Move to Review', permission: 'maintenance_request.review' }],
  UNDER_REVIEW: [
    { action: 'approve', label: 'Approve', permission: 'maintenance_request.approve' },
    { action: 'reject', label: 'Reject', permission: 'maintenance_request.reject', needsNote: true },
  ],
};

const GROUP_LABELS: Record<InspectionGroupCode, string> = {
  ENGINE: 'Engine',
  LUBRICATION_SYSTEM: 'Lubrication System',
  CLUTCH_TORQUE_CONVERTER: 'Clutch System / Torque Converter',
  COOLING_SYSTEM: 'Cooling System',
  FUEL_SYSTEM: 'Fuel System',
  TRANSMISSION_SYSTEM: 'Transmission System',
  EXHAUST_SYSTEM: 'Exhaust System',
  STEERING_SYSTEM: 'Steering System',
  DRIVE_AXLE_ASSEMBLY: 'Drive System / Axle Assembly',
  FRAME_CHASSIS: 'Frame / Chassis',
  ELECTRICAL_SYSTEM: 'Electrical System',
  BRAKE_SYSTEM: 'Brake System',
  SUSPENSION_SYSTEM: 'Suspension System',
  TYRE_WHEEL: 'Tyre and Wheel',
};

const GROUP_STATUSES: InspectionGroupStatus[] = ['GOOD', 'ATTENTION', 'REPAIR_REQUIRED', 'CRITICAL_UNSAFE', 'NOT_APPLICABLE'];

function emptyGroups(): Record<InspectionGroupCode, { status: InspectionGroupStatus; notes: string }> {
  return Object.fromEntries(INSPECTION_GROUP_CODES.map((code) => [code, { status: 'GOOD' as InspectionGroupStatus, notes: '' }])) as Record<
    InspectionGroupCode,
    { status: InspectionGroupStatus; notes: string }
  >;
}

export function AssessmentSection({ maintenanceRequestId, editable }: { maintenanceRequestId: string; editable: boolean }) {
  const [assessment, setAssessment] = useState<MaintenanceRequestAssessmentItem | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [groups, setGroups] = useState(emptyGroups());
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function load() {
    apiClient
      .get(`/app/maintenance-requests/${maintenanceRequestId}/assessment`)
      .then((res) => {
        const data: MaintenanceRequestAssessmentItem | null = res.data.data;
        setAssessment(data);
        if (data) {
          const next = emptyGroups();
          for (const g of data.groups) next[g.group_code] = { status: g.status, notes: g.notes ?? '' };
          setGroups(next);
          setNotes(data.notes ?? '');
        }
        setLoaded(true);
      })
      .catch(() => setLoaded(true));
  }

  useEffect(load, [maintenanceRequestId]);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/maintenance-requests/${maintenanceRequestId}/assessment`, {
        notes,
        groups: INSPECTION_GROUP_CODES.map((code) => ({ group_code: code, status: groups[code].status, notes: groups[code].notes || null })),
      });
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function clear() {
    setBusy(true);
    setError(null);
    try {
      await apiClient.delete(`/app/maintenance-requests/${maintenanceRequestId}/assessment`);
      setGroups(emptyGroups());
      setNotes('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (!loaded) return null;
  if (!assessment && !editable) return null;

  return (
    <div className="card" style={{ marginBottom: 16 }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Initial Assessment &amp; Visual Inspection</h3>
      {error && <ErrorState message={error} />}
      <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
        <thead>
          <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
            <th style={{ padding: '6px 4px' }}>Inspection Group</th>
            <th style={{ padding: '6px 4px' }}>Status</th>
            <th style={{ padding: '6px 4px' }}>Notes</th>
          </tr>
        </thead>
        <tbody>
          {INSPECTION_GROUP_CODES.map((code) => (
            <tr key={code} style={{ borderBottom: '1px solid #f3f4f6' }}>
              <td style={{ padding: '6px 4px' }}>{GROUP_LABELS[code]}</td>
              <td style={{ padding: '6px 4px' }}>
                {editable ? (
                  <select
                    value={groups[code].status}
                    onChange={(e) => setGroups((prev) => ({ ...prev, [code]: { ...prev[code], status: e.target.value as InspectionGroupStatus } }))}
                    style={{ fontSize: 13, padding: 4 }}
                  >
                    {GROUP_STATUSES.map((s) => (
                      <option key={s} value={s}>
                        {s.replace(/_/g, ' ')}
                      </option>
                    ))}
                  </select>
                ) : (
                  groups[code].status.replace(/_/g, ' ')
                )}
              </td>
              <td style={{ padding: '6px 4px' }}>
                {editable ? (
                  <input
                    value={groups[code].notes}
                    onChange={(e) => setGroups((prev) => ({ ...prev, [code]: { ...prev[code], notes: e.target.value } }))}
                    style={{ fontSize: 13, padding: 4, width: '100%' }}
                  />
                ) : (
                  groups[code].notes || '—'
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div style={{ marginTop: 10 }}>
        {editable ? (
          <textarea
            placeholder="Overall assessment notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            style={{ width: '100%', minHeight: 50, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
          />
        ) : (
          notes && (
            <p style={{ fontSize: 13 }}>
              <strong>Notes:</strong> {notes}
            </p>
          )
        )}
      </div>
      {editable && (
        <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
          <button className="btn-primary" disabled={busy} onClick={save}>
            Save
          </button>
          {assessment && (
            <button className="btn-secondary" disabled={busy} onClick={clear}>
              Clear
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export function MaintenanceRequestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [request, setRequest] = useState<MaintenanceRequestItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');

  function load() {
    apiClient
      .get(`/app/maintenance-requests/${id}`)
      .then((res) => setRequest(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(request?.id, request?.request_number);

  async function act(action: string, needsNote?: boolean) {
    if (needsNote && !note.trim()) {
      setError('A note is required for this action.');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/maintenance-requests/${id}/${action}`, needsNote ? { note } : {});
      setNote('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  async function convertToWorkOrder() {
    setBusy(true);
    setError(null);
    try {
      const res = await apiClient.post(`/app/maintenance-requests/${id}/work-order`);
      navigate(`/app/work-orders/${res.data.data.id}`);
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !request) return <ErrorState message={error} />;
  if (!request) return <LoadingState />;

  const actions = (ACTIONS[request.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <BackButton fallbackTo="/app/maintenance-requests" label="← Back to Maintenance Request" />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>
          {request.request_number} <span style={{ color: '#9ca3af', fontWeight: 400 }}>({request.vehicle?.registration_number})</span>
        </h1>
        <StatusBadge status={request.status} />
      </div>

      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <h3 style={{ marginTop: 0, fontSize: 15 }}>Details</h3>
        <p style={{ fontSize: 13 }}>
          <strong>Source:</strong> {request.source_type} &nbsp; <strong>Priority:</strong> {request.priority}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Complaint:</strong> {request.complaint}
        </p>
        {request.review_note && (
          <p style={{ fontSize: 13 }}>
            <strong>Review Note:</strong> {request.review_note}
          </p>
        )}
      </div>

      <AssessmentSection maintenanceRequestId={request.id} editable={request.status === 'DRAFT' && hasPermission('maintenance_request.create')} />

      {actions.length > 0 && (
        <div className="card" style={{ marginBottom: 16 }}>
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Workflow Actions</h3>
          {actions.some((a) => a.needsNote) && (
            <textarea
              placeholder="Note (required for reject)"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              style={{ width: '100%', minHeight: 60, marginBottom: 10, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
            />
          )}
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {actions.map((a) => (
              <button key={a.action} className="btn-secondary" disabled={busy} onClick={() => act(a.action, a.needsNote)}>
                {a.label}
              </button>
            ))}
          </div>
        </div>
      )}

      {request.status === 'APPROVED' && hasPermission('maintenance_request.convert_work_order') && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Work Order</h3>
          <p style={{ fontSize: 13, color: '#6b7280' }}>This request is approved and ready to be converted into a Work Order.</p>
          <button className="btn-primary" disabled={busy} onClick={convertToWorkOrder}>
            Create Work Order
          </button>
        </div>
      )}

      {request.status === 'WORK_ORDER_CREATED' && request.work_order_id && (
        <div className="card">
          <p style={{ fontSize: 13 }}>
            Work Order created:{' '}
            <button className="btn-link" onClick={() => navigate(`/app/work-orders/${request.work_order_id}`)}>
              View Work Order
            </button>
          </p>
        </div>
      )}
    </div>
  );
}
