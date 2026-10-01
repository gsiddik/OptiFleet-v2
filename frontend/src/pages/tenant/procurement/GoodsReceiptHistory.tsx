import type { GoodsReceiptItem } from '../../../types';
import { formatDateTime } from '../../../utils/date';
import { downloadProtectedFile, openProtectedFile } from '../../../utils/protectedFile';
import { formatQty } from '../../../utils/quantity';

/**
 * Receipt history of a Purchase Order: one row per Goods Receipt (oldest first), never
 * overwritten by later receipts. Receipt Date is the receipt's own `received_at`.
 */
export function GoodsReceiptHistory({ receipts, receivedComplete, canViewDocuments }: { receipts: GoodsReceiptItem[]; receivedComplete: boolean; canViewDocuments: boolean }) {
  const last = receipts[receipts.length - 1];
  const cell = { padding: '8px 10px', borderBottom: '1px solid #f3f4f6', fontSize: 13, verticalAlign: 'top' as const };

  return (
    <div className="card" style={{ marginTop: 16 }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Receipt History</h3>
      {receivedComplete && last && (
        <p style={{ fontSize: 13, marginTop: 0 }}>
          <strong>Goods received:</strong> {formatDateTime(last.received_at)}
          {receipts.length > 1 && <span style={{ color: '#6b7280' }}> (completed in {receipts.length} receipts)</span>}
        </p>
      )}
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }} aria-label="Receipt history">
          <thead>
            <tr style={{ textAlign: 'left', fontSize: 12, color: '#6b7280' }}>
              {['GR#', 'Receipt Date', 'Received Quantity', 'Invoice Number', 'Invoice Document', 'Received By'].map((h) => (
                <th key={h} style={{ ...cell, fontWeight: 600 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {receipts.map((gr) => {
              const invoice = gr.vendor_invoice_reference;
              const docPath = invoice ? `/app/vendor-invoice-references/${invoice.id}/download` : null;
              return (
                <tr key={gr.id}>
                  <td style={cell}>{gr.gr_number}</td>
                  <td style={cell}>{formatDateTime(gr.received_at)}</td>
                  <td style={cell}>
                    {(gr.items ?? []).map((line) => (
                      <div key={line.id}>
                        {formatQty(line.quantity_accepted)} × {line.product?.name ?? line.product_id}
                      </div>
                    ))}
                  </td>
                  <td style={cell}>{invoice?.vendor_invoice_number ?? '—'}</td>
                  <td style={cell}>
                    {invoice?.has_document && docPath && canViewDocuments ? (
                      <span style={{ display: 'inline-flex', gap: 10 }}>
                        <button type="button" className="btn-link" onClick={() => openProtectedFile(docPath)}>
                          View
                        </button>
                        <button type="button" className="btn-link" onClick={() => downloadProtectedFile(docPath, invoice.attachment_original_name ?? `${invoice.vendor_invoice_number}.pdf`)}>
                          Download
                        </button>
                      </span>
                    ) : invoice?.has_document ? (
                      invoice.attachment_original_name ?? 'Document uploaded'
                    ) : invoice ? (
                      'No document uploaded'
                    ) : (
                      '—'
                    )}
                  </td>
                  <td style={cell}>{gr.receiver?.name ?? '—'}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
