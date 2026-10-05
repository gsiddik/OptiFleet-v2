import { useEffect, useMemo, useRef, useState } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { FormField, inputStyle } from "../../../../components/FormField";
import { InfoTip } from "../../../../components/InfoTip";
import { Modal } from "../../../../components/Modal";
import { NumericInput } from "../../../../components/NumericInput";
import type {
  NumberingMetadata,
  NumberingPayload,
  NumberingSegment,
} from "../../../../types";
import {
  FormatEditor,
  TOKEN_DRAG_TYPE,
  type FormatEditorHandle,
} from "./FormatEditor";
import {
  displayFormat,
  parseFormat,
  serializeFormat,
  usedTokens,
  validateBuilder,
} from "./numberingFormat";

const INITIAL_KEYS: Record<string, string> = {
  TENANT: "tenant_initial",
  BRANCH: "branch_initial",
  WORKSHOP: "workshop_initial",
  WAREHOUSE: "warehouse_initial",
};

export interface NumberingBuilderTarget {
  /** Fixed document type (New Draft / Edit), or null to choose one. */
  code: string | null;
  /** Fixed configuration name (an existing custom configuration), or null to enter one. */
  name: string | null;
  defaultName?: string;
  payload: NumberingPayload | null;
  /** Editing this DRAFT version; otherwise a new draft is created. */
  versionId: string | null;
  title: string;
}

/**
 * New / Edit Document Numbering: a Format Builder instead of JSON. Token cards are inserted at
 * the caret (click or Enter) or dragged into the Format; custom text is typed directly. Only the
 * settings of tokens in use are shown. The preview comes from the backend numbering service
 * (sample date: today, sample number: the first), so it matches what real documents get.
 */
export function NumberingBuilderModal({
  meta,
  target,
  onClose,
  onSaved,
}: {
  meta: NumberingMetadata;
  target: NumberingBuilderTarget;
  onClose: () => void;
  onSaved: () => void;
}) {
  const base = useMemo<NumberingPayload>(
    () => target.payload ?? { format: "", reset_rule: "YEARLY" },
    [target.payload],
  );
  const parsed = useMemo(() => parseFormat(base), [base]);
  const [code, setCode] = useState(target.code ?? "");
  const [name, setName] = useState(target.name ?? target.defaultName ?? "");
  const [segments, setSegments] = useState<NumberingSegment[]>(parsed.segments);
  const [digits, setDigits] = useState(
    parsed.digits ? String(parsed.digits) : "6",
  );
  const [params, setParams] = useState<Record<string, string>>({
    doc_code: base.doc_code ?? "",
    tenant_initial: base.tenant_initial ?? "",
    branch_initial: base.branch_initial ?? "",
    workshop_initial: base.workshop_initial ?? "",
    warehouse_initial: base.warehouse_initial ?? "",
  });
  const [resetRule, setResetRule] = useState(base.reset_rule ?? "NEVER");
  const [changeSummary, setChangeSummary] = useState("");
  const [preview, setPreview] = useState("");
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const editor = useRef<FormatEditorHandle>(null);

  const docType = meta.document_types.find((d) => d.key === code);
  const cards = meta.token_definitions.filter(
    (t) =>
      !["ITEMTYPE", "CG"].includes(t.token) ||
      docType?.extra_tokens.includes(t.token),
  );
  const labels = Object.fromEntries(
    meta.token_definitions.map((t) => [t.token, t.label]),
  );
  const tokens = usedTokens(segments);
  const errors = validateBuilder(segments, digits, meta.max_sequence_digits);

  const payload = useMemo<NumberingPayload>(() => {
    const out: NumberingPayload = {
      ...base,
      format: serializeFormat(segments, Number(digits) || null),
      reset_rule: resetRule,
    };
    if (params.doc_code.trim()) out.doc_code = params.doc_code.trim();
    else delete out.doc_code;
    for (const [token, key] of Object.entries(INITIAL_KEYS)) {
      // Kept while editing even when the token is removed; only saved for tokens in use.
      if (tokens.has(token) && params[key].trim())
        out[key] = params[key].trim();
      else delete out[key];
    }
    return out;
  }, [base, segments, digits, resetRule, params, tokens]);

  // Real-time preview from the backend numbering service (debounced; stale answers ignored).
  const seq = useRef(0);
  useEffect(() => {
    const mine = ++seq.current;
    if (errors.length > 0 || !code) {
      setPreview("");
      setPreviewError(errors[0] ?? (code ? null : "Choose the Document Type."));
      return;
    }
    const timer = setTimeout(() => {
      apiClient
        .post("/app/configuration/preview", {
          type: "NUMBERING",
          code,
          payload,
        })
        .then(
          (res) =>
            mine === seq.current &&
            (setPreview(res.data.data.preview), setPreviewError(null)),
        )
        .catch(
          (e) =>
            mine === seq.current &&
            (setPreview(""), setPreviewError(extractApiError(e).message)),
        );
    }, 250);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(payload), code, errors.length]);

  async function save() {
    setSaveError(null);
    if (!code || !name.trim() || errors.length > 0) {
      setSaveError(
        !code
          ? "Choose the Document Type."
          : !name.trim()
            ? "Name is required."
            : errors[0],
      );
      return;
    }
    setSaving(true);
    try {
      if (target.versionId)
        await apiClient.put(`/app/configuration/versions/${target.versionId}`, {
          payload,
          change_summary: changeSummary || null,
        });
      else
        await apiClient.post("/app/configuration/versions", {
          type: "NUMBERING",
          code,
          name: name.trim(),
          payload,
          change_summary: changeSummary || null,
        });
      onSaved();
    } catch (e) {
      setSaveError(extractApiError(e).message);
    } finally {
      setSaving(false);
    }
  }

  const param = (key: string, label: string, help: string) => (
    <FormField label={label} hint={help}>
      <input
        aria-label={label}
        value={params[key]}
        maxLength={30}
        onChange={(e) => setParams((p) => ({ ...p, [key]: e.target.value }))}
        style={inputStyle}
      />
    </FormField>
  );

  return (
    <Modal open title={target.title} onClose={onClose} width={760}>
      <div data-numbering-builder>
        <div
          style={{
            display: "grid",
            gridTemplateColumns:
              "repeat(auto-fit, minmax(min(100%, 260px), 1fr))",
            gap: "0 14px",
          }}
        >
          <FormField
            label="Document Type"
            required
            hint="The document this numbering is used for. The list contains every document the system numbers."
          >
            <select
              aria-label="Document Type"
              value={code}
              disabled={!!target.code}
              onChange={(e) => setCode(e.target.value)}
              style={inputStyle}
            >
              <option value="">Select…</option>
              {meta.document_types.map((d) => (
                <option key={d.key} value={d.key}>
                  {d.label}
                </option>
              ))}
            </select>
          </FormField>
          <FormField
            label="Name"
            required
            hint="A name for this numbering configuration, e.g. Purchase Order Jakarta."
          >
            <input
              aria-label="Name"
              value={name}
              disabled={!!target.name}
              onChange={(e) => setName(e.target.value)}
              style={inputStyle}
            />
          </FormField>
        </div>

        <FormField
          label="Format"
          required
          hint="Type your own text (e.g. RPO-) and add the parts below. Click a card to insert it where the cursor is, or drag it into the Format. A part can be used more than once."
        >
          <FormatEditor
            ref={editor}
            initial={parsed.segments}
            labels={labels}
            onChange={setSegments}
            invalid={errors.length > 0}
          />
        </FormField>
        <div
          role="group"
          aria-label="Format parts"
          style={{
            display: "flex",
            flexWrap: "wrap",
            gap: 6,
            margin: "-6px 0 14px",
          }}
          data-token-cards
        >
          {cards.map((t) => (
            <span
              key={t.token}
              style={{ display: "inline-flex", alignItems: "center" }}
            >
              <button
                type="button"
                draggable
                data-token-card={t.token}
                title={`${t.label}: ${t.description}`}
                onDragStart={(e) => {
                  e.dataTransfer.setData(TOKEN_DRAG_TYPE, t.token);
                  e.dataTransfer.effectAllowed = "copy";
                }}
                onMouseDown={
                  (e) => e.preventDefault() /* keep the Format's cursor */
                }
                onClick={() => editor.current?.insertToken(t.token)}
                style={{
                  padding: "3px 9px",
                  borderRadius: 6,
                  border: "1px solid #93c5fd",
                  background: "#eff6ff",
                  color: "#1e3a8a",
                  fontWeight: 600,
                  fontSize: 12,
                  cursor: "grab",
                }}
              >
                {t.token}
              </button>
              <InfoTip label={t.token}>
                <strong>{t.label}</strong> — {t.description}
              </InfoTip>
            </span>
          ))}
        </div>

        <div
          style={{
            display: "grid",
            gridTemplateColumns:
              "repeat(auto-fit, minmax(min(100%, 220px), 1fr))",
            gap: "0 14px",
          }}
          data-token-parameters
        >
          {tokens.has("DOC") &&
            param(
              "doc_code",
              "Doc Code Name",
              "The code the DOC part shows, e.g. PO.",
            )}
          {tokens.has("TENANT") &&
            param(
              "tenant_initial",
              "Tenant Initial",
              "Shown by TENANT, e.g. ALP. Leave empty to use the tenant code.",
            )}
          {tokens.has("BRANCH") &&
            param(
              "branch_initial",
              "Branch Initial",
              "Shown by BRANCH. Leave empty to use the code of the branch each document belongs to.",
            )}
          {tokens.has("WORKSHOP") &&
            param(
              "workshop_initial",
              "Workshop Initial",
              "Shown by WORKSHOP, e.g. WSBDG. Leave empty to use the code of the workshop each document belongs to.",
            )}
          {tokens.has("WAREHOUSE") &&
            param(
              "warehouse_initial",
              "Warehouse Initial",
              "Shown by WAREHOUSE. Leave empty to use the code of the warehouse each document belongs to.",
            )}
          {tokens.has("SEQ:N") && (
            <FormField
              label="Sequential Digit"
              required
              hint={`Number of digits of the running number (1–${meta.max_sequence_digits}); 6 gives 000001.`}
            >
              <NumericInput
                aria-label="Sequential Digit"
                integer
                value={digits}
                onChange={(e) => setDigits(e.target.value)}
                style={inputStyle}
              />
            </FormField>
          )}
          <FormField
            label="Restart Numbering"
            hint="When the running number starts again from the beginning."
          >
            <select
              aria-label="Restart Numbering"
              value={resetRule}
              onChange={(e) => setResetRule(e.target.value)}
              style={inputStyle}
            >
              {meta.reset_rules.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </select>
          </FormField>
        </div>

        <FormField
          label="Format Preview"
          hint="Example of the first number with today's date. Real numbers are created by the system when a document is saved."
        >
          <input
            aria-label="Format Preview"
            readOnly
            value={preview || "—"}
            style={{
              ...inputStyle,
              background: "#f3f4f6",
              fontFamily: "ui-monospace, monospace",
              fontWeight: 600,
            }}
            data-format-preview
          />
        </FormField>
        {previewError && (
          <div
            role="alert"
            style={{
              color: "#b91c1c",
              fontSize: 12,
              marginTop: -8,
              marginBottom: 10,
            }}
            data-format-error
          >
            {previewError}
          </div>
        )}
        <div style={{ fontSize: 12, color: "#6b7280", marginBottom: 10 }}>
          Format:{" "}
          <span style={{ fontFamily: "ui-monospace, monospace" }}>
            {displayFormat(segments) || "—"}
          </span>
        </div>
        <FormField label="Change Summary">
          <input
            aria-label="Change Summary"
            value={changeSummary}
            onChange={(e) => setChangeSummary(e.target.value)}
            placeholder="What changed and why (optional)"
            style={inputStyle}
          />
        </FormField>
        {saveError && (
          <div
            role="alert"
            style={{ color: "#b91c1c", fontSize: 13, marginBottom: 8 }}
          >
            {saveError}
          </div>
        )}
        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8 }}>
          <button className="btn-secondary" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button className="btn-primary" onClick={save} disabled={saving}>
            {saving ? "Saving…" : "Save Draft"}
          </button>
        </div>
      </div>
    </Modal>
  );
}
