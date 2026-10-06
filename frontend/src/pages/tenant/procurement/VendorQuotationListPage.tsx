import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Pagination } from '../../../components/Pagination';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { VendorQuotationItem } from '../../../types';
import { formatMoney } from '../../../utils/money';
import { useAuth } from '../../../auth/AuthContext';
import { t } from '../../../i18n/i18n';

export function VendorQuotationListPage() {
  const { hasPermission } = useAuth();
  const [page, setPage] = useState(1);
  const { data, meta, loading, error } = useApiList<VendorQuotationItem>('/app/quotations', { page }, 0);

  const columns: Column<VendorQuotationItem>[] = [
    { key: 'rfq', header: t('procurement.fields.rfqNumber2'), render: (q) => <Link to={`/app/rfqs/${q.rfq_id}`}>{q.rfq?.rfq_number ?? '—'}</Link> },
    { key: 'partner', header: t('common.fields.vendor'), render: (q) => q.partner?.name ?? q.partner_id },
    { key: 'total', header: t('common.fields.total'), render: (q) => formatMoney(q.total) },
    { key: 'lead_time', header: t('procurement.fields.leadTimeDays'), render: (q) => q.lead_time_days ?? '—' },
    { key: 'status', header: t('common.fields.status'), render: (q) => <StatusBadge status={q.status} /> },
    {
      key: 'purchase_order',
      header: t('configuration.labels.purchaseOrder'),
      render: (q) =>
        q.purchase_order ? (
          <Link to={`/app/purchase-orders/${q.purchase_order.id}`}>{q.purchase_order.po_number}</Link>
        ) : q.can_create_purchase_order && hasPermission('purchase_order.create') ? (
          <Link to={`/app/quotations/${q.id}/create-po`}>{t('procurement.actions.createPo')}</Link>
        ) : (
          '—'
        ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('procurement.titles.vendorQuotations')}</h1>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label={t('procurement.empty.noQuotationsFoundSubmitOneRfq')} />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
