import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { ConfirmDialog } from '../../../components/ConfirmDialog';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import { formatQty } from '../../../utils/quantity';
import type { WorkOrderPartReturnItem } from '../../../types';
import { NumericInput } from '../../../components/NumericInput';
import { formatDateTime } from '../../../utils/date';
import { message } from '../../../i18n/messages';
import { labelText, t, translatedRecord } from '../../../i18n/i18n';

const STATUSES = [
  { value: 'PENDING_PROCESSING', label: 'Pending Processing', labelKey: 'inventory.status.pendingProcessing' },
  { value: 'RESTOCKED', label: 'Accepted to Stock', labelKey: 'inventory.status.acceptedToStock' },
  { value: 'QUARANTINED', label: 'Quarantined', labelKey: 'inventory.status.quarantined' },
  { value: 'WARRANTY_CLAIM', label: 'Warranty Claim', labelKey: 'inventory.fields.warrantyClaim' },
  { value: 'REPAIR', label: 'Repair', labelKey: 'inventory.fields.repair' },
  { value: 'SCRAP', label: 'Scrap', labelKey: 'inventory.fields.scrap' },
  { value: '', label: 'All', labelKey: 'inventory.status.all' },
];

/** Follow-up for a faulty (quarantined) return. None of them puts the part back into available stock. */
const FAULTY_DISPOSITIONS = [
  { value: 'WARRANTY_CLAIM', label: 'Warranty Claim', labelKey: 'inventory.fields.warrantyClaim' },
  { value: 'REPAIR', label: 'Repair', labelKey: 'inventory.fields.repair' },
  { value: 'SCRAP', label: 'Scrap', labelKey: 'inventory.fields.scrap' },
] as const;
type FaultyDisposition = (typeof FAULTY_DISPOSITIONS)[number]['value'];
const DISPOSITION_LABEL: Record<string, string> = Object.fromEntries(FAULTY_DISPOSITIONS.map((d) => [d.value, d.label]));

const CONDITION_LABEL: Record<string, string> = translatedRecord({ UNUSED_NEW: 'New Good', UNUSED_FAULTY: 'New Faulty' }, { UNUSED_NEW: 'inventory.condition.unusedNew', UNUSED_FAULTY: 'inventory.condition.unusedFaulty' });

/**
 * Return: new parts issued to a Work Order and returned unused (Issuance & Return). Each one
 * is inspected in Returned Parts Processing before it can go back to available stock.
 * Old components removed from a vehicle are handled in Used Sparepart Processing instead.
 */
export function ReturnListPage() {
  const [status, setStatus] = useState('PENDING_PROCESSING');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [openReturn, setOpenReturn] = useState<WorkOrderPartReturnItem | null>(null);
  const { data, meta, loading, error } = useApiList<WorkOrderPartReturnItem>(
    '/app/part-returns',
    { status: status || undefined, search: search || undefined, page },
    reloadKey,
  );

  const columns: Column<WorkOrderPartReturnItem>[] = [
    {
      key: 'return_number',
      header: t('inventory.fields.returnNumber'),
      render: (r) => (
        <button className="btn-link" onClick={() => setOpenReturn(r)} style={{ fontWeight: 600 }}>
          {r.return_number}
        </button>
      ),
    },
    {
      key: 'work_order',
      header: t('inventory.fields.workOrderNumber'),
      render: (r) => (r.work_order ? <Link to={`/app/work-orders/${r.work_order.id}`}>{r.work_order.wo_number}</Link> : '—'),
    },
    { key: 'product', header: t('inventory.fields.returnedProduct'), render: (r) => r.product?.name ?? r.product_id },
    { key: 'quantity', header: t('inventory.fields.returnedQuantity'), render: (r) => formatQty(r.quantity) },
    { key: 'status', header: t('common.fields.status'), render: (r) => <StatusBadge status={r.disposition_status} /> },
    { key: 'created_at', header: t('inventory.fields.returnedAt'), render: (r) => formatDateTime(r.created_at) },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{t('inventory.titles.stockReturn')}</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: 0, marginBottom: 14 }}>
        {t('inventory.help.newPartsReturnedUnusedWorkOrder')}
      </p>
      <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
        {STATUSES.map((s) => (
          <button
            key={s.value}
            onClick={() => {
              setStatus(s.value);
              setPage(1);
            }}
            className={status === s.value ? 'btn-primary' : 'btn-secondary'}
            style={{ padding: '6px 12px', fontSize: 13 }}
          >
            {labelText(s)}
          </button>
        ))}
        <input
          aria-label={t('inventory.fields.searchReturns')}
          placeholder={t('inventory.search.searchReturnWoProduct')}
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 240, marginLeft: 'auto' }}
        />
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('inventory.empty.noStockReturnsFound')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}

      {openReturn && (
        <ReturnedPartsProcessingModal
          item={openReturn}
          onClose={() => setOpenReturn(null)}
          onProcessed={() => {
            setOpenReturn(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

function ReturnedPartsProcessingModal({ item: listed, onClose, onProcessed }: { item: WorkOrderPartReturnItem; onClose: () => void; onProcessed: () => void }) {
  const { hasPermission } = useAuth();
  // Always act on the current state (someone else may have processed it since the list loaded).
  const [item, setItem] = useState(listed);
  useEffect(() => {
    apiClient
      .get(`/app/part-returns/${listed.id}`)
      .then((res) => setItem(res.data.data))
      .catch(() => undefined);
  }, [listed.id]);
  const pending = item.disposition_status === 'PENDING_PROCESSING';
  const canProcess = pending && hasPermission('part_return.process');
  const canRoute = item.disposition_status === 'QUARANTINED' && hasPermission('part_return.process');
  const [disposition, setDisposition] = useState<FaultyDisposition | ''>('');
  const [routeReason, setRouteReason] = useState('');
  const [confirmingRoute, setConfirmingRoute] = useState(false);
  const [actualCondition, setActualCondition] = useState<'UNUSED_NEW' | 'UNUSED_FAULTY'>(item.condition === 'UNUSED_FAULTY' ? 'UNUSED_FAULTY' : 'UNUSED_NEW');
  const [receivedQty, setReceivedQty] = useState(String(Number(item.quantity)));
  const [notes, setNotes] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const matches = actualCondition === item.condition && Number(receivedQty) === Number(item.quantity);

  async function submit() {
    setConfirming(false);
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/part-returns/${item.id}/process`, { actual_condition: actualCondition, received_quantity: receivedQty, notes: notes || undefined });
      onProcessed();
    } catch (err) {
      const e = extractApiError(err);
      setError(e.errors ? Object.values(e.errors).flat()[0] ?? e.message : e.message);
    } finally {
      setBusy(false);
    }
  }

  async function route() {
    setConfirmingRoute(false);
    setBusy(true);
    setError(null);
    try {
      await apiClient.post(`/app/part-returns/${item.id}/route`, { disposition, reason: routeReason || undefined });
      onProcessed();
    } catch (err) {
      const e = extractApiError(err);
      setError(e.errors ? Object.values(e.errors).flat()[0] ?? e.message : e.message);
    } finally {
      setBusy(false);
    }
  }

  const row = (label: string, value: React.ReactNode) => (
    <div style={{ display: 'contents' }}>
      <div style={{ fontSize: 12, color: '#6b7280' }}>{label}</div>
      <div style={{ fontSize: 13 }}>{value}</div>
    </div>
  );

  return (
    <Modal open title={t('inventory.modals.returnedPartsProcessing')} onClose={onClose} width={620}>
      {error && <ErrorState message={error} />}
      <h4 style={{ margin: '0 0 8px', fontSize: 13 }}>{t('inventory.sections.identification')}</h4>
      <div style={{ display: 'grid', gridTemplateColumns: '160px 1fr', gap: '6px 12px', marginBottom: 16 }}>
        {row(t('inventory.fields.returnNumber'), <strong>{item.return_number}</strong>)}
        {row(t('inventory.fields.workOrderNumber'), item.work_order?.wo_number ?? '—')}
        {row(t('common.fields.product'), item.product?.name ?? item.product_id)}
        {row(t('inventory.fields.returnedQuantity'), formatQty(item.quantity))}
        {row(t('inventory.fields.returnedByAt'), `${item.returner?.name ?? '—'} · ${formatDateTime(item.created_at)}`)}
        {row(t('inventory.fields.reportedCondition'), CONDITION_LABEL[item.condition] ?? item.condition)}
        {row(t('inventory.fields.returnWarehouse'), item.warehouse?.name ?? '—')}
        {item.reason && row(t('common.fields.reason'), item.reason)}
        {row(t('common.fields.status'), <StatusBadge status={item.disposition_status} />)}
      </div>

      <h4 style={{ margin: '0 0 8px', fontSize: 13 }}>{t('inventory.sections.inspection')}</h4>
      {pending ? (
        canProcess ? (
          <>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <FormField label={t('inventory.fields.actualCondition')} required>
                <select value={actualCondition} onChange={(e) => setActualCondition(e.target.value as 'UNUSED_NEW' | 'UNUSED_FAULTY')} style={inputStyle}>
                  <option value="UNUSED_NEW">{t('inventory.fields.newGoodAcceptStock')}</option>
                  <option value="UNUSED_FAULTY">{t('inventory.fields.newFaultyQuarantine')}</option>
                </select>
              </FormField>
              <FormField label={t('inventory.fields.receivedQuantity')} required>
                <NumericInput min="0" step="any" max={Number(item.quantity)} value={receivedQty} onChange={(e) => setReceivedQty(e.target.value)} style={inputStyle} />
              </FormField>
            </div>
            <FormField label={t('common.fields.notes')}>
              <textarea value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} style={{ ...inputStyle, minHeight: 60 }} />
            </FormField>
            <div style={{ fontSize: 12, marginBottom: 12, color: matches ? '#047857' : '#b45309' }}>
              {t('inventory.fields.inspectionResult')}: <strong>{matches ? 'MATCH' : 'MISMATCH'}</strong>
              {' — '}
              {actualCondition === 'UNUSED_NEW'
                ? item.warehouse?.name
                  ? message('inventory.confirm.restockToWarehouse', { quantity: formatQty(receivedQty), warehouseName: item.warehouse.name })
                  : message('inventory.confirm.restockToUnknownWarehouse', { quantity: formatQty(receivedQty) })
                : t('inventory.empty.nothingAddedAvailableStock')}
            </div>
          </>
        ) : (
          <p style={{ fontSize: 13, color: '#6b7280' }}>{t('inventory.help.awaitingInspectionWarehouse')}</p>
        )
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: '160px 1fr', gap: '6px 12px' }}>
          {row(t('inventory.fields.actualCondition'), item.actual_condition ? CONDITION_LABEL[item.actual_condition] : '—')}
          {row(t('inventory.fields.receivedQuantity'), formatQty(item.accepted_quantity))}
          {row(t('inventory.fields.inspectionResult2'), item.inspection_result ?? '—')}
          {row(
            t('tenantComponents.fields.outcome'),
            item.disposition_status === 'RESTOCKED'
              ? t('inventory.help.acceptedAcceptedQuantityBackStock', { accepted_quantity: formatQty(item.accepted_quantity) })
              : item.disposition_status === 'QUARANTINED'
                ? t('inventory.help.quarantinedAwaitingDisposition')
                : DISPOSITION_LABEL[item.disposition_status]
                  ? `Faulty — routed to ${DISPOSITION_LABEL[item.disposition_status]}; not added to available stock`
                  : item.disposition_status,
          )}
          {row(t('inventory.fields.inspectedByAt'), `${item.inspector?.name ?? '—'} · ${item.inspected_at ? formatDateTime(item.inspected_at) : '—'}`)}
          {item.inspection_notes && row(t('common.fields.notes'), item.inspection_notes)}
          {item.routed_at && row(t('inventory.fields.routedByAt'), `${item.router?.name ?? '—'} · ${formatDateTime(item.routed_at)}`)}
          {item.routed_at && item.disposition_reason && row(t('inventory.fields.dispositionReason'), item.disposition_reason)}
        </div>
      )}

      {canRoute && (
        <>
          <h4 style={{ margin: '16px 0 8px', fontSize: 13 }}>{t('inventory.sections.faultyPartDisposition')}</h4>
          <FormField label={t('tenantComponents.fields.disposition')} required>
            <select aria-label={t('tenantComponents.fields.disposition')} value={disposition} onChange={(e) => setDisposition(e.target.value as FaultyDisposition | '')} style={inputStyle}>
              <option value="">{t('inventory.fields.selectDisposition')}</option>
              {FAULTY_DISPOSITIONS.map((d) => (
                <option key={d.value} value={d.value}>
                  {labelText(d)}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label={t('common.fields.reason')}>
            <textarea value={routeReason} onChange={(e) => setRouteReason(e.target.value)} maxLength={2000} style={{ ...inputStyle, minHeight: 50 }} />
          </FormField>
          <p style={{ fontSize: 12, color: '#6b7280', margin: '0 0 4px' }}>{t('inventory.help.partStaysOutAvailableStockWhichever')}</p>
        </>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
        <button className="btn-secondary" onClick={onClose} disabled={busy}>
          {t('common.actions.close')}
        </button>
        {canProcess && (
          <button className="btn-primary" disabled={busy || !(Number(receivedQty) > 0)} onClick={() => setConfirming(true)}>
            {busy ? t('inventory.actions.processing') : t('inventory.actions.completeProcessing')}
          </button>
        )}
        {canRoute && (
          <button className="btn-primary" disabled={busy || !disposition} onClick={() => setConfirmingRoute(true)}>
            {busy ? t('inventory.actions.routing') : t('inventory.actions.routeDisposition')}
          </button>
        )}
      </div>
      <ConfirmDialog
        open={confirming}
        title={t('inventory.confirm.completeReturnedPartsProcessing')}
        message={
          actualCondition === 'UNUSED_NEW'
            ? t('inventory.confirm.acceptReceivedQtyValueBackInto', { receivedQty: formatQty(receivedQty), value: item.product?.name ?? 'part' })
            : t('inventory.confirm.quarantineReceivedQtyValueNotAdded', { receivedQty: formatQty(receivedQty), value: item.product?.name ?? 'part' })
        }
        confirmLabel={t('inventory.confirm.complete')}
        onCancel={() => setConfirming(false)}
        onConfirm={submit}
      />
      <ConfirmDialog
        open={confirmingRoute}
        title={t('inventory.confirm.routeFaultyReturn')}
        message={t('inventory.confirm.routeReturnNumberValueStaysOut', { return_number: item.return_number, value: disposition ? DISPOSITION_LABEL[disposition] : '' })}
        confirmLabel={t('inventory.confirm.route')}
        onCancel={() => setConfirmingRoute(false)}
        onConfirm={route}
      />
    </Modal>
  );
}
