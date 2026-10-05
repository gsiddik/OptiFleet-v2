import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { Modal } from "../../../components/Modal";
import { ErrorState, LoadingState } from "../../../components/States";
import type { NumberingMetadata, NumberingPayload } from "../../../types";
import { DocumentConfigList, type EditorRequest } from "./DocumentConfigList";
import { NumberingBuilderModal } from "./numbering/NumberingBuilderModal";
import { displayFormat, parseFormat } from "./numbering/numberingFormat";

/**
 * Configuration → Document Numbering: per document type, the System Default and your Custom
 * Configuration, edited with the Format Builder (no JSON). Publishing a new version keeps the
 * running number; documents already created keep their numbers.
 */
export function NumberingConfigPage() {
  const [meta, setMeta] = useState<NumberingMetadata | null>(null);
  const [metaError, setMetaError] = useState<string | null>(null);
  const [editing, setEditing] = useState<EditorRequest | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [preview, setPreview] = useState<{ code: string; text: string } | null>(
    null,
  );

  useEffect(() => {
    apiClient
      .get("/app/configuration/metadata", { params: { type: "NUMBERING" } })
      .then((res) => setMeta(res.data.data))
      .catch((e) => setMetaError(extractApiError(e).message));
  }, []);

  async function handlePreview(code: string, payload: Record<string, unknown>) {
    try {
      const res = await apiClient.post("/app/configuration/preview", {
        type: "NUMBERING",
        code,
        payload,
      });
      setPreview({ code, text: res.data.data.preview });
    } catch (err) {
      setPreview({ code, text: extractApiError(err).message });
    }
  }

  if (metaError) return <ErrorState message={metaError} />;
  if (!meta) return <LoadingState />;
  const label = (code: string) =>
    meta.document_types.find((d) => d.key === code)?.label ?? code;

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Document Numbering</h1>
      <p style={{ color: "#6b7280", fontSize: 13, marginBottom: 16 }}>
        Choose how document numbers look for each document type. Build the
        format from your own text and parts such as the year, month or a running
        number; the preview shows the result. Publishing a new version keeps the
        running number, and documents already created keep their numbers.
      </p>
      <DocumentConfigList
        type="NUMBERING"
        managePermission="numbering.manage"
        publishPermission="numbering.publish"
        documentTypes={meta.document_types}
        reloadKey={reloadKey}
        newLabel="New Document Type Configuration"
        describe={(payload) => (
          <span style={{ fontFamily: "ui-monospace, monospace" }}>
            {displayFormat(
              parseFormat(payload as unknown as NumberingPayload).segments,
            )}
          </span>
        )}
        onEdit={setEditing}
        onPreview={handlePreview}
      />
      {editing && (
        <NumberingBuilderModal
          meta={meta}
          target={{
            ...editing,
            payload: editing.payload as NumberingPayload | null,
          }}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {preview && (
        <Modal
          open
          title={`Preview — ${label(preview.code)}`}
          onClose={() => setPreview(null)}
        >
          <p style={{ color: "#6b7280", fontSize: 13 }}>
            Sample number this format produces (the real running number is not
            used):
          </p>
          <div
            style={{
              fontFamily: "ui-monospace, monospace",
              fontSize: 18,
              padding: 12,
              background: "#f9fafb",
              borderRadius: 6,
              textAlign: "center",
            }}
            data-preview-number
          >
            {preview.text}
          </div>
        </Modal>
      )}
    </div>
  );
}
