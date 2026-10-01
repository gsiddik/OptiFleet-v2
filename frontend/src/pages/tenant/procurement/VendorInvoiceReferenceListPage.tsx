import { useState } from 'react';
import { apiClient } from '../../../api/client';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { VendorInvoiceReferenceItem } from '../../../types';
import { formatMoney } from '../../../utils/money';

export function VendorInvoiceReferenceListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [busy, setBusy] = useState(false);
  const { data, loading, error } = useApiList<VendorInvoiceReferenceItem>('/app/vendor-invoice-references', {}, reloadKey);

  async function updateStatus(id: string, status: string) {
    setBusy(true);
    try {
      await apiClient.post(`/app/vendor-invoice-references/${id}/status`, { status });
      setReloadKey((k) => k + 1);
    } finally {
      setBusy(false);
    }
  }

  const columns: Column<VendorInvoiceReferenceItem>[] = [
    { key: 'number', header: 'Invoice #', render: (v) => v.vendor_invoice_number },
    { key: 'partner', header: 'Vendor', render: (v) => v.partner?.name ?? v.partner_id },
    { key: 'po', header: 'PO', render: (v) => v.purchase_order?.po_number ?? '—' },
    { key: 'date', header: 'Date', render: (v) => v.vendor_invoice_date ?? '—' },
    { key: 'amount', header: 'Amount', render: (v) => formatMoney(v.amount) },
    { key: 'status', header: 'Status', render: (v) => <StatusBadge status={v.status} /> },
    {
      key: 'actions', header: '', render: (v) => hasPermission('goods_receipt.create') && v.status === 'RECEIVED' && (
        <div style={{ display: 'flex', gap: 6 }}>
          <button className="btn-link" disabled={busy} onClick={() => updateStatus(v.id, 'VERIFIED')}>
            Verify
          </button>
          <button className="btn-link" disabled={busy} onClick={() => updateStatus(v.id, 'DISPUTED')}>
            Dispute
          </button>
        </div>
      ),
    },
  ];

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Vendor Invoice References</h1>
      <p style={{ fontSize: 12, color: '#9ca3af', marginTop: -10 }}>
        Recorded when goods are received (Purchase Order → Post Goods Receipt). Procurement traceability only — separate from tenant SaaS billing invoices under Account &gt; Invoices.
      </p>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vendor invoice references found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

    </div>
  );
}
