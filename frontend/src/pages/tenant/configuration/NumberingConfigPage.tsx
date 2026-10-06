import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { Modal } from "../../../components/Modal";
import { ErrorState, LoadingState } from "../../../components/States";
import type { NumberingMetadata, NumberingPayload } from "../../../types";
import { DocumentConfigList, type EditorRequest } from "./DocumentConfigList";
import { NumberingBuilderModal } from "./numbering/NumberingBuilderModal";
import { displayFormat, parseFormat } from "./numbering/numberingFormat";
import { t } from '../../../i18n/i18n';

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
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{t('configuration.titles.documentNumbering')}</h1>
      <p style={{ color: "#6b7280", fontSize: 13, marginBottom: 16 }}>
        {t('configuration.help.chooseHowDocumentNumbersLookEach')}
      </p>
      <DocumentConfigList
        type="NUMBERING"
        managePermission="numbering.manage"
        publishPermission="numbering.publish"
        documentTypes={meta.document_types}
        reloadKey={reloadKey}
        newLabel={t('configuration.actions.newDocumentTypeConfiguration')}
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
          title={t('configuration.fields.previewValue', { value: label(preview.code) })}
          onClose={() => setPreview(null)}
        >
          <p style={{ color: "#6b7280", fontSize: 13 }}>
            {t('configuration.help.sampleNumberFormatProducesRealRunning')}:
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
