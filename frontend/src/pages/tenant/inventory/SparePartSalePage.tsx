import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { useAuth } from '../../../auth/AuthContext';
import { StatusBadge } from '../../../components/StatusBadge';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { Pagination } from '../../../components/Pagination';
import { useApiList } from '../../../hooks/useApiList';
import type { SparePartSaleItem, WorkOrderPartReturnItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatMoney } from '../../../utils/money';
import { formatQty } from '../../../utils/quantity';
import { ScrappedTireSaleForm } from './ScrappedTireSaleForm';

const inputStyle: React.CSSProperties = { padding: '6px 8px', fontSize: 12, border: '1px solid #d1d5db', borderRadius: 4 };
const STATUS_FILTERS = ['', 'DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED'];

export function SparePartSalePage() {
  const { hasPermission } = useAuth();
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Tires selected on Used Tire Management → Scrap (row or bulk Sell).
  const [params, setParams] = useSearchParams();
  const tireIds = (params.get('tire_ids') ?? '').split(',').filter(Boolean);
  const { data: sales, meta, loading, error: listError, reload } = useApiList<SparePartSaleItem>(
    '/app/sparepart-sales',
    { status: statusFilter || undefined, page },
    0,
  );
  const { data: eligibleReturns, reload: reloadEligible } = useApiList<WorkOrderPartReturnItem>(
    '/app/used-part-returns',
    { disposition_status: 'FINALIZED', disposition: 'SELL_ELIGIBLE', per_page: 100 },
    0,
  );

  const canCreate = hasPermission('sparepart_sale.create');
  const canApprove = hasPermission('sparepart_sale.approve');

  async function submit(action: () => Promise<unknown>) {
    setError(null);
    try {
      await action();
      reload();
      reloadEligible();
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Sell Sparepart</h1>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0, marginBottom: 16 }}>
        Only quantity finalized as SELL_ELIGIBLE by Used Sparepart Processing can be sold. Every sale is approved by someone other than the maker before it counts as final.
      </p>

      {canCreate && tireIds.length > 0 && (
        <ScrappedTireSaleForm
          tireIds={tireIds}
          onCancel={() => setParams({}, { replace: true })}
          onCreated={() => {
            setParams({}, { replace: true });
            reload();
          }}
        />
      )}

      {canCreate && (
        <NewSaleForm
          eligibleReturns={eligibleReturns.filter((r) => (r.remaining_eligible_quantity ?? 0) > 0)}
          onCreated={() => submit(() => Promise.resolve())}
          onSubmitCreate={(payload) => submit(() => apiClient.post('/app/sparepart-sales', payload))}
        />
      )}

      <h3 style={{ fontSize: 15, marginTop: 24 }}>Sales</h3>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUS_FILTERS.map((s) => (
          <button key={s} onClick={() => { setStatusFilter(s); setPage(1); }} className={statusFilter === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 11 }}>
            {s || 'All'}
          </button>
        ))}
      </div>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {listError && <ErrorState message={listError} />}
      {!listError && loading && <LoadingState />}
      {!listError && !loading && sales.length === 0 && <EmptyState label="No sales found." />}
      {!listError && !loading && sales.map((sale) => (
        <div key={sale.id} className="card" style={{ marginBottom: 10, fontSize: 13 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
            <span>
              {sale.source_type === 'SCRAPPED_TIRE' && <strong data-sale-serial>Tire {sale.tire_serial_number} · </strong>}
              {sale.product?.name ?? sale.product_id} — qty {formatQty(sale.quantity)} × {formatMoney(sale.unit_price)} = {formatMoney(sale.total_amount)} ({sale.sale_type})
              <br />
              <span style={{ color: '#6b7280', fontSize: 12 }}>
                Buyer: {sale.buyer_type === 'PARTNER' ? sale.partner?.name : sale.buyer_name}
              </span>
            </span>
            <StatusBadge status={sale.status} />
          </div>
          {sale.status === 'DRAFT' && canCreate && (
            <button className="btn-primary" disabled={busyId === sale.id} onClick={() => {
              setBusyId(sale.id);
              submit(() => apiClient.post(`/app/sparepart-sales/${sale.id}/submit`)).finally(() => setBusyId(null));
            }}>
              Submit for Approval
            </button>
          )}
          {sale.status === 'PENDING_APPROVAL' && (
            canApprove ? (
              <div style={{ display: 'flex', gap: 6 }}>
                <button className="btn-primary" disabled={busyId === sale.id} onClick={() => {
                  setBusyId(sale.id);
                  submit(() => apiClient.post(`/app/sparepart-sales/${sale.id}/decide`, { decision: 'APPROVE' })).finally(() => setBusyId(null));
                }}>
                  Approve
                </button>
                <button className="btn-secondary" disabled={busyId === sale.id} onClick={() => {
                  setBusyId(sale.id);
                  submit(() => apiClient.post(`/app/sparepart-sales/${sale.id}/decide`, { decision: 'REJECT' })).finally(() => setBusyId(null));
                }}>
                  Reject
                </button>
              </div>
            ) : (
              <span style={{ fontSize: 11, color: '#6b7280' }}>Awaiting an approver (the maker cannot approve their own sale).</span>
            )
          )}
          {sale.status === 'REJECTED' && sale.rejection_reason && (
            <span style={{ fontSize: 12, color: '#b91c1c' }}>Rejected: {sale.rejection_reason}</span>
          )}
        </div>
      ))}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}

function NewSaleForm({
  eligibleReturns, onSubmitCreate,
}: {
  eligibleReturns: WorkOrderPartReturnItem[];
  onCreated: () => void;
  onSubmitCreate: (payload: Record<string, unknown>) => Promise<void>;
}) {
  const [returnId, setReturnId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [saleType, setSaleType] = useState<'OPERATIONAL_REUSE' | 'SCRAP_MATERIAL'>('OPERATIONAL_REUSE');
  const [buyerName, setBuyerName] = useState('');
  const [unitPrice, setUnitPrice] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const selected = eligibleReturns.find((r) => r.id === returnId);

  async function submit() {
    setSubmitting(true);
    try {
      await onSubmitCreate({
        work_order_part_return_id: returnId, quantity, sale_type: saleType,
        buyer_type: 'EXTERNAL', buyer_name: buyerName, unit_price: unitPrice,
      });
      setReturnId('');
      setQuantity('');
      setBuyerName('');
      setUnitPrice('');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="card" style={{ marginBottom: 10 }}>
      <h3 style={{ marginTop: 0, fontSize: 14 }}>New Sale</h3>
      {eligibleReturns.length === 0 && <EmptyState label="No SELL_ELIGIBLE quantity available. Process a used return first." />}
      {eligibleReturns.length > 0 && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
          <select value={returnId} onChange={(e) => setReturnId(e.target.value)} style={{ ...inputStyle, width: 240 }}>
            <option value="">Select eligible item…</option>
            {eligibleReturns.map((r) => (
              <option key={r.id} value={r.id}>
                {r.product?.name ?? r.product_id} (remaining {formatQty(r.remaining_eligible_quantity)})
              </option>
            ))}
          </select>
          <NumericInput step="0.01" placeholder="Qty" value={quantity} onChange={(e) => setQuantity(e.target.value)} style={{ ...inputStyle, width: 80 }} max={selected?.remaining_eligible_quantity} />
          <select value={saleType} onChange={(e) => setSaleType(e.target.value as 'OPERATIONAL_REUSE' | 'SCRAP_MATERIAL')} style={{ ...inputStyle, width: 160 }}>
            <option value="OPERATIONAL_REUSE" disabled={selected?.condition === 'USED_FAULTY'}>Operational Reuse</option>
            <option value="SCRAP_MATERIAL">Scrap Material</option>
          </select>
          <input placeholder="Buyer name" value={buyerName} onChange={(e) => setBuyerName(e.target.value)} style={{ ...inputStyle, width: 160 }} />
          <NumericInput step="0.01" placeholder="Unit price" value={unitPrice} onChange={(e) => setUnitPrice(e.target.value)} style={{ ...inputStyle, width: 100 }} />
          <button className="btn-primary" disabled={submitting || !returnId || !quantity || !buyerName || !unitPrice} onClick={submit}>
            Create Sale (Draft)
          </button>
        </div>
      )}
    </div>
  );
}
