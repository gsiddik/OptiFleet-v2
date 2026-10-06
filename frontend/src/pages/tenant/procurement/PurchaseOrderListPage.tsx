import { useState } from 'react';
import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { PurchaseOrderItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { statusLabel } from '../../../i18n/statusRegistry';
import { t } from '../../../i18n/i18n';

const STATUSES = ['', 'DRAFT', 'SUBMITTED', 'APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED', 'REJECTED', 'CANCELLED'];

export function PurchaseOrderListPage() {
  const [status, setStatus] = useState('');
  const { data, loading, error } = useApiList<PurchaseOrderItem>('/app/purchase-orders', { status: status || undefined }, 0);

  const columns: Column<PurchaseOrderItem>[] = [
    { key: 'number', header: t('procurement.fields.poNumber'), render: (p) => <Link to={`/app/purchase-orders/${p.id}`}>{p.po_number}</Link> },
    { key: 'partner', header: t('common.fields.vendor'), render: (p) => p.partner?.name ?? p.partner_id },
    { key: 'warehouse', header: t('configuration.labels.deliveryWarehouse'), render: (p) => p.delivery_warehouse?.name ?? p.delivery_warehouse_id },
    { key: 'total', header: t('common.fields.total'), render: (p) => formatMoney(p.total) },
    { key: 'status', header: t('common.fields.status'), render: (p) => <StatusBadge status={p.status} domain="document" /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('procurement.titles.purchaseOrders')}</h1>
      <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap' }}>
        {STATUSES.map((s) => (
          <button key={s} onClick={() => setStatus(s)} className={status === s ? 'btn-primary' : 'btn-secondary'} style={{ padding: '4px 10px', fontSize: 12 }}>
            {s ? statusLabel(s, 'document') : t('common.actions.all')}
          </button>
        ))}
      </div>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('procurement.empty.noPurchaseOrdersFoundCreateOne')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
