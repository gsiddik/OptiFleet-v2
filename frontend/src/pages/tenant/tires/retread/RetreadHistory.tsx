import { useEffect, useState } from "react";
import { apiClient } from "../../../../api/client";
import { StatusBadge } from "../../../../components/StatusBadge";
import { openProtectedFile } from "../../../../utils/protectedFile";
import { formatDate } from "../../../../utils/date";
import { formatMoney } from "../../../../utils/money";
import type { TireCycle } from "./retreadTypes";

/**
 * Retread History of a tire (retread and repair cycles — repair is a kind of retread): vendor,
 * estimated price, photos, notes, open / receive dates, Tire Inspection result and final status.
 * Renders nothing when the tire has no cycle. Shared by every serial detail.
 */
export function RetreadHistory({ tireId }: { tireId: string }) {
  const [cycles, setCycles] = useState<TireCycle[] | null>(null);

  useEffect(() => {
    apiClient
      .get(`/app/tires/${tireId}/cycle-history`)
      .then((res) => setCycles(res.data.data))
      .catch(() => setCycles([]));
  }, [tireId]);

  if (!cycles || cycles.length === 0) return null;

  return (
    <section className="card" data-retread-history style={{ marginBottom: 16 }}>
      <h3 style={{ marginTop: 0, fontSize: 15 }}>Retread History</h3>
      <div style={{ overflowX: "auto" }}>
        <table
          style={{
            width: "100%",
            borderCollapse: "collapse",
            fontSize: 13,
            minWidth: 820,
          }}
        >
          <thead>
            <tr
              style={{ textAlign: "left", borderBottom: "1px solid #e5e7eb" }}
            >
              {[
                "Cycle",
                "Vendor",
                "Estimated Price",
                "Open Cycle Date",
                "Receive Date",
                "Inspection Result",
                "Final Status",
                "Photos",
                "Notes",
              ].map((h) => (
                <th key={h} style={{ padding: 6 }}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {cycles.map((c) => (
              <tr
                key={c.id}
                data-cycle={c.cycle_number}
                style={{
                  borderBottom: "1px solid #f3f4f6",
                  verticalAlign: "top",
                }}
              >
                <td style={{ padding: 6 }}>
                  {c.kind === "REPAIR" ? "Repair" : "Retread"} #{c.cycle_number}
                  <div style={{ fontSize: 11, color: "#6b7280" }}>
                    {c.state.replace("_", " ")}
                  </div>
                </td>
                <td style={{ padding: 6 }}>{c.vendor?.name ?? "—"}</td>
                <td style={{ padding: 6 }}>
                  {c.estimated_price != null
                    ? formatMoney(c.estimated_price)
                    : "—"}
                </td>
                <td style={{ padding: 6 }}>{formatDate(c.sent_at)}</td>
                <td style={{ padding: 6 }}>{formatDate(c.received_at)}</td>
                <td style={{ padding: 6 }}>{c.inspection_result ?? "—"}</td>
                <td style={{ padding: 6 }}>
                  {c.final_status ? (
                    <StatusBadge status={c.final_status} />
                  ) : (
                    "—"
                  )}
                </td>
                <td style={{ padding: 6 }}>
                  {c.photos.length === 0
                    ? "—"
                    : c.photos.map((p, i) => (
                        <div key={p.id}>
                          <button
                            type="button"
                            className="btn-link"
                            onClick={() =>
                              openProtectedFile(
                                `/app/tire-cycles/${c.kind.toLowerCase()}/${c.id}/photos/${p.id}`,
                              )
                            }
                          >
                            Photo {i + 1}
                          </button>
                        </div>
                      ))}
                </td>
                <td style={{ padding: 6, maxWidth: 220 }}>{c.notes ?? "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
