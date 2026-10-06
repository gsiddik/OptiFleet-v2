import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { GoodsReceiptItem } from '../../../types';
import { formatDateTime } from '../../../utils/date';
import { t } from '../../../i18n/i18n';

export function GoodsReceiptListPage() {
  const { data, loading, error } = useApiList<GoodsReceiptItem>('/app/goods-receipts', {}, 0);

  const columns: Column<GoodsReceiptItem>[] = [
    { key: 'number', header: t('procurement.fields.grNumber'), render: (g) => g.gr_number },
    { key: 'po', header: t('procurement.fields.purchaseOrder'), render: (g) => <Link to={`/app/purchase-orders/${g.purchase_order_id}`}>{g.purchase_order?.po_number ?? g.purchase_order_id}</Link> },
    { key: 'warehouse', header: t('common.fields.warehouse'), render: (g) => g.warehouse?.name ?? g.warehouse_id },
    { key: 'partner', header: t('common.fields.vendor'), render: (g) => g.partner?.name ?? g.partner_id },
    { key: 'received_at', header: t('procurement.fields.receivedAt'), render: (g) => (g.received_at ? formatDateTime(g.received_at) : '—') },
    { key: 'status', header: t('common.fields.status'), render: (g) => <StatusBadge status={g.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('procurement.titles.goodsReceipt')}</h1>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('procurement.empty.noGoodsReceiptsFoundPostOne')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
