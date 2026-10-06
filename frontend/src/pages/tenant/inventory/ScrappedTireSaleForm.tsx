import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { NumericInput } from "../../../components/NumericInput";
import { ErrorState, LoadingState } from "../../../components/States";
import { StatusBadge } from "../../../components/StatusBadge";
import type { PartnerItem } from "../../../types";
import type { ScrappedTireRow } from "../tires/scrap/ScrappedTiresPanel";
import { t as tt } from '../../../i18n/i18n';

const inputStyle: React.CSSProperties = {
  padding: "6px 8px",
  fontSize: 12,
  border: "1px solid #d1d5db",
  borderRadius: 4,
};
const cell = {
  padding: "6px 10px",
  borderBottom: "1px solid #f3f4f6",
} as const;

/**
 * Sell Sparepart → "Sell scrapped tires": the tires selected on Used Tire Management → Scrap. One
 * Draft sale (Scrap Material, quantity 1) is created per tire, keeping its serial number; the
 * backend accepts only SCRAPPED tires that are not already in an open or approved sale.
 */
export function ScrappedTireSaleForm({
  tireIds,
  onCreated,
  onCancel,
}: {
  tireIds: string[];
  onCreated: () => void;
  onCancel: () => void;
}) {
  const [tires, setTires] = useState<ScrappedTireRow[] | null>(null);
  const [partners, setPartners] = useState<PartnerItem[]>([]);
  const [buyerType, setBuyerType] = useState<"EXTERNAL" | "PARTNER">(
    "EXTERNAL",
  );
  const [partnerId, setPartnerId] = useState("");
  const [buyerName, setBuyerName] = useState("");
  const [unitPrice, setUnitPrice] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const idsKey = tireIds.join(",");

  useEffect(() => {
    apiClient
      .get("/app/tires-scrapped", { params: { ids: idsKey, per_page: 100 } })
      .then((res) => setTires(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
    apiClient
      .get("/app/partners", { params: { status: "ACTIVE", per_page: 100 } })
      .then((res) => setPartners(res.data.data))
      .catch(() => setPartners([]));
  }, [idsKey]);

  const sellable = (tires ?? []).filter((t) => !t.sale_id);
  const missing = tires ? tireIds.length - tires.length : 0;
  const buyerOk =
    buyerType === "PARTNER" ? Boolean(partnerId) : Boolean(buyerName.trim());

  async function submit() {
    setSubmitting(true);
    setError(null);
    try {
      await apiClient.post("/app/sparepart-sales/scrapped-tires", {
        tire_ids: sellable.map((t) => t.id),
        sale_type: "SCRAP_MATERIAL",
        buyer_type: buyerType,
        partner_id: buyerType === "PARTNER" ? partnerId : null,
        buyer_name: buyerType === "EXTERNAL" ? buyerName.trim() : null,
        unit_price: unitPrice,
        notes: notes || null,
      });
      onCreated();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="card" style={{ marginBottom: 10 }} data-scrapped-tire-sale>
      <h3 style={{ marginTop: 0, fontSize: 14 }}>{tt('inventory.sections.sellScrappedTires')}</h3>
      <p style={{ fontSize: 12, color: "#6b7280", marginTop: 0 }}>
        {tt('inventory.help.oneDraftSaleScrapMaterialQuantity')}
      </p>
      {error && <ErrorState message={error} />}
      {!tires && !error && <LoadingState />}
      {tires && (
        <>
          {missing > 0 && (
            <p role="alert" style={{ fontSize: 12, color: "#b91c1c" }}>
              {tt('inventory.help.missingSelectedTireSNoLonger', { count: missing })}
            </p>
          )}
          <div style={{ overflowX: "auto", marginBottom: 10 }}>
            <table
              style={{
                width: "100%",
                borderCollapse: "collapse",
                fontSize: 13,
              }}
            >
              <thead>
                <tr style={{ background: "#f9fafb", textAlign: "left" }}>
                  <th style={cell}>{tt('common.fields.serialNumber')}</th>
                  <th style={cell}>{tt('common.fields.product')}</th>
                  <th style={cell}>{tt('common.fields.status')}</th>
                  <th style={cell}>{tt('common.fields.sale')}</th>
                </tr>
              </thead>
              <tbody>
                {tires.map((t) => (
                  <tr key={t.id} data-sale-tire={t.serial_number}>
                    <td style={cell}>{t.serial_number}</td>
                    <td style={cell}>{t.product_name ?? "—"}</td>
                    <td style={cell}>
                      <StatusBadge status={t.current_status} />
                    </td>
                    <td style={cell}>
                      {t.sale_status ? (
                        <>
                          <StatusBadge status={t.sale_status} /> {tt('inventory.help.alreadyInASale')}
                        </>
                      ) : (
                        "—"
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div
            style={{
              display: "flex",
              gap: 6,
              flexWrap: "wrap",
              alignItems: "center",
            }}
          >
            <select
              aria-label={tt('inventory.fields.buyerType')}
              value={buyerType}
              onChange={(e) =>
                setBuyerType(e.target.value as "EXTERNAL" | "PARTNER")
              }
              style={{ ...inputStyle, width: 120 }}
            >
              <option value="EXTERNAL">{tt('tenantComponents.fields.external')}</option>
              <option value="PARTNER">{tt('common.fields.partner')}</option>
            </select>
            {buyerType === "PARTNER" ? (
              <select
                aria-label={tt('common.fields.partner')}
                value={partnerId}
                onChange={(e) => setPartnerId(e.target.value)}
                style={{ ...inputStyle, width: 200 }}
              >
                <option value="">{tt('inventory.fields.selectPartner')}</option>
                {partners.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            ) : (
              <input
                aria-label={tt('inventory.fields.buyerName')}
                placeholder={tt('inventory.fields.buyerName')}
                value={buyerName}
                onChange={(e) => setBuyerName(e.target.value)}
                style={{ ...inputStyle, width: 180 }}
              />
            )}
            <NumericInput
              aria-label={tt('inventory.fields.unitPricePerTire')}
              step="0.01"
              min="0"
              placeholder={tt('inventory.fields.unitPricePerTire')}
              value={unitPrice}
              onChange={(e) => setUnitPrice(e.target.value)}
              style={{ ...inputStyle, width: 140 }}
            />
            <input
              aria-label={tt('common.fields.notes')}
              placeholder={tt('inventory.placeholders.notesOptional')}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              style={{ ...inputStyle, width: 200 }}
            />
            <button
              className="btn-primary"
              disabled={
                submitting || sellable.length === 0 || !buyerOk || !unitPrice
              }
              onClick={submit}
            >
              {tt('inventory.actions.createSellableCountSaleSDraft', { count: sellable.length })}
            </button>
            <button className="btn-secondary" onClick={onCancel}>
              {tt('common.actions.cancel')}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
