import { Link } from 'react-router-dom';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import type { VendorQuotationItem } from '../../../types';
import { formatMoney } from '../../../utils/money';

export function VendorQuotationListPage() {
  const { data, loading, error } = useApiList<VendorQuotationItem>('/app/quotations', {}, 0);

  const columns: Column<VendorQuotationItem>[] = [
    { key: 'rfq', header: 'RFQ', render: (q) => <Link to={`/app/rfqs/${q.rfq_id}`}>{q.rfq_id}</Link> },
    { key: 'partner', header: 'Vendor', render: (q) => q.partner?.name ?? q.partner_id },
    { key: 'total', header: 'Total', render: (q) => formatMoney(q.total) },
    { key: 'lead_time', header: 'Lead Time (days)', render: (q) => q.lead_time_days ?? '—' },
    { key: 'status', header: 'Status', render: (q) => <StatusBadge status={q.status} /> },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vendor Quotations</h1>
      <Toolbar />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No quotations found. Submit one from an RFQ." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}
    </div>
  );
}
