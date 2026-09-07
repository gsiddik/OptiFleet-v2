import { useEffect, useState } from 'react';
import { apiClient, extractApiError, type ApiErrorShape } from '../../../api/client';
import { FormField, inputStyle } from '../../../components/FormField';
import { Modal } from '../../../components/Modal';
import { StatusBadge } from '../../../components/StatusBadge';
import { Table, type Column } from '../../../components/Table';
import { Toolbar } from '../../../components/Toolbar';
import { EmptyState, ErrorState, LoadingState } from '../../../components/States';
import { useApiList } from '../../../hooks/useApiList';
import { useAuth } from '../../../auth/AuthContext';
import type { PartnerItem, VendorInvoiceReferenceItem } from '../../../types';

export function VendorInvoiceReferenceListPage() {
  const { hasPermission } = useAuth();
  const [reloadKey, setReloadKey] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
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
    { key: 'amount', header: 'Amount', render: (v) => v.amount ?? '—' },
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
        Procurement traceability only — separate from tenant SaaS billing invoices under Account &gt; Invoices.
      </p>
      <Toolbar
        actions={
          hasPermission('goods_receipt.create') ? (
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              + Record Invoice Reference
            </button>
          ) : null
        }
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && <EmptyState label="No vendor invoice references found." />}
      {!error && !loading && data.length > 0 && <Table columns={columns} rows={data} />}

      <CreateModal open={showCreate} onClose={() => setShowCreate(false)} onCreated={() => setReloadKey((k) => k + 1)} />
    </div>
  );
}

function CreateModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [partnerId, setPartnerId] = useState('');
  const [invoiceNumber, setInvoiceNumber] = useState('');
  const [invoiceDate, setInvoiceDate] = useState('');
  const [amount, setAmount] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    apiClient.get('/app/partners', { params: { per_page: 100 } }).then((res) => setPartners(res.data.data)).catch(() => setPartners([]));
  }, [open]);

  async function submit() {
    setSubmitting(true);
    setErrors({});
    try {
      await apiClient.post('/app/vendor-invoice-references', {
        partner_id: partnerId, vendor_invoice_number: invoiceNumber, vendor_invoice_date: invoiceDate, amount,
      });
      setInvoiceNumber('');
      setAmount('');
      onCreated();
      onClose();
    } catch (err) {
      const apiError: ApiErrorShape = extractApiError(err);
      setErrors(apiError.errors ?? {});
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} title="Record Vendor Invoice Reference" onClose={onClose}>
      <FormField label="Vendor" errors={errors.partner_id}>
        <select value={partnerId} onChange={(e) => setPartnerId(e.target.value)} style={inputStyle}>
          <option value="">Select…</option>
          {partners.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Invoice Number" errors={errors.vendor_invoice_number}>
        <input value={invoiceNumber} onChange={(e) => setInvoiceNumber(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Invoice Date" errors={errors.vendor_invoice_date}>
        <input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} style={inputStyle} />
      </FormField>
      <FormField label="Amount" errors={errors.amount}>
        <input type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} style={inputStyle} />
      </FormField>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 20 }}>
        <button className="btn-secondary" onClick={onClose}>
          Cancel
        </button>
        <button className="btn-primary" disabled={submitting || !partnerId || !invoiceNumber || !invoiceDate || !amount} onClick={submit}>
          Save
        </button>
      </div>
    </Modal>
  );
}
