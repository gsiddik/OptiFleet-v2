import { useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { FormField, inputStyle } from "../../../components/FormField";
import { Modal } from "../../../components/Modal";
import { NumericInput } from "../../../components/NumericInput";
import type { PurchaseOrderItem, PurchaseReturnOption } from "../../../types";
import { RETURN_OPTION_LABEL } from "./purchaseReturnLabels";
import { formatQty } from "../../../utils/quantity";
import { message } from "../../../i18n/messages";

const readonly = { ...inputStyle, background: "#f3f4f6" };

/**
 * Return PO: goods received on this Purchase Order sent back to the vendor, per PO line. The
 * backend checks every quantity against what is still held from that line.
 */
export function ReturnToVendorModal({
  po,
  onClose,
  onSaved,
}: {
  po: PurchaseOrderItem;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [option, setOption] = useState<PurchaseReturnOption | "">("");
  const [quantities, setQuantities] = useState<Record<string, string>>({});
  // Lines whose receipts generated Component Assets: the returned Asset# are selected and the
  // quantity is the number selected (owner decision).
  const [selectedAssets, setSelectedAssets] = useState<
    Record<string, string[]>
  >({});
  const assetsOf = (id: string) =>
    po.return_summary?.items[id]?.returnable_assets ?? [];
  const toggleAsset = (lineId: string, assetId: string) =>
    setSelectedAssets((sel) => {
      const current = sel[lineId] ?? [];
      const next = current.includes(assetId)
        ? current.filter((a) => a !== assetId)
        : [...current, assetId];
      setQuantities((q) => ({
        ...q,
        [lineId]: next.length ? String(next.length) : "",
      }));
      return { ...sel, [lineId]: next };
    });
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const returnable = (id: string) =>
    Number(po.return_summary?.items[id]?.returnable_quantity ?? 0);
  const lines = (po.items ?? []).filter((item) => returnable(item.id) > 0);

  async function save() {
    setError(null);
    const items = lines
      .filter((item) => Number(quantities[item.id] || 0) > 0)
      .map((item) => ({
        purchase_order_item_id: item.id,
        quantity: quantities[item.id],
        ...(assetsOf(item.id).length > 0
          ? { component_asset_ids: selectedAssets[item.id] ?? [] }
          : {}),
      }));
    if (!option) return setError("Choose the Return Option.");
    if (items.length === 0)
      return setError(
        "Enter the Qty Returned to Vendor for at least one item.",
      );
    const over = lines.find(
      (item) => Number(quantities[item.id] || 0) > returnable(item.id),
    );
    if (over)
      return setError(
        message("procurement.validation.returnQtyExceeded", { productName: over.product?.name ?? message("account.fields.item"), returnable: formatQty(returnable(over.id)) }),
      );
    setSaving(true);
    try {
      await apiClient.post(`/app/purchase-orders/${po.id}/returns`, {
        return_option: option,
        items,
        notes: notes.trim() || null,
      });
      onSaved();
    } catch (err) {
      setError(extractApiError(err).message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open title="Return PO" onClose={onClose} width={640}>
      <div data-return-po style={{ display: "grid", gap: 12 }}>
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))",
            gap: 12,
          }}
        >
          <FormField label="Vendor Name">
            <input
              aria-label="Vendor Name"
              value={po.partner?.name ?? "—"}
              readOnly
              style={readonly}
            />
          </FormField>
          <FormField label="Vendor PIC Phone Number">
            <input
              aria-label="Vendor PIC Phone Number"
              value={po.partner?.contact_phone ?? "—"}
              readOnly
              style={readonly}
            />
          </FormField>
        </div>
        <FormField label="Vendor Address">
          <textarea
            aria-label="Vendor Address"
            value={po.partner?.address ?? "—"}
            readOnly
            rows={2}
            style={{ ...readonly, resize: "none" }}
          />
        </FormField>
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))",
            gap: 12,
          }}
        >
          <FormField label="PO Number">
            <input
              aria-label="PO Number"
              value={po.po_number}
              readOnly
              style={readonly}
            />
          </FormField>
          <FormField label="Return Option" required>
            <select
              aria-label="Return Option"
              value={option}
              onChange={(e) =>
                setOption(e.target.value as PurchaseReturnOption | "")
              }
              style={inputStyle}
            >
              <option value="">Select…</option>
              <option value="REFUND">{RETURN_OPTION_LABEL.REFUND}</option>
              <option value="REDELIVERY">
                {RETURN_OPTION_LABEL.REDELIVERY}
              </option>
            </select>
          </FormField>
        </div>
        <div>
          <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 6 }}>
            Qty Returned to Vendor
          </div>
          {lines.map((item) => (
            <div
              key={item.id}
              data-return-line={item.product?.name}
              style={{
                display: "flex",
                alignItems: "center",
                justifyContent: "space-between",
                gap: 10,
                padding: "6px 0",
                borderBottom: "1px solid #f3f4f6",
                flexWrap: "wrap",
              }}
            >
              <span style={{ fontSize: 13, flex: "1 1 200px" }}>
                {item.product?.name ?? item.product_id}
                <span style={{ color: "#6b7280" }}>
                  {" "}
                  — received {formatQty(item.quantity_received)}, returnable{" "}
                  {formatQty(returnable(item.id))}
                </span>
              </span>
              <NumericInput
                aria-label={`Qty Returned ${item.product?.name ?? item.id}`}
                readOnly={assetsOf(item.id).length > 0}
                step="0.0001"
                min="0"
                max={String(returnable(item.id))}
                placeholder="0"
                value={quantities[item.id] ?? ""}
                onChange={(e) =>
                  setQuantities((q) => ({ ...q, [item.id]: e.target.value }))
                }
                style={{ ...inputStyle, width: 130 }}
              />
              {assetsOf(item.id).length > 0 && (
                <fieldset
                  data-return-assets={item.product?.name}
                  style={{
                    flex: "1 1 100%",
                    border: "1px solid #e5e7eb",
                    borderRadius: 6,
                    padding: "6px 10px",
                    margin: 0,
                  }}
                >
                  <legend style={{ fontSize: 12, color: "#374151" }}>
                    Select the Asset# returned (quantity = number selected)
                  </legend>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 10 }}>
                    {assetsOf(item.id).map((a) => (
                      <label
                        key={a.id}
                        style={{
                          fontSize: 12,
                          display: "inline-flex",
                          gap: 4,
                          alignItems: "center",
                        }}
                      >
                        <input
                          type="checkbox"
                          checked={(selectedAssets[item.id] ?? []).includes(
                            a.id,
                          )}
                          onChange={() => toggleAsset(item.id, a.id)}
                        />
                        <span style={{ fontFamily: "monospace" }}>
                          {a.asset_number}
                        </span>
                        {a.serial_number && (
                          <span style={{ color: "#6b7280" }}>
                            (S/N {a.serial_number})
                          </span>
                        )}
                      </label>
                    ))}
                  </div>
                </fieldset>
              )}
            </div>
          ))}
        </div>
        <FormField label="Notes">
          <textarea
            aria-label="Return notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            style={inputStyle}
          />
        </FormField>
        {error && (
          <div role="alert" style={{ color: "#b91c1c", fontSize: 13 }}>
            {error}
          </div>
        )}
        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8 }}>
          <button className="btn-secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button className="btn-primary" onClick={save} disabled={saving}>
            {saving ? "Saving…" : "Save"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
