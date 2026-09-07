import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import type { WarrantyClaimItem } from '../../../types';

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', label: 'Submit', permission: 'warranty_claim.create', primary: true }],
  SUBMITTED: [{ action: 'review', label: 'Move to Review', permission: 'warranty_claim.review', primary: true }],
  UNDER_REVIEW: [
    { action: 'approve', label: 'Approve', permission: 'warranty_claim.approve', primary: true },
    { action: 'reject', label: 'Reject', permission: 'warranty_claim.approve' },
  ],
  APPROVED: [
    { action: 'replacement', label: 'Resolve via Replacement', permission: 'warranty_claim.approve', primary: true },
    { action: 'repair', label: 'Resolve via Repair', permission: 'warranty_claim.approve' },
  ],
  REPLACEMENT: [{ action: 'settle', label: 'Settle', permission: 'warranty_claim.approve', primary: true }],
  REPAIR: [{ action: 'settle', label: 'Settle', permission: 'warranty_claim.approve', primary: true }],
  SETTLED: [{ action: 'close', label: 'Close', permission: 'warranty_claim.approve', primary: true }],
  REJECTED: [{ action: 'close', label: 'Close', permission: 'warranty_claim.approve', primary: true }],
};

export function WarrantyClaimDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [claim, setClaim] = useState<WarrantyClaimItem | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');

  function load() {
    apiClient.get(`/app/warranty-claims/${id}`).then((res) => setClaim(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  async function act(action: string) {
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/warranty-claims/${id}/${action}`, action === 'reject' || action === 'approve' ? { note: note || undefined } : {});
      setNote('');
      load();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusy(false);
    }
  }

  if (error && !claim) return <ErrorState message={error} />;
  if (!claim) return <LoadingState />;

  const actions = (LIFECYCLE[claim.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{claim.claim_number}</h1>
        <StatusBadge status={claim.status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>Vehicle:</strong> {claim.vehicle?.registration_number ?? claim.vehicle_id} &nbsp; <strong>Failure Date:</strong> {claim.failure_date}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>Reason:</strong> {claim.reason}
        </p>
        {claim.review_note && (
          <p style={{ fontSize: 13 }}>
            <strong>Review Note:</strong> {claim.review_note}
          </p>
        )}
      </div>

      {actions.length > 0 && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>Workflow Actions</h3>
          {(claim.status === 'UNDER_REVIEW') && (
            <textarea
              placeholder="Note (required for reject)"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              style={{ width: '100%', minHeight: 50, marginBottom: 10, padding: 8, borderRadius: 6, border: '1px solid #d1d5db', fontSize: 13 }}
            />
          )}
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {actions.map((a) => (
              <button key={a.action} className={a.primary ? 'btn-primary' : 'btn-secondary'} disabled={busy || (a.action === 'reject' && !note)} onClick={() => act(a.action)}>
                {a.label}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
