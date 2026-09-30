import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { useAuth } from '../../../auth/AuthContext';
import { StatusBadge } from '../../../components/StatusBadge';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { Pagination } from '../../../components/Pagination';
import { useApiList } from '../../../hooks/useApiList';
import { formatQty } from '../../../utils/quantity';
import type { WorkOrderPartReturnItem } from '../../../types';

const STATUS_FILTERS = ['', 'PENDING_RETURN', 'PENDING_INSPECTION', 'INSPECTED', 'PENDING_APPROVAL', 'REJECTED', 'FINALIZED'];
const DISPOSITIONS = ['REPAIR', 'REUSE', 'QUARANTINE', 'SCRAP', 'SELL_ELIGIBLE'] as const;

const inputStyle: React.CSSProperties = { padding: '6px 8px', fontSize: 12, border: '1px solid #d1d5db', borderRadius: 4 };

export function UsedPartDispositionPage() {
  const { hasPermission } = useAuth();
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data, meta, loading, error: listError, reload } = useApiList<WorkOrderPartReturnItem>(
    '/app/used-part-returns',
    { disposition_status: statusFilter || undefined, page },
    0,
  );

  const canInspect = hasPermission('used_part.inspect');
  const canDispose = hasPermission('used_part.dispose');
  const canApprove = hasPermission('used_part.approve');

  // Warehouses for receiving a removed component (loaded only when the user can receive).
  const [warehouses, setWarehouses] = useState<{ id: string; name: string }[]>([]);
  useEffect(() => {
    if (!canInspect) return;
    apiClient
      .get('/app/warehouses', { params: { per_page: 100 } })
      .then((res) => setWarehouses(res.data.data))
      .catch(() => setWarehouses([]));
  }, [canInspect]);

  async function submit(id: string, action: 'receive' | 'inspect' | 'propose-disposition' | 'decide', body: Record<string, unknown>) {
    setBusyId(id);
    setError(null);
    try {
      await apiClient.post(`/app/used-part-returns/${id}/${action}`, body);
      reload();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Used Sparepart Processing</h1>
      <p style={{ fontSize: 12, color: '#6b7280', marginTop: 0, marginBottom: 16 }}>
        Old components removed from a vehicle (Work Order → Issuance &amp; Return → Removed Components) are received into a warehouse, inspected, given a
        proposed disposition, and approved by someone other than the proposer before they can reach available stock. New parts returned unused are
        processed under Inventory → Return.
      </p>
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
      {!listError && !loading && data.length === 0 && <EmptyState label="No used-sparepart returns found." />}
      {!listError && !loading && data.map((r) => (
        <RowCard
          key={r.id}
          item={r}
          busy={busyId === r.id}
          canInspect={canInspect}
          canDispose={canDispose}
          canApprove={canApprove}
          warehouses={warehouses}
          onReceive={(warehouseId) => submit(r.id, 'receive', { warehouse_id: warehouseId })}
          onInspect={(qty, cond, notes, evidence) => submit(r.id, 'inspect', { accepted_quantity: qty, condition: cond, notes: notes || undefined, evidence: evidence || undefined })}
          onPropose={(disposition, reason) => submit(r.id, 'propose-disposition', { disposition, reason: reason || undefined })}
          onDecide={(decision, note) => submit(r.id, 'decide', { decision, note: note || undefined })}
        />
      ))}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}

function RowCard({
  item, busy, canInspect, canDispose, canApprove, warehouses, onReceive, onInspect, onPropose, onDecide,
}: {
  item: WorkOrderPartReturnItem;
  busy: boolean;
  canInspect: boolean;
  canDispose: boolean;
  canApprove: boolean;
  warehouses: { id: string; name: string }[];
  onReceive: (warehouseId: string) => void;
  onInspect: (qty: string, condition: string, notes: string, evidence: string) => void;
  onPropose: (disposition: string, reason: string) => void;
  onDecide: (decision: 'APPROVE' | 'REJECT', note: string) => void;
}) {
  const [acceptedQty, setAcceptedQty] = useState(item.quantity);
  const [inspectCondition, setInspectCondition] = useState<'USED_GOOD' | 'USED_FAULTY'>(item.condition === 'USED_FAULTY' ? 'USED_FAULTY' : 'USED_GOOD');
  const [notes, setNotes] = useState('');
  const [inspectionEvidence, setInspectionEvidence] = useState('');
  const [disposition, setDisposition] = useState<string>(DISPOSITIONS[0]);
  const [reason, setReason] = useState('');
  const [decideNote, setDecideNote] = useState('');
  const [receiveWarehouseId, setReceiveWarehouseId] = useState('');
  const workOrder = item.work_order ?? item.planned_part?.work_order ?? null;

  return (
    <div className="card" style={{ marginBottom: 10, fontSize: 13 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
        <span>
          <strong>{item.product?.name ?? item.product_id}</strong> — qty {formatQty(item.quantity)} · {item.condition === 'USED_FAULTY' ? 'Faulty' : 'Good'}
          {workOrder && (
            <span style={{ color: '#6b7280' }}>
              {' '}· WO <Link to={`/app/work-orders/${workOrder.id}`}>{workOrder.wo_number}</Link>
            </span>
          )}
          {item.work_order?.vehicle && <span style={{ color: '#6b7280' }}> · {item.work_order.vehicle.registration_number}</span>}
        </span>
        <StatusBadge status={item.disposition_status} />
      </div>
      <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
        {item.return_source === 'REMOVED_COMPONENT' ? 'Removed component' : 'Used-part return (legacy)'}
        {item.removed_component && ` · removed ${new Date(item.removed_component.removed_at).toLocaleString()}`}
        {item.returner && ` by ${item.returner.name}`}
        {item.warehouse && ` · at ${item.warehouse.name}`}
      </div>
      {item.disposition_status === 'PENDING_RETURN' && (
        canInspect ? (
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center', marginBottom: 6 }}>
            <select aria-label="Receive into warehouse" value={receiveWarehouseId} onChange={(e) => setReceiveWarehouseId(e.target.value)} style={{ ...inputStyle, width: 200 }}>
              <option value="">Receive into warehouse…</option>
              {warehouses.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
            <button className="btn-primary" disabled={busy || !receiveWarehouseId} onClick={() => onReceive(receiveWarehouseId)}>
              Receive to Warehouse
            </button>
          </div>
        ) : (
          <span style={{ fontSize: 12, color: '#6b7280' }}>Awaiting physical return to a warehouse.</span>
        )
      )}
      {(item.reason || item.evidence) && (
        <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
          {item.reason && <span>Reason: {item.reason}</span>}
          {item.evidence && (
            <>
              {item.reason && ' · '}
              Return evidence:{' '}
              <a href={item.evidence} target="_blank" rel="noreferrer">
                {item.evidence}
              </a>
            </>
          )}
        </div>
      )}
      {item.inspection_evidence && (
        <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
          Inspection evidence:{' '}
          <a href={item.inspection_evidence} target="_blank" rel="noreferrer">
            {item.inspection_evidence}
          </a>
        </div>
      )}

      {item.disposition_status === 'PENDING_INSPECTION' && canInspect && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
          <input type="number" step="0.01" placeholder="Accepted qty" value={acceptedQty} onChange={(e) => setAcceptedQty(e.target.value)} style={{ ...inputStyle, width: 100 }} />
          <select value={inspectCondition} onChange={(e) => setInspectCondition(e.target.value as 'USED_GOOD' | 'USED_FAULTY')} style={{ ...inputStyle, width: 140 }}>
            <option value="USED_GOOD">Used — Good</option>
            <option value="USED_FAULTY">Used — Faulty</option>
          </select>
          <input placeholder="Inspection notes" value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, width: 200 }} />
          <input placeholder="Evidence photo URL (optional)" value={inspectionEvidence} onChange={(e) => setInspectionEvidence(e.target.value)} style={{ ...inputStyle, width: 220 }} />
          <button className="btn-primary" disabled={busy || !acceptedQty} onClick={() => onInspect(acceptedQty, inspectCondition, notes, inspectionEvidence)}>
            Record Inspection
          </button>
        </div>
      )}

      {(item.disposition_status === 'INSPECTED' || item.disposition_status === 'REJECTED') && canDispose && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
          {item.disposition_status === 'REJECTED' && <span style={{ fontSize: 11, color: '#b91c1c', width: '100%' }}>Previous disposition was rejected — propose again.</span>}
          <select value={disposition} onChange={(e) => setDisposition(e.target.value)} style={{ ...inputStyle, width: 160 }}>
            {DISPOSITIONS.map((d) => (
              <option key={d} value={d} disabled={item.condition === 'USED_FAULTY' && (d === 'REUSE' || d === 'SELL_ELIGIBLE')}>
                {d}
              </option>
            ))}
          </select>
          <input placeholder="Reason (optional)" value={reason} onChange={(e) => setReason(e.target.value)} style={{ ...inputStyle, width: 200 }} />
          <button className="btn-primary" disabled={busy} onClick={() => onPropose(disposition, reason)}>
            Propose Disposition
          </button>
        </div>
      )}

      {item.disposition_status === 'PENDING_APPROVAL' && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
          <span style={{ fontSize: 12, color: '#374151' }}>Proposed: {item.disposition}{item.disposition_reason && ` — ${item.disposition_reason}`}</span>
          {canApprove ? (
            <>
              <input placeholder="Note (optional)" value={decideNote} onChange={(e) => setDecideNote(e.target.value)} style={{ ...inputStyle, width: 200 }} />
              <button className="btn-primary" disabled={busy} onClick={() => onDecide('APPROVE', decideNote)}>
                Approve
              </button>
              <button className="btn-secondary" disabled={busy} onClick={() => onDecide('REJECT', decideNote)}>
                Reject
              </button>
            </>
          ) : (
            <span style={{ fontSize: 11, color: '#6b7280' }}>Awaiting an approver (the proposer cannot approve their own disposition).</span>
          )}
        </div>
      )}

      {item.disposition_status === 'FINALIZED' && (
        <span style={{ fontSize: 12, color: '#065f46' }}>
          Finalized as {item.disposition}
          {item.disposition === 'REUSE' && ' — restocked to available inventory.'}
          {item.disposition && item.disposition !== 'REUSE' && ' — no inventory movement (was never in available stock).'}
        </span>
      )}
    </div>
  );
}
