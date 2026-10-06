import { useEffect, useState } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { ErrorState, LoadingState } from "../../../components/States";
import type { DocumentTypeOption, WorkflowPayload } from "../../../types";
import { DocumentConfigList } from "./DocumentConfigList";
import {
  WorkflowBuilder,
  type WorkflowBuilderTarget,
} from "./workflow/WorkflowBuilder";
import { t as tt } from '../../../i18n/i18n';

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
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>{tt('configuration.titles.workflow')}</h1>
      <p style={{ color: "#6b7280", fontSize: 13, marginBottom: 16 }}>
        {tt('configuration.help.designHowEachDocumentMovesBetween')}
      </p>
      <DocumentConfigList
        type="WORKFLOW"
        managePermission="workflow.manage"
        publishPermission="workflow.publish"
        documentTypes={types}
        reloadKey={reloadKey}
        newLabel={tt('configuration.actions.newWorkflow')}
        describe={(payload) => {
          const p = payload as unknown as WorkflowPayload;
          return (
            <span style={{ color: "#6b7280" }}>
              {tt('configuration.help.statusesTransitionsCount', { statuses: p.statuses?.length ?? 0, transitions: p.transitions?.length ?? 0 })}
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
