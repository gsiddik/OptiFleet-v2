import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ErrorState, LoadingState } from '../../../components/States';
import { StatusBadge } from '../../../components/StatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useWorkflowTransitions, workflowButtons } from '../../../hooks/useWorkflowTransitions';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { WarrantyClaimItem } from '../../../types';
import { t } from '../../../i18n/i18n';

const LIFECYCLE: Record<string, { action: string; label: string; permission: string; primary?: boolean }[]> = {
  DRAFT: [{ action: 'submit', get label() { return t('common.actions.submit'); }, permission: 'warranty_claim.create', primary: true }],
  SUBMITTED: [{ action: 'review', get label() { return t('common.actions.moveToReview'); }, permission: 'warranty_claim.review', primary: true }],
  UNDER_REVIEW: [
    { action: 'approve', get label() { return t('common.actions.approve'); }, permission: 'warranty_claim.approve', primary: true },
    { action: 'reject', get label() { return t('common.actions.reject'); }, permission: 'warranty_claim.approve' },
  ],
  APPROVED: [
    { action: 'replacement', get label() { return t('warranty.actions.resolveViaReplacement'); }, permission: 'warranty_claim.approve', primary: true },
    { action: 'repair', get label() { return t('warranty.actions.resolveViaRepair'); }, permission: 'warranty_claim.approve' },
  ],
  REPLACEMENT: [{ action: 'settle', get label() { return t('warranty.actions.settle'); }, permission: 'warranty_claim.approve', primary: true }],
  REPAIR: [{ action: 'settle', get label() { return t('warranty.actions.settle'); }, permission: 'warranty_claim.approve', primary: true }],
  SETTLED: [{ action: 'close', get label() { return t('common.actions.close'); }, permission: 'warranty_claim.approve', primary: true }],
  REJECTED: [{ action: 'close', get label() { return t('common.actions.close'); }, permission: 'warranty_claim.approve', primary: true }],
};

/** The module action that moves a claim into each status (the workflow decides when it is offered). */
const ACTIONS_BY_TARGET: Record<string, { action: string; label: string; permission: string; primary?: boolean }> = {
  SUBMITTED: { action: 'submit', get label() { return t('common.actions.submit'); }, permission: 'warranty_claim.create', primary: true },
  UNDER_REVIEW: { action: 'review', get label() { return t('common.actions.moveToReview'); }, permission: 'warranty_claim.review', primary: true },
  APPROVED: { action: 'approve', get label() { return t('common.actions.approve'); }, permission: 'warranty_claim.approve', primary: true },
  REJECTED: { action: 'reject', get label() { return t('common.actions.reject'); }, permission: 'warranty_claim.approve' },
  REPLACEMENT: { action: 'replacement', get label() { return t('warranty.actions.resolveViaReplacement'); }, permission: 'warranty_claim.approve', primary: true },
  REPAIR: { action: 'repair', get label() { return t('warranty.actions.resolveViaRepair'); }, permission: 'warranty_claim.approve' },
  SETTLED: { action: 'settle', get label() { return t('warranty.actions.settle'); }, permission: 'warranty_claim.approve', primary: true },
  CLOSED: { action: 'close', get label() { return t('common.actions.close'); }, permission: 'warranty_claim.approve', primary: true },
};

export function WarrantyClaimDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { hasPermission } = useAuth();
  const [claim, setClaim] = useState<WarrantyClaimItem | null>(null);
  const available = useWorkflowTransitions('warranty_claim', id, claim?.status);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');

  function load() {
    apiClient.get(`/app/warranty-claims/${id}`).then((res) => setClaim(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }

  useEffect(load, [id]);

  useBreadcrumbLabel(claim?.id, claim?.claim_number);

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

  const actions = workflowButtons(available, ACTIONS_BY_TARGET, LIFECYCLE[claim.status] ?? []).filter((a) => hasPermission(a.permission));

  return (
    <div>
      <BackButton fallbackTo="/app/warranty-claims" label={t('warranty.actions.backToClaims')} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1 style={{ fontSize: 22, margin: 0 }}>{claim.claim_number}</h1>
        <StatusBadge status={claim.status} />
      </div>
      {error && <ErrorState message={error} />}

      <div className="card" style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.vehicle')}:</strong> {claim.vehicle?.registration_number ?? claim.vehicle_id} &nbsp; <strong>{t('warranty.fields.failureDate')}:</strong> {claim.failure_date}
        </p>
        <p style={{ fontSize: 13 }}>
          <strong>{t('common.fields.reason')}:</strong> {claim.reason}
        </p>
        {claim.review_note && (
          <p style={{ fontSize: 13 }}>
            <strong>{t('maintenance.fields.reviewNote')}:</strong> {claim.review_note}
          </p>
        )}
      </div>

      {actions.length > 0 && (
        <div className="card">
          <h3 style={{ marginTop: 0, fontSize: 15 }}>{t('maintenance.sections.workflowActions')}</h3>
          {(claim.status === 'UNDER_REVIEW') && (
            <textarea
              placeholder={t('maintenance.placeholders.noteRequiredForReject')}
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
