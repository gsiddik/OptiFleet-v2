import { useEffect, useState } from 'react';
import { apiClient, extractApiError } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { ValuationStatusBadge, VALUATION_ORDER, valuationLabel } from '../../../components/ValuationStatusBadge';
import { useAuth } from '../../../auth/AuthContext';
import { useApiList } from '../../../hooks/useApiList';
import { formatDateTime } from '../../../utils/date';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { t } from '../../../i18n/i18n';

interface ValuationRow {
  id: string;
  warehouse: { id: string; name: string } | null;
  product: { id: string; name: string; sku: string; product_type: string } | null;
  quantity_on_hand: string;
  average_unit_cost: string;
  valuation_status: string;
  valuation_basis: string | null;
}

interface Detail extends ValuationRow {
  sources: { valuation_status: string; valuation_basis: string | null; movements: number; quantity: string }[];
  reviews: { id: string; from_status: string | null; to_status: string; basis: string; reason: string; evidence_reference: string | null; reviewed_at: string; quantity_at_review: string; unit_cost_at_review: string }[];
  review_bases: Record<string, string[]>;
}

/** Valuation status of warehouse balances: what is verified, what is not, and the review that changes it. */
export function StockValuationPage() {
  const { hasPermission } = useAuth();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [openId, setOpenId] = useState<string | null>(null);
  const { data, meta, loading, error } = useApiList<ValuationRow>('/app/inventory/valuation', { status: status || undefined, search: search || undefined, page }, reloadKey);

  const columns: Column<ValuationRow>[] = [
    { key: 'warehouse', header: t('common.fields.warehouse'), render: (r) => r.warehouse?.name ?? '—' },
    { key: 'product', header: t('common.fields.product'), render: (r) => <span>{r.product?.name} <small style={{ color: '#6b7280' }}>{r.product?.sku}</small></span> },
    { key: 'qty', header: t('valuation.columns.quantity'), render: (r) => formatQty(r.quantity_on_hand) },
    { key: 'cost', header: t('valuation.columns.recordedCost'), render: (r) => formatMoney(r.average_unit_cost) },
    { key: 'status', header: t('valuation.columns.status'), render: (r) => <ValuationStatusBadge status={r.valuation_status} /> },
    { key: 'basis', header: t('valuation.columns.basis'), render: (r) => (r.valuation_basis ? t(`valuation.basis.${r.valuation_basis}`) : '—') },
    { key: 'action', header: '', render: (r) => <button className="btn-secondary" onClick={() => setOpenId(r.id)}>{t('valuation.actions.details')}</button> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 6 }}>{t('valuation.titles.page')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', margin: '0 0 14px', maxWidth: 780 }}>{t('valuation.help.page')}</p>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
        <select aria-label={t('valuation.columns.status')} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 'auto' }}>
          <option value="">{t('valuation.filters.allStatuses')}</option>
          {VALUATION_ORDER.map((s) => <option key={s} value={s}>{valuationLabel(s)}</option>)}
        </select>
        <input aria-label={t('valuation.filters.search')} placeholder={t('valuation.filters.search')} value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} style={{ ...inputStyle, width: 240 }} />
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('valuation.empty')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
      {openId && <DetailModal id={openId} canVerify={hasPermission('inventory_valuation.verify')} onClose={() => setOpenId(null)} onChanged={() => setReloadKey((k) => k + 1)} />}
    </div>
  );
}

function DetailModal({ id, canVerify, onClose, onChanged }: { id: string; canVerify: boolean; onClose: () => void; onChanged: () => void }) {
  const [detail, setDetail] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({ status: 'VERIFIED', basis: '', reason: '', evidence_reference: '', acknowledge: false });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    apiClient.get(`/app/inventory/valuation/${id}`).then((res) => setDetail(res.data.data)).catch((err) => setError(extractApiError(err).message));
  }, [id, reload]);

  const bases = detail?.review_bases[form.status] ?? [];
  const mixed = detail?.valuation_status === 'MIXED';

  async function submit() {
    setSaving(true);
    setError(null);
    setFieldErrors({});
    try {
      await apiClient.post(`/app/inventory/valuation/${id}/review`, {
        status: form.status, basis: form.basis, reason: form.reason, evidence_reference: form.evidence_reference || null, acknowledge_mixed_sources: form.acknowledge,
      });
      setForm({ ...form, reason: '', evidence_reference: '', acknowledge: false });
      setReload((n) => n + 1);
      onChanged();
    } catch (err) {
      const e = extractApiError(err);
      setError(e.message);
      setFieldErrors(e.errors ?? {});
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open title={t('valuation.titles.detail')} onClose={onClose} width={760}>
      {error && <ErrorState message={error} />}
      {!detail && !error && <LoadingState />}
      {detail && (
        <div>
          <p style={{ margin: '0 0 10px', fontSize: 14 }}>
            <strong>{detail.product?.name}</strong> · {detail.warehouse?.name} · {formatQty(detail.quantity_on_hand)} × {formatMoney(detail.average_unit_cost)} <ValuationStatusBadge status={detail.valuation_status} />
          </p>
          <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 12px' }}>{t('valuation.help.costNeverChanged')}</p>

          <h4 style={{ margin: '12px 0 6px' }}>{t('valuation.titles.sources')}</h4>
          {detail.sources.length === 0 ? <p style={{ fontSize: 13, color: '#6b7280' }}>{t('valuation.help.noSources')}</p> : (
            <table style={{ width: '100%', fontSize: 13, borderCollapse: 'collapse' }}>
              <thead><tr style={{ textAlign: 'left', color: '#6b7280' }}><th>{t('valuation.columns.status')}</th><th>{t('valuation.columns.basis')}</th><th>{t('valuation.columns.movements')}</th><th>{t('valuation.columns.quantity')}</th></tr></thead>
              <tbody>{detail.sources.map((s, i) => (
                <tr key={i}><td><ValuationStatusBadge status={s.valuation_status} /></td><td>{s.valuation_basis ? t(`valuation.basis.${s.valuation_basis}`) : '—'}</td><td>{s.movements}</td><td>{formatQty(s.quantity)}</td></tr>
              ))}</tbody>
            </table>
          )}

          <h4 style={{ margin: '14px 0 6px' }}>{t('valuation.titles.history')}</h4>
          {detail.reviews.length === 0 ? <p style={{ fontSize: 13, color: '#6b7280' }}>{t('valuation.help.noReviews')}</p> : (
            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 13 }}>
              {detail.reviews.map((r) => (
                <li key={r.id}>{formatDateTime(r.reviewed_at)} — {r.from_status ? valuationLabel(r.from_status) : '—'} → <strong>{valuationLabel(r.to_status)}</strong> ({t(`valuation.basis.${r.basis}`)}) · {r.evidence_reference ?? '—'} · {r.reason}</li>
              ))}
            </ul>
          )}

          {canVerify && (
            <div style={{ marginTop: 16, paddingTop: 12, borderTop: '1px solid #e5e7eb' }}>
              <h4 style={{ margin: '0 0 8px' }}>{t('valuation.titles.review')}</h4>
              <FormField label={t('valuation.columns.newStatus')} errors={fieldErrors.status} required>
                <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value, basis: '' })} style={inputStyle}>
                  {['VERIFIED', 'VERIFIED_ZERO', 'NOT_VALUED', 'UNVERIFIED'].map((s) => <option key={s} value={s}>{valuationLabel(s)}</option>)}
                </select>
              </FormField>
              <FormField label={t('valuation.columns.basis')} errors={fieldErrors.basis} required>
                <select value={form.basis} onChange={(e) => setForm({ ...form, basis: e.target.value })} style={inputStyle}>
                  <option value="">{t('common.fields.select')}</option>
                  {bases.map((b) => <option key={b} value={b}>{t(`valuation.basis.${b}`)}</option>)}
                </select>
              </FormField>
              <FormField label={t('valuation.columns.evidence')} errors={fieldErrors.evidence_reference}>
                <input value={form.evidence_reference} onChange={(e) => setForm({ ...form, evidence_reference: e.target.value })} style={inputStyle} maxLength={255} />
              </FormField>
              <FormField label={t('valuation.columns.reason')} errors={fieldErrors.reason} required>
                <textarea value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} style={{ ...inputStyle, minHeight: 60 }} maxLength={1000} />
              </FormField>
              {mixed && (
                <label style={{ display: 'flex', gap: 8, fontSize: 13, marginBottom: 10 }}>
                  <input type="checkbox" checked={form.acknowledge} onChange={(e) => setForm({ ...form, acknowledge: e.target.checked })} />
                  {t('valuation.help.acknowledgeMixed')}
                </label>
              )}
              <p style={{ fontSize: 12, color: '#6b7280' }}>{t('valuation.help.reviewNeverEditsCost')}</p>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                <button className="btn-secondary" onClick={onClose}>{t('common.actions.close')}</button>
                <button className="btn-primary" disabled={saving || !form.basis || !form.reason.trim()} onClick={submit}>{t('valuation.actions.saveReview')}</button>
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
