import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { ErrorState, LoadingState } from "../../../components/States";
import type { DocumentTypeOption, WorkflowPayload } from "../../../types";
import { DocumentConfigList } from "./DocumentConfigList";
import {
  WorkflowBuilder,
  type WorkflowBuilderTarget,
} from "./workflow/WorkflowBuilder";

/**
 * Configuration → Workflow: per document type, the System Default workflow and your Custom
 * Configuration with its versions. Every workflow opens in the Visual Workflow Builder (statuses
 * as cards, transitions as arrows). Publishing makes it the workflow the modules enforce for
 * documents created from then on; documents already in progress keep the version they started
 * with.
 */
export function WorkflowConfigPage() {
  const [types, setTypes] = useState<DocumentTypeOption[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [builder, setBuilder] = useState<WorkflowBuilderTarget | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    apiClient
      .get("/app/configuration/metadata", { params: { type: "WORKFLOW" } })
      .then((res) =>
        setTypes(
          (
            res.data.data.resource_types as Array<{
              code: string;
              name: string;
            }>
          ).map((r) => ({
            key: r.code,
            label: r.name.replace(/ Workflow$/, ""),
            numbering: false,
            template: false,
            extra_tokens: [],
          })),
        ),
      )
      .catch((e) => setError(extractApiError(e).message));
  }, []);

  if (error && !types) return <ErrorState message={error} />;
  if (!types) return <LoadingState />;

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Workflow</h1>
      <p style={{ color: "#6b7280", fontSize: 13, marginBottom: 16 }}>
        Design how each document moves between its statuses. Open a workflow to
        see it as cards and arrows; New Draft lets you add, remove or redirect
        transitions. A published workflow applies to documents created after
        publishing — documents already in progress keep their workflow.
      </p>
      <DocumentConfigList
        type="WORKFLOW"
        managePermission="workflow.manage"
        publishPermission="workflow.publish"
        documentTypes={types}
        reloadKey={reloadKey}
        newLabel="New Workflow"
        describe={(payload) => {
          const p = payload as unknown as WorkflowPayload;
          return (
            <span style={{ color: "#6b7280" }}>
              {p.statuses?.length ?? 0} statuses · {p.transitions?.length ?? 0}{" "}
              transitions
            </span>
          );
        }}
        onEdit={(request) => setBuilder({ ...request, mode: "edit" })}
        onPreview={(code, payload, version) =>
          setBuilder({
            code,
            name: null,
            payload,
            versionId: version?.id ?? null,
            title: `${types.find((t) => t.key === code)?.label ?? code} Workflow${version ? ` v${version.version_number}` : ""}`,
            mode:
              version && !version.isSystem && version.status === "PUBLISHED"
                ? "layout"
                : "view",
          })
        }
      />
      {builder && (
        <WorkflowBuilder
          target={builder}
          onClose={() => setBuilder(null)}
          onSaved={() => {
            setBuilder(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}
