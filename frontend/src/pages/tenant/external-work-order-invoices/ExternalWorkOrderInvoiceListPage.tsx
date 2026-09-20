import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { ExternalWorkOrderInvoiceItem } from '../../../types';

const STATUSES = ['', 'NEW_EXTERNAL_WO', 'DELIVERED', 'IN_PROGRESS', 'CANCELLED', 'BILLED', 'PAID'];

const STATUS_LABELS: Record<string, string> = {
  NEW_EXTERNAL_WO: 'New External WO',
  DELIVERED: 'Delivered',
  IN_PROGRESS: 'In Progress',
  CANCELLED: 'Cancelled',
  BILLED: 'Billed',
  PAID: 'Paid',
};

const WAL_LABELS: Record<string, string> = {
  NOT_GENERATED: 'Not Generated',
  GENERATED: 'Generated',
  ACKNOWLEDGED: 'Acknowledged',
};

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice": the tenant-facing "Workshop Invoice" list — Work Orders being
 * carried out by an External Workshop. Deliberately its own page/route,
 * separate from WorkshopInvoiceListPage (the pre-existing, unrelated R1
 * feature). Generate/View Authorization, Deliver, Acknowledge, Complete,
 * and Settlement actions are added on this same page in later phases;
 * Cancel already works today by reusing the existing Work Order detail
 * page's Cancel action (this list intentionally does not duplicate that
 * logic).
 */
export function ExternalWorkOrderInvoiceListPage() {
  const [status, setStatus] = useState('');
  const { data, loading, error } = useApiList<ExternalWorkOrderInvoiceItem>('/app/external-work-order-invoices', { status: status || undefined });

  const columns: Column<ExternalWorkOrderInvoiceItem>[] = [
    {
      key: 'wo_number',
      header: 'WO Number',
      render: (r) => <Link to={`/app/work-orders/${r.work_order_id}`}>{r.work_order?.wo_number ?? r.work_order_id}</Link>,
    },
    { key: 'vehicle', header: 'Vehicle', render: (r) => r.work_order?.vehicle?.registration_number ?? '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={STATUS_LABELS[r.status] ?? r.status} /> },
    { key: 'work_authorization', header: 'Work Authorization', render: (r) => WAL_LABELS[r.work_authorization_status] ?? r.work_authorization_status },
    { key: 'workshop', header: 'Workshop', render: (r) => r.wal_workshop_name ?? r.work_order?.workshop?.name ?? '—' },
    {
      key: 'action',
      header: 'Action',
      render: (r) => (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {r.allowed_actions.includes('cancel') && (
            <Link to={`/app/work-orders/${r.work_order_id}`} className="btn-secondary" style={{ textDecoration: 'none', display: 'inline-block' }}>
              Cancel
            </Link>
          )}
          {(r.allowed_actions.includes('generate_authorization') ||
            r.allowed_actions.includes('view_authorization') ||
            r.allowed_actions.includes('deliver') ||
            r.allowed_actions.includes('acknowledge') ||
            r.allowed_actions.includes('complete') ||
            r.allowed_actions.includes('settle')) && (
            <Link to={`/app/work-orders/${r.work_order_id}`} className="btn-secondary" style={{ textDecoration: 'none', display: 'inline-block' }}>
              Open
            </Link>
          )}
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Workshop Invoice</h1>
      <p style={{ fontSize: 13, color: '#6b7280', marginTop: -8, marginBottom: 16 }}>
        Work Orders being carried out by an External Workshop.
      </p>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '6px 12px', fontSize: 13 }}>
            {s ? STATUS_LABELS[s] : 'All'}
          </button>
        ))}
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No External Work Order Invoices found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
