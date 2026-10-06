import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { apiClient, extractApiError } from "../../../../api/client";
import { useAuth } from "../../../../auth/AuthContext";
import { FormField, inputStyle } from "../../../../components/FormField";
import { Modal } from "../../../../components/Modal";
import { NumericInput } from "../../../../components/NumericInput";
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from "../../../../components/States";
import { StatusBadge } from "../../../../components/StatusBadge";
import { Toolbar } from "../../../../components/Toolbar";
import type { PartnerItem } from "../../../../types";
import { formatDate } from "../../../../utils/date";
import { formatMoney } from "../../../../utils/money";
import type { CycleListRow } from "./retreadTypes";
import { message } from "../../../../i18n/messages";
import { t } from '../../../../i18n/i18n';

const MAX_PHOTOS = 3;
const PHOTO_MAX_BYTES = 3 * 1024 * 1024;
/** The vendor types the backend accepts for retread / repair work (TireService). */
const SERVICE_VENDOR_TYPES = ["EXTERNAL_WORKSHOP", "TIRE_SUPPLIER"];

/**
 * Used Tire Management → Retread → "Tires in Retread / Repair Cycle": Open Cycle (Retread Form) →
 * Receive (opens the Tire Inspection) → the cycle completes when that inspection is approved.
 */
export function RetreadCyclePanel() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [rows, setRows] = useState<CycleListRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [opening, setOpening] = useState<CycleListRow | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(() => {
    apiClient
      .get("/app/tire-cycles", { params: { search: search || undefined } })
      .then((res) => setRows(res.data.data))
      .catch((err) => setError(extractApiError(err).message));
  }, [search]);
  useEffect(load, [load]);

  async function receive(row: CycleListRow) {
    if (!row.cycle) return;
    setBusy(row.tire.id);
    setError(null);
    try {
      await apiClient.post(
        `/app/tire-cycles/${row.kind.toLowerCase()}/${row.cycle.id}/receive`,
      );
      // The tire must be inspected again before the cycle can complete.
      navigate(`/app/tires/${row.tire.id}/inspection`);
    } catch (err) {
      setError(extractApiError(err).message);
      setBusy(null);
    }
  }

  const can = (row: CycleListRow, step: "send" | "receive") =>
    hasPermission(
      `tire_${row.kind === "REPAIR" ? "repair" : "retread"}.${step}`,
    );

  function action(row: CycleListRow) {
    const state = row.cycle?.state;
    if (!row.cycle)
      return (
        can(row, "send") && (
          <button
            className="btn-primary"
            style={{ padding: "4px 10px", fontSize: 12 }}
            onClick={() => setOpening(row)}
          >
            {t('tire.actions.openCycle')}
          </button>
        )
      );
    if (state === "IN_PROCESS")
      return (
        can(row, "receive") && (
          <button
            className="btn-primary"
            style={{ padding: "4px 10px", fontSize: 12 }}
            disabled={busy === row.tire.id}
            onClick={() => receive(row)}
          >
            {t('tire.actions.receive')}
          </button>
        )
      );
    return (
      <Link
        to={`/app/tires/${row.tire.id}/inspection`}
        className="btn-secondary"
        style={{ padding: "4px 10px", fontSize: 12, textDecoration: "none" }}
      >
        {state === "RECEIVED" ? t('tire.actions.inspect') : t('tire.actions.reviewInspection')}
      </Link>
    );
  }

  return (
    <section
      className="card"
      style={{ marginBottom: 16 }}
      data-workflow-candidates
      data-retread-cycles
    >
      <h3 style={{ marginTop: 0, fontSize: 15 }}>
        {t('tire.sections.tiresRetreadRepairCycle')}{" "}
        {rows && (
          <span style={{ color: "#6b7280", fontWeight: 400 }}>
            ({rows.length})
          </span>
        )}
      </h3>
      <Toolbar search={search} onSearchChange={setSearch} />
      {error && <ErrorState message={error} />}
      {!rows && !error && <LoadingState />}
      {rows && rows.length === 0 && (
        <EmptyState label={t('tire.empty.noTiresWaitingRetreadRepairCycle')} />
      )}
      {rows && rows.length > 0 && (
        <div style={{ overflowX: "auto" }}>
          <table
            style={{
              width: "100%",
              borderCollapse: "collapse",
              fontSize: 13,
              minWidth: 760,
            }}
          >
            <thead>
              <tr
                style={{ textAlign: "left", borderBottom: "1px solid #e5e7eb" }}
              >
                {[
                  t('tire.fields.serial'),
                  t('common.fields.product'),
                  t('common.fields.type'),
                  t('common.fields.cycle'),
                  t('tire.fields.processedAt'),
                  t('tire.fields.estimatedPrice'),
                  t('tire.fields.opened'),
                  t('documents.stockTransfer.received'),
                  t('common.fields.action'),
                ].map((h) => (
                  <th key={h} style={{ padding: 8 }}>
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr
                  key={row.tire.id}
                  data-cycle-row={row.tire.serial_number}
                  data-cycle-state={row.cycle?.state ?? "WAITING"}
                  style={{ borderBottom: "1px solid #f3f4f6" }}
                >
                  <td style={{ padding: 8 }}>
                    <Link
                      to={`/app/tires/${row.tire.id}`}
                      style={{ fontFamily: "monospace" }}
                    >
                      {row.tire.serial_number}
                    </Link>
                  </td>
                  <td style={{ padding: 8 }}>
                    {row.tire.product?.name ?? "—"}
                  </td>
                  <td style={{ padding: 8 }}>
                    {row.kind === "REPAIR" ? t('inventory.fields.repair') : t('tire.help.retread')}
                  </td>
                  <td style={{ padding: 8 }}>
                    {row.cycle ? (
                      <StatusBadge status={row.cycle.state} />
                    ) : (
                      <span style={{ color: "#6b7280" }}>{t('tire.help.waiting')}</span>
                    )}
                  </td>
                  <td style={{ padding: 8 }}>
                    {row.cycle?.vendor?.name ?? "—"}
                  </td>
                  <td style={{ padding: 8 }}>
                    {row.cycle?.estimated_price != null
                      ? formatMoney(row.cycle.estimated_price)
                      : "—"}
                  </td>
                  <td style={{ padding: 8 }}>
                    {formatDate(row.cycle?.sent_at)}
                  </td>
                  <td style={{ padding: 8 }}>
                    {formatDate(row.cycle?.received_at)}
                  </td>
                  <td style={{ padding: 8 }}>{action(row)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {opening && (
        <RetreadFormModal
          row={opening}
          onClose={() => setOpening(null)}
          onSaved={() => {
            setOpening(null);
            load();
          }}
        />
      )}
    </section>
  );
}

/** Retread Form: Processed At (vendor), Estimated Price, 1–3 photos (mandatory), Notes. */
function RetreadFormModal({
  row,
  onClose,
  onSaved,
}: {
  row: CycleListRow;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [vendors, setVendors] = useState<PartnerItem[]>([]);
  const [partnerId, setPartnerId] = useState("");
  const [price, setPrice] = useState("");
  const [photos, setPhotos] = useState<File[]>([]);
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const previews = useMemo(
    () => photos.map((f) => URL.createObjectURL(f)),
    [photos],
  );
  useEffect(
    () => () => previews.forEach((u) => URL.revokeObjectURL(u)),
    [previews],
  );

  useEffect(() => {
    apiClient
      .get("/app/partners", { params: { status: "ACTIVE", per_page: 100 } })
      .then((res) =>
        setVendors(
          (res.data.data as PartnerItem[]).filter(
            (p) =>
              SERVICE_VENDOR_TYPES.includes(p.partner_type) &&
              (p.status ?? "ACTIVE") === "ACTIVE",
          ),
        ),
      )
      .catch(() => setVendors([]));
  }, []);

  function addPhotos(files: FileList | null) {
    if (!files) return;
    setError(null);
    const accepted: File[] = [];
    for (const f of Array.from(files)) {
      if (!["image/jpeg", "image/png"].includes(f.type)) {
        setError(t('tire.validation.photosMustJpgPngImages'));
        continue;
      }
      if (f.size > PHOTO_MAX_BYTES) {
        setError(t('tire.errors.nameExceeds3MbMaximumSize', { name: f.name }));
        continue;
      }
      accepted.push(f);
    }
    const next = [...photos, ...accepted];
    if (next.length > MAX_PHOTOS) setError(t('tire.validation.mostMaxPhotosPhotos', { MAX_PHOTOS: MAX_PHOTOS }));
    setPhotos(next.slice(0, MAX_PHOTOS));
  }

  async function save() {
    setError(null);
    if (!partnerId)
      return setError(t('tire.validation.chooseWhereTireProcessedProcessed'));
    if (!(Number(price) > 0)) return setError(t('tire.validation.estimatedPriceIsRequired'));
    if (photos.length === 0) return setError(t('tire.validation.uploadLeastOnePhoto'));
    const form = new FormData();
    form.append("partner_id", partnerId);
    form.append("estimated_price", price);
    photos.forEach((p) => form.append("photos[]", p));
    if (notes.trim()) form.append("notes", notes.trim());
    setSaving(true);
    try {
      await apiClient.post(`/app/tires/${row.tire.id}/cycles`, form);
      onSaved();
    } catch (err) {
      const e = extractApiError(err);
      setError(
        e.errors ? (Object.values(e.errors).flat()[0] ?? e.message) : e.message,
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      title={message(row.kind === "REPAIR" ? "tire.retread.repairFormTitle" : "tire.retread.retreadFormTitle", { serialNumber: row.tire.serial_number })}
      onClose={onClose}
      width={620}
    >
      <div data-retread-form style={{ display: "grid", gap: 12 }}>
        <FormField label={t('tire.fields.processedAt')} required>
          <select
            aria-label={t('tire.fields.processedAt')}
            value={partnerId}
            onChange={(e) => setPartnerId(e.target.value)}
            style={inputStyle}
          >
            <option value="">{t('procurement.fields.selectVendor')}</option>
            {vendors.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </select>
        </FormField>
        <FormField label={t('tire.fields.estimatedPrice')} required>
          <NumericInput
            aria-label={t('tire.fields.estimatedPrice')}
            step="0.01"
            min="0.01"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            style={inputStyle}
          />
        </FormField>
        <FormField label={t('tire.fields.photoRequiredUpMaxPhotos', { MAX_PHOTOS: MAX_PHOTOS })} required>
          <div>
            <input
              aria-label={t('tire.fields.photos')}
              type="file"
              accept=".jpg,.jpeg,.png,image/jpeg,image/png"
              multiple
              disabled={photos.length >= MAX_PHOTOS}
              onChange={(e) => {
                addPhotos(e.target.files);
                e.target.value = "";
              }}
            />
            {photos.length > 0 && (
              <div
                style={{
                  display: "flex",
                  gap: 8,
                  flexWrap: "wrap",
                  marginTop: 8,
                }}
                data-photo-previews
              >
                {photos.map((p, i) => (
                  <div key={`${p.name}-${i}`} style={{ position: "relative" }}>
                    <img
                      src={previews[i]}
                      alt={p.name}
                      style={{
                        width: 96,
                        height: 64,
                        objectFit: "contain",
                        border: "1px solid #e5e7eb",
                        borderRadius: 6,
                        background: "#f9fafb",
                      }}
                    />
                    <button
                      type="button"
                      aria-label={t('tire.actions.removeName', { name: p.name })}
                      onClick={() =>
                        setPhotos(photos.filter((_, j) => j !== i))
                      }
                      style={{
                        position: "absolute",
                        top: -6,
                        right: -6,
                        width: 20,
                        height: 20,
                        borderRadius: "50%",
                        border: "none",
                        background: "#ef4444",
                        color: "#fff",
                        cursor: "pointer",
                        fontSize: 12,
                      }}
                    >
                      ×
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </FormField>
        <FormField label={t('common.fields.notes')}>
          <textarea
            aria-label={t('common.fields.notes')}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={3}
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
            {t('common.actions.cancel')}
          </button>
          <button className="btn-primary" onClick={save} disabled={saving}>
            {saving ? t('common.actions.saving') : t('common.actions.save')}
          </button>
        </div>
      </div>
    </Modal>
  );
}
