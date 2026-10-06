import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { Modal } from "../../../components/Modal";
import { ErrorState, LoadingState } from "../../../components/States";
import type { DocumentTypeOption } from "../../../types";
import { DocumentConfigList, type EditorRequest } from "./DocumentConfigList";
import {
  TemplateEditor,
  type TemplateEditorTarget,
} from "./templates/TemplateEditor";
import { t as tt } from '../../../i18n/i18n';

/**
 * Configuration → Document Template: per printed document type, the System Default and your
 * Custom Configuration, designed in the visual editor (variables and repeating blocks as cards,
 * no HTML or JSON to edit). Documents already printed are not changed.
 */
export function DocumentTemplateConfigPage() {
  const [types, setTypes] = useState<DocumentTypeOption[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<EditorRequest | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [preview, setPreview] = useState<{ code: string; html: string } | null>(
    null,
  );

  useEffect(() => {
    apiClient
      .get("/app/configuration/metadata", { params: { type: "TEMPLATE" } })
      .then((res) => setTypes(res.data.data.document_type_options))
      .catch((e) => setError(extractApiError(e).message));
  }, []);

  async function handlePreview(code: string, payload: Record<string, unknown>) {
    try {
      const body = payload.editor
        ? {
            type: "TEMPLATE",
            code,
            editor: (payload.editor as { nodes: unknown[] }).nodes,
          }
        : {
            type: "TEMPLATE",
            code,
            html: (payload.html as string | undefined) ?? "",
          };
      const res = await apiClient.post("/app/configuration/preview", body);
      setPreview({ code, html: res.data.data.html });
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  if (error && !types) return <ErrorState message={error} />;
  if (!types) return <LoadingState />;
  const label = (code: string) =>
    types.find((t) => t.key === code)?.label ?? code;

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{tt('configuration.titles.documentTemplates')}</h1>
      <p style={{ color: "#6b7280", fontSize: 13, marginBottom: 16 }}>
        {tt('configuration.help.designHowEachPrintedDocumentLooks')}
      </p>
      {error && <ErrorState message={error} />}
      <DocumentConfigList
        type="TEMPLATE"
        managePermission="document_template.manage"
        publishPermission="document_template.publish"
        documentTypes={types}
        reloadKey={reloadKey}
        newLabel={tt('configuration.actions.newDocumentTypeConfiguration')}
        describe={(payload) => (
          <span style={{ color: "#6b7280" }}>
            {payload.editor ? tt('configuration.help.designedVisualEditor') : tt('configuration.fields.template')}
          </span>
        )}
        onEdit={setEditing}
        onPreview={handlePreview}
      />
      {editing && (
        <TemplateEditor
          documentTypes={types}
          target={editing as TemplateEditorTarget}
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
          title={tt('configuration.modals.previewValue', { value: label(preview.code) })}
          onClose={() => setPreview(null)}
          width={860}
        >
          <iframe
            title={tt('configuration.tooltips.templatePreview')}
            sandbox=""
            srcDoc={preview.html}
            style={{
              width: "100%",
              height: "65vh",
              border: "1px solid #e5e7eb",
              borderRadius: 6,
              background: "#fff",
            }}
            data-template-preview
          />
        </Modal>
      )}
    </div>
  );
}
