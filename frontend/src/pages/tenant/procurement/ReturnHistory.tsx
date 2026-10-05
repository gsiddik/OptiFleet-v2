import type { PurchaseReturnItem } from "../../../types";
import { StatusBadge } from "../../../components/StatusBadge";
import { formatDateTime } from "../../../utils/date";
import { formatMoney } from "../../../utils/money";
import { formatQty } from "../../../utils/quantity";
import { RETURN_OPTION_LABEL } from "./purchaseReturnLabels";

const EVENT_LABEL: Record<string, string> = {
  CREATED: "Return Order created",
  PRINTED: "Return Order printed",
  VENDOR_ACCEPTED: "Refund accepted by vendor",
  VENDOR_REJECTED: "Refund rejected by vendor",
  REDELIVERY_EXPECTED: "Vendor redelivers the goods",
  REDELIVERY_RECEIVED: "Redelivery received",
};

/** Return History of a Purchase Order — rendered only when it has at least one Return Order. */
export function ReturnHistory({
  returns,
  onPrint,
}: {
  returns: PurchaseReturnItem[];
  onPrint: (r: PurchaseReturnItem) => void;
}) {
  return (
    <div className="card" data-return-history style={{ marginTop: 16 }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Return History</h3>
      <div style={{ overflowX: "auto" }}>
        <table
          style={{
            width: "100%",
            borderCollapse: "collapse",
            fontSize: 13,
            minWidth: 720,
          }}
        >
          <thead>
            <tr
              style={{ textAlign: "left", borderBottom: "1px solid #e5e7eb" }}
            >
              <th style={{ padding: 6 }}>Return Order #</th>
              <th style={{ padding: 6 }}>Returned Date</th>
              <th style={{ padding: 6 }}>Returned Quantity</th>
              <th style={{ padding: 6 }}>Return Option</th>
              <th style={{ padding: 6 }}>Refunded Amount</th>
              <th style={{ padding: 6 }}>Status</th>
              <th style={{ padding: 6 }} />
            </tr>
          </thead>
          <tbody>
            {returns.map((r) => (
              <tr
                key={r.id}
                data-return-row={r.return_number}
                style={{
                  borderBottom: "1px solid #f3f4f6",
                  verticalAlign: "top",
                }}
              >
                <td style={{ padding: 6, fontWeight: 600 }}>
                  {r.return_number}
                </td>
                <td style={{ padding: 6 }}>{formatDateTime(r.returned_at)}</td>
                <td style={{ padding: 6 }}>
                  {r.items.map((i) => (
                    <div key={i.id}>
                      {i.product?.name ?? i.product_id} ×{" "}
                      {formatQty(i.quantity)}
                    </div>
                  ))}
                </td>
                <td style={{ padding: 6 }}>
                  {RETURN_OPTION_LABEL[r.return_option]}
                  {r.vendor_decision === "REJECTED" && (
                    <div style={{ fontSize: 12, color: "#b45309" }}>
                      Refund rejected → Redelivery
                    </div>
                  )}
                </td>
                <td style={{ padding: 6 }} data-refunded-amount>
                  {r.status === "REFUND_ACCEPTED" && r.refunded_amount != null
                    ? formatMoney(r.refunded_amount)
                    : "—"}
                </td>
                <td style={{ padding: 6 }}>
                  <StatusBadge status={r.status} />
                  <ul
                    style={{
                      margin: "4px 0 0",
                      paddingLeft: 16,
                      color: "#6b7280",
                      fontSize: 12,
                    }}
                  >
                    {r.events.map((e) => (
                      <li key={e.id}>
                        {EVENT_LABEL[e.event] ?? e.event} ·{" "}
                        {formatDateTime(e.occurred_at)}
                        {e.performer?.name ? ` · ${e.performer.name}` : ""}
                      </li>
                    ))}
                  </ul>
                </td>
                <td style={{ padding: 6 }}>
                  <button className="btn-link" onClick={() => onPrint(r)}>
                    Print
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
