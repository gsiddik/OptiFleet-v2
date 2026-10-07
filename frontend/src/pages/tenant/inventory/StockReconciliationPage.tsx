import { useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useAuth } from '../../../auth/AuthContext';
import { useApiList } from '../../../hooks/useApiList';
import { formatDateTime } from '../../../utils/date';
import { formatQty } from '../../../utils/quantity';
import { t } from '../../../i18n/i18n';

const CATEGORIES = ['PROVABLE_UNDEDUCTED', 'COVERED_BY_WO_ISSUE', 'RESOLVED_BY_OPNAME', 'AMBIGUOUS_NO_RECEIPT', 'AMBIGUOUS_TIRE', 'NOT_WAREHOUSE_ORIGIN'] as const;
const CATEGORY_COLOR: Record<string, string> = {
  PROVABLE_UNDEDUCTED: '#b45309', COVERED_BY_WO_ISSUE: '#166534', RESOLVED_BY_OPNAME: '#1d4ed8',
  AMBIGUOUS_NO_RECEIPT: '#7c3aed', AMBIGUOUS_TIRE: '#7c3aed', NOT_WAREHOUSE_ORIGIN: '#4b5563', CORRECTED_BY_RECONCILIATION: '#0f766e',
};

interface OpnameEvidence {
  opname_number: string; snapshot_at: string; posted_at: string; system_quantity: string; physical_quantity: string; variance: string; snapshot_stale: boolean; movements_after: number;
}
interface Candidate {
  id: string; installation_id: string; kind: string; serial: string | null; installed_at: string; registration_number: string | null; category: string; evidence: string | null;
  warehouse_id: string | null; product_id: string; opname: OpnameEvidence | null;
  warehouse_evidence: { warehouse_id: string; warehouse: string; system_quantity: string; opname: OpnameEvidence | null }[];
}
interface Adjustment {
  id: string; serial: string | null; installed_at: string | null; status: string; reason: string; proposed_by: string; proposed_at: string; decided_at: string | null; decision_note: string | null; applied_at: string | null;
  evidence: { category?: string; ledger_on_hand_at_proposal?: string; opname?: OpnameEvidence | null };
}
interface Report { summary: Record<string, number>; balance: { warehouse: string; product: string; ledger_on_hand: string; registered_in_stock_units: number; difference: string }[] }

function categoryLabel(c: string) {
  return t(`reconciliation.category.${c}`);
}

function CategoryBadge({ category }: { category: string }) {
  const color = CATEGORY_COLOR[category] ?? '#4b5563';
  return <span title={t(`reconciliation.definition.${category}`)} style={{ color, border: `1px solid ${color}`, borderRadius: 999, padding: '1px 8px', fontSize: 12, fontWeight: 600, whiteSpace: 'nowrap' }}>{categoryLabel(category)}</span>;
}

function OpnameLine({ o }: { o: OpnameEvidence }) {
  return (
    <span style={{ fontSize: 12 }}>
      {t('reconciliation.opname.line', { number: o.opname_number, time: formatDateTime(o.posted_at), system: formatQty(o.system_quantity), physical: formatQty(o.physical_quantity), variance: formatQty(o.variance) })}
      {o.snapshot_stale && <strong style={{ color: '#b45309' }}> · {t('reconciliation.opname.stale')}</strong>}
      {o.movements_after > 0 && <span style={{ color: '#6b7280' }}> · {t('reconciliation.opname.movementsAfter', { count: o.movements_after })}</span>}
    </span>
  );
}

/** Reconciliation of old serialized installations: report with evidence, then proposal → approval → apply. */
export function StockReconciliationPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState<'report' | 'adjustments'>('report');
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 6 }}>{t('reconciliation.titles.page')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', margin: '0 0 12px', maxWidth: 820 }}>{t('reconciliation.help.page')}</p>
      <div role="tablist" style={{ display: 'flex', borderBottom: '1px solid #e5e7eb', marginBottom: 14 }}>
        {(['report', 'adjustments'] as const).map((id) => (
          <button key={id} role="tab" aria-selected={tab === id} onClick={() => setTab(id)} style={{ padding: '8px 16px', fontSize: 14, fontWeight: 600, background: 'none', border: 'none', cursor: 'pointer', borderBottom: tab === id ? '2px solid #1d4ed8' : '2px solid transparent', color: tab === id ? '#1d4ed8' : '#6b7280' }}>
            {t(`reconciliation.tabs.${id}`)}
          </button>
        ))}
      </div>
      {tab === 'report' ? <ReportTab canManage={hasPermission('inventory_reconcile.manage')} onProposed={() => setTab('adjustments')} /> : <AdjustmentsTab canManage={hasPermission('inventory_reconcile.manage')} canApprove={hasPermission('inventory_reconcile.approve')} />}
    </div>
  );
}

function ReportTab({ canManage, onProposed }: { canManage: boolean; onProposed: () => void }) {
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const [report, setReport] = useState<Report | null>(null);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [proposing, setProposing] = useState<Candidate | null>(null);
  const { data, meta, loading, error } = useApiList<Candidate>('/app/inventory/reconciliation', { category: category || undefined, page }, 0, (res) => setReport({ summary: res.summary as Report['summary'], balance: res.balance as Report['balance'] }));

  const columns: Column<Candidate>[] = [
    { key: 'serial', header: t('common.fields.serialNumber'), render: (c) => <span>{c.serial ?? '—'} <small style={{ color: '#6b7280' }}>{c.kind === 'TIRE' ? t('reconciliation.kind.tire') : t('reconciliation.kind.component')}</small></span> },
    { key: 'vehicle', header: t('common.fields.vehicle'), render: (c) => c.registration_number ?? '—' },
    { key: 'installed', header: t('reconciliation.columns.installedAt'), render: (c) => formatDateTime(c.installed_at) },
    { key: 'category', header: t('reconciliation.columns.classification'), render: (c) => <CategoryBadge category={c.category} /> },
    { key: 'evidence', header: t('reconciliation.columns.evidence'), render: (c) => (
      <div style={{ maxWidth: 380 }}>
        <div style={{ fontSize: 12 }}>{c.evidence}</div>
        {c.opname && <OpnameLine o={c.opname} />}
        {c.warehouse_evidence.length > 0 && (
          <button className="btn-secondary" style={{ marginTop: 4 }} onClick={() => setExpanded((s) => { const n = new Set(s); if (n.has(c.id)) n.delete(c.id); else n.add(c.id); return n; })}>
            {expanded.has(c.id) ? t('reconciliation.actions.hideWarehouses') : t('reconciliation.actions.showWarehouses', { count: c.warehouse_evidence.length })}
          </button>
        )}
      </div>
    ) },
    { key: 'action', header: '', render: (c) => canManage && c.category === 'PROVABLE_UNDEDUCTED' ? <button className="btn-primary" onClick={() => setProposing(c)}>{t('reconciliation.actions.propose')}</button> : null },
  ];

  return (
    <div>
      {report && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: 8, marginBottom: 14 }}>
          {[...CATEGORIES, 'CORRECTED_BY_RECONCILIATION'].map((c) => (
            <button key={c} onClick={() => { setCategory(category === c || c === 'CORRECTED_BY_RECONCILIATION' ? '' : c); setPage(1); }} title={t(`reconciliation.definition.${c}`)} aria-pressed={category === c}
              style={{ textAlign: 'left', padding: 10, borderRadius: 8, border: category === c ? '2px solid #1d4ed8' : '1px solid #e5e7eb', background: '#fff', cursor: 'pointer' }}>
              <div style={{ fontSize: 22, fontWeight: 700, color: CATEGORY_COLOR[c] }}>{report.summary[c] ?? 0}</div>
              <div style={{ fontSize: 12, color: '#374151' }}>{categoryLabel(c)}</div>
            </button>
          ))}
        </div>
      )}
      <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 10px' }}>{t('reconciliation.help.opnameProves')}</p>
      {report && report.balance.length > 0 && (
        <details style={{ marginBottom: 12, fontSize: 13 }}>
          <summary>{t('reconciliation.titles.balance')}</summary>
          <ul>{report.balance.map((b, i) => <li key={i}>{b.warehouse} / {b.product}: {t('reconciliation.balance.line', { ledger: formatQty(b.ledger_on_hand), units: b.registered_in_stock_units, difference: formatQty(b.difference) })}</li>)}</ul>
        </details>
      )}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('reconciliation.empty.report')} />}
      {!error && !loading && data.length > 0 && (
        <Table columns={columns} rows={data} expandedIds={expanded} renderExpanded={(c) => (
          <div style={{ padding: 10, fontSize: 12 }}>
            <strong>{t('reconciliation.titles.tireEvidence')}</strong>
            <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
              {c.warehouse_evidence.map((w) => (
                <li key={w.warehouse_id}>{w.warehouse}: {t('reconciliation.tire.systemQuantity', { quantity: formatQty(w.system_quantity) })} — {w.opname ? <OpnameLine o={w.opname} /> : t('reconciliation.tire.noOpname')}</li>
              ))}
            </ul>
            <p style={{ color: '#6b7280' }}>{t('reconciliation.help.tireNotAdjusted')}</p>
          </div>
        )} />
      )}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
      {proposing && <ProposeModal candidate={proposing} onClose={() => setProposing(null)} onDone={() => { setProposing(null); onProposed(); }} />}
    </div>
  );
}

function ProposeModal({ candidate, onClose, onDone }: { candidate: Candidate; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  async function submit() {
    setSaving(true);
    setError(null);
    try {
      await apiClient.post('/app/inventory/reconciliation/adjustments', { installation_id: candidate.installation_id, reason });
      onDone();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSaving(false);
    }
  }
  return (
    <Modal open title={t('reconciliation.titles.propose')} onClose={onClose}>
      <p style={{ fontSize: 13 }}>{t('reconciliation.help.proposeSummary', { serial: candidate.serial ?? '—', date: formatDateTime(candidate.installed_at) })}</p>
      <p style={{ fontSize: 12, color: '#6b7280' }}>{t('reconciliation.help.noStockMovesYet')}</p>
      {error && <ErrorState message={error} />}
      <FormField label={t('common.fields.reason')} required>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} maxLength={1000} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={onClose}>{t('common.actions.cancel')}</button>
        <button className="btn-primary" disabled={saving || !reason.trim()} onClick={submit}>{t('reconciliation.actions.submitProposal')}</button>
      </div>
    </Modal>
  );
}

function AdjustmentsTab({ canManage, canApprove }: { canManage: boolean; canApprove: boolean }) {
  const { user } = useAuth();
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [actionError, setActionError] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<Adjustment | null>(null);
  const [note, setNote] = useState('');
  const [applying, setApplying] = useState<Adjustment | null>(null);
  const { data, meta, loading, error } = useApiList<Adjustment>('/app/inventory/reconciliation/adjustments', { status: status || undefined, page }, reloadKey);

  async function act(path: string, body: object = {}) {
    setActionError(null);
    try {
      await apiClient.post(`/app/inventory/reconciliation/adjustments/${path}`, body);
    } catch (err) {
      setActionError(extractApiError(err).message);
    }
    setReloadKey((k) => k + 1);
  }

  const columns: Column<Adjustment>[] = [
    { key: 'serial', header: t('common.fields.serialNumber'), render: (a) => a.serial ?? '—' },
    { key: 'status', header: t('common.fields.status'), render: (a) => <strong>{t(`reconciliation.adjustmentStatus.${a.status}`)}</strong> },
    { key: 'reason', header: t('common.fields.reason'), render: (a) => <span style={{ fontSize: 12 }}>{a.reason}{a.decision_note ? ` — ${a.decision_note}` : ''}</span> },
    { key: 'evidence', header: t('reconciliation.columns.evidence'), render: (a) => <span style={{ fontSize: 12 }}>{a.evidence.ledger_on_hand_at_proposal ? t('reconciliation.adjustment.ledgerAtProposal', { quantity: formatQty(a.evidence.ledger_on_hand_at_proposal) }) : '—'}{a.evidence.opname && <><br /><OpnameLine o={a.evidence.opname} /></>}</span> },
    { key: 'when', header: t('reconciliation.columns.timeline'), render: (a) => <span style={{ fontSize: 12 }}>{t('reconciliation.adjustment.proposedAt', { time: formatDateTime(a.proposed_at) })}{a.decided_at && <><br />{t('reconciliation.adjustment.decidedAt', { time: formatDateTime(a.decided_at) })}</>}{a.applied_at && <><br />{t('reconciliation.adjustment.appliedAt', { time: formatDateTime(a.applied_at) })}</>}</span> },
    { key: 'action', header: '', render: (a) => (
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        {a.status === 'PENDING_APPROVAL' && canApprove && a.proposed_by !== user?.id && (
          <>
            <button className="btn-primary" onClick={() => act(`${a.id}/approve`)}>{t('common.actions.approve')}</button>
            <button className="btn-secondary" onClick={() => { setRejecting(a); setNote(''); }}>{t('common.actions.reject')}</button>
          </>
        )}
        {a.status === 'PENDING_APPROVAL' && a.proposed_by === user?.id && <small style={{ color: '#6b7280' }}>{t('reconciliation.help.makerCannotApprove')}</small>}
        {a.status === 'APPROVED' && canManage && <button className="btn-primary" onClick={() => setApplying(a)}>{t('reconciliation.actions.apply')}</button>}
      </div>
    ) },
  ];

  return (
    <div>
      <select aria-label={t('common.fields.status')} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 'auto', marginBottom: 12 }}>
        <option value="">{t('reconciliation.filters.allStatuses')}</option>
        {['PENDING_APPROVAL', 'APPROVED', 'APPLIED', 'REJECTED', 'SUPERSEDED'].map((s) => <option key={s} value={s}>{t(`reconciliation.adjustmentStatus.${s}`)}</option>)}
      </select>
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('reconciliation.empty.adjustments')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
      <Modal open={rejecting !== null} title={t('reconciliation.titles.reject')} onClose={() => setRejecting(null)}>
        <FormField label={t('common.fields.reason')} required>
          <textarea value={note} onChange={(e) => setNote(e.target.value)} style={{ ...inputStyle, minHeight: 70 }} maxLength={1000} />
        </FormField>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn-secondary" onClick={() => setRejecting(null)}>{t('common.actions.cancel')}</button>
          <button className="btn-primary" disabled={!note.trim()} onClick={async () => { const a = rejecting; setRejecting(null); if (a) await act(`${a.id}/reject`, { note }); }}>{t('common.actions.reject')}</button>
        </div>
      </Modal>
      <ConfirmDialog open={applying !== null} title={t('reconciliation.titles.apply')} message={t('reconciliation.help.applyConfirm', { serial: applying?.serial ?? '—' })} confirmLabel={t('reconciliation.actions.apply')}
        onCancel={() => setApplying(null)} onConfirm={async () => { const a = applying; setApplying(null); if (a) await act(`${a.id}/apply`); }} />
    </div>
  );
}
