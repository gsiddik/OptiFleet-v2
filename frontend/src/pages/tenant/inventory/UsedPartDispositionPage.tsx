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
import { NumericInput } from '../../../components/NumericInput';
import { ImageUploadField } from '../../../components/ImageUploadField';
import { useAuthorizedPreviews } from '../../../hooks/useAuthorizedPreviews';

/** Evidence Photo: JPG/PNG only, max 3 MB — validated here and again by the backend. */
const EVIDENCE_MAX_BYTES = 3 * 1024 * 1024;

interface EvidencePhoto {
  id: string;
  original_filename: string | null;
  mime_type: string | null;
  size: number | null;
}

type UsedPartItem = WorkOrderPartReturnItem & { evidence_photos?: EvidencePhoto[] };

const STATUS_FILTERS = ['', 'PENDING_RETURN', 'PENDING_INSPECTION', 'INSPECTED', 'PENDING_APPROVAL', 'REJECTED', 'FINALIZED'];
const DISPOSITIONS = ['REPAIR', 'REUSE', 'QUARANTINE', 'SCRAP', 'SELL_ELIGIBLE'] as const;

const inputStyle: React.CSSProperties = { padding: '6px 8px', fontSize: 12, border: '1px solid #d1d5db', borderRadius: 4 };

export function UsedPartDispositionPage() {
  const { hasPermission } = useAuth();
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { data, meta, loading, error: listError, reload } = useApiList<UsedPartItem>(
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

  async function submit(id: string, action: 'receive' | 'inspect' | 'propose-disposition' | 'decide' | 'complete-repair', body: Record<string, unknown>) {
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
          onInspect={(qty, cond, notes) => submit(r.id, 'inspect', { accepted_quantity: qty, condition: cond, notes: notes || undefined })}
          onEvidenceChanged={reload}
          onPropose={(disposition, reason) => submit(r.id, 'propose-disposition', { disposition, reason: reason || undefined })}
          onDecide={(decision, note) => submit(r.id, 'decide', { decision, note: note || undefined })}
          onCompleteRepair={(notes) => submit(r.id, 'complete-repair', { notes: notes || undefined })}
        />
      ))}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}

function RowCard({
  item, busy, canInspect, canDispose, canApprove, warehouses, onReceive, onInspect, onPropose, onDecide, onEvidenceChanged, onCompleteRepair,
}: {
  item: UsedPartItem;
  busy: boolean;
  canInspect: boolean;
  canDispose: boolean;
  canApprove: boolean;
  warehouses: { id: string; name: string }[];
  onReceive: (warehouseId: string) => void;
  onInspect: (qty: string, condition: string, notes: string) => void;
  onEvidenceChanged: () => void;
  onPropose: (disposition: string, reason: string) => void;
  onDecide: (decision: 'APPROVE' | 'REJECT', note: string) => void;
  onCompleteRepair: (notes: string) => void;
}) {
  const [acceptedQty, setAcceptedQty] = useState(item.quantity);
  const [inspectCondition, setInspectCondition] = useState<'USED_GOOD' | 'USED_FAULTY'>(item.condition === 'USED_FAULTY' ? 'USED_FAULTY' : 'USED_GOOD');
  const [notes, setNotes] = useState('');
  const [disposition, setDisposition] = useState<string>(DISPOSITIONS[0]);
  const [reason, setReason] = useState('');
  const [decideNote, setDecideNote] = useState('');
  const [receiveWarehouseId, setReceiveWarehouseId] = useState('');
  const [repairNotes, setRepairNotes] = useState('');
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
      <EvidencePhotos item={item} editable={item.disposition_status === 'PENDING_INSPECTION' && canInspect} onChanged={onEvidenceChanged} />
      {item.inspection_evidence && (
        <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 6 }}>
          Inspection evidence (link):{' '}
          <a href={item.inspection_evidence} target="_blank" rel="noreferrer">
            {item.inspection_evidence}
          </a>
        </div>
      )}

      {item.disposition_status === 'PENDING_INSPECTION' && canInspect && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
          <NumericInput integer={!item.product?.uom?.allows_fractional_quantity} placeholder="Accepted qty" value={acceptedQty} onChange={(e) => setAcceptedQty(e.target.value)} style={{ ...inputStyle, width: 100 }} />
          <select value={inspectCondition} onChange={(e) => setInspectCondition(e.target.value as 'USED_GOOD' | 'USED_FAULTY')} style={{ ...inputStyle, width: 140 }}>
            <option value="USED_GOOD">Used — Good</option>
            <option value="USED_FAULTY">Used — Faulty</option>
          </select>
          <input placeholder="Inspection notes" value={notes} onChange={(e) => setNotes(e.target.value)} style={{ ...inputStyle, width: 200 }} />
          <button className="btn-primary" disabled={busy || !(Number(acceptedQty) > 0)} onClick={() => onInspect(acceptedQty, inspectCondition, notes)}>
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

      {item.repair_completed_at && (
        <div style={{ fontSize: 12, color: '#374151', marginBottom: 6 }}>
          Repair completed {new Date(item.repair_completed_at).toLocaleString()}
          {item.repair_notes && ` — ${item.repair_notes}`}
        </div>
      )}
      {item.disposition_status === 'FINALIZED' && (
        <span style={{ fontSize: 12, color: '#065f46' }}>
          Finalized as {item.disposition}
          {item.disposition === 'REUSE' && ' — restocked to available inventory.'}
          {item.disposition === 'REPAIR' && !item.repair_completed_at && ' — repair pending (not available).'}
          {item.disposition && !['REUSE', 'REPAIR'].includes(item.disposition) && ' — no inventory movement (was never in available stock).'}
        </span>
      )}
      {item.disposition_status === 'FINALIZED' && item.disposition === 'REPAIR' && !item.repair_completed_at && canInspect && (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center', marginTop: 6 }}>
          <input placeholder="Repair notes (optional)" value={repairNotes} onChange={(e) => setRepairNotes(e.target.value)} style={{ ...inputStyle, width: 240 }} />
          <button className="btn-primary" disabled={busy} onClick={() => onCompleteRepair(repairNotes)} title="The repaired part goes back to Inspected for a new disposition (e.g. Reuse), which needs approval.">
            Complete Repair
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Evidence Photo: an explicit Upload button opens the OS file picker (JPG/PNG, max 3 MB). Photos
 * are private files shown through the authorized API; they can be added or removed only while the
 * item awaits inspection.
 */
function EvidencePhotos({ item, editable, onChanged }: { item: UsedPartItem; editable: boolean; onChanged: () => void }) {
  const photos = item.evidence_photos ?? [];
  const previews = useAuthorizedPreviews(
    (id) => `/app/used-part-returns/${item.id}/evidence/${id}`,
    photos.map((p) => p.id),
  );
  if (!editable && photos.length === 0) return null;

  return (
    <div style={{ marginBottom: 8 }}>
      <div style={{ fontSize: 12, fontWeight: 600, color: '#374151', marginBottom: 4 }}>Evidence Photo</div>
      <ImageUploadField
        images={photos.map((p) => ({ id: p.id, previewUrl: previews[p.id] ?? '', name: p.original_filename ?? undefined }))}
        onUpload={async (file) => {
          const form = new FormData();
          form.append('file', file);
          await apiClient.post(`/app/used-part-returns/${item.id}/evidence`, form);
          onChanged();
        }}
        onRemove={
          editable
            ? async (id) => {
                await apiClient.delete(`/app/used-part-returns/${item.id}/evidence/${id}`);
                onChanged();
              }
            : undefined
        }
        disabled={!editable}
        maxSizeBytes={EVIDENCE_MAX_BYTES}
        uploadLabel="Upload"
        label="JPG or PNG, max 3 MB"
      />
    </div>
  );
}
