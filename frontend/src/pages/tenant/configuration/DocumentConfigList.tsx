import { useState, type ReactNode } from "react";
import { apiClient, extractApiError } from "../../../api/client";
import { InfoTip } from "../../../components/InfoTip";
import { StatusBadge } from "../../../components/StatusBadge";
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from "../../../components/States";
import { useApiList } from "../../../hooks/useApiList";
import { useAuth } from "../../../auth/AuthContext";
import type {
  ConfigurationSetItem,
  ConfigurationType,
  ConfigurationVersionItem,
  DocumentTypeOption,
} from "../../../types";

/** What the page's editor (numbering builder / template editor) is opened with. */
export interface EditorRequest {
  code: string | null;
  name: string | null;
  defaultName?: string;
  payload: Record<string, unknown> | null;
  versionId: string | null;
  title: string;
}

/**
 * Configuration → Document Numbering / Document Template: one card per document type showing
 * the System Default (platform, read-only) and the tenant's Custom Configuration with its
 * versions, which one is active, and the actions — New Draft (from the default or the custom
 * one), Edit / Publish a draft, Preview, Return to System Default. The editor itself is the
 * page's own friendly editor (no JSON).
 */
export function DocumentConfigList({
  type,
  managePermission,
  publishPermission,
  documentTypes,
  reloadKey,
  newLabel,
  describe,
  onEdit,
  onPreview,
}: {
  type: ConfigurationType;
  managePermission: string;
  publishPermission: string;
  documentTypes: DocumentTypeOption[];
  reloadKey: number;
  newLabel: string;
  describe: (payload: Record<string, unknown>) => ReactNode;
  onEdit: (request: EditorRequest) => void;
  onPreview: (code: string, payload: Record<string, unknown>) => void;
}) {
  const { hasPermission } = useAuth();
  const [localReload, setLocalReload] = useState(0);
  const [actionError, setActionError] = useState<string | null>(null);
  const { data, loading, error } = useApiList<ConfigurationSetItem>(
    "/app/configuration/sets",
    { type, per_page: 200 },
    reloadKey + localReload,
  );
  const canManage = hasPermission(managePermission);
  const canPublish = hasPermission(publishPermission);
  const label = (code: string) =>
    documentTypes.find((d) => d.key === code)?.label ?? code.replace(/_/g, " ");

  const groups = new Map<
    string,
    { system?: ConfigurationSetItem; custom?: ConfigurationSetItem }
  >();
  for (const set of data) {
    const group = groups.get(set.code) ?? {};
    if (set.is_system || set.tenant_id === null) group.system = set;
    else group.custom = set;
    groups.set(set.code, group);
  }
  const codes = [...groups.keys()].sort((a, b) =>
    label(a).localeCompare(label(b)),
  );

  async function act(path: string) {
    setActionError(null);
    try {
      await apiClient.post(path);
      setLocalReload((k) => k + 1);
    } catch (e) {
      setActionError(extractApiError(e).message);
    }
  }

  const published = (set?: ConfigurationSetItem) =>
    set?.versions.find((v) => v.status === "PUBLISHED");

  const versionRow = (
    set: ConfigurationSetItem,
    v: ConfigurationVersionItem,
    isSystem: boolean,
  ) => (
    <tr
      key={v.id}
      style={{ borderTop: "1px solid #f1f5f9" }}
      data-config-version={`${set.code}:${isSystem ? "default" : "custom"}:v${v.version_number}`}
    >
      <td style={{ padding: "8px 12px", width: 52 }}>v{v.version_number}</td>
      <td style={{ padding: "8px 12px", width: 110 }}>
        <StatusBadge status={v.status} />
      </td>
      <td style={{ padding: "8px 12px", minWidth: 0 }}>
        <div style={{ overflowWrap: "anywhere" }}>{describe(v.payload)}</div>
        {v.change_summary && (
          <div style={{ color: "#6b7280", fontSize: 12 }}>
            {v.change_summary}
          </div>
        )}
      </td>
      <td
        style={{
          padding: "8px 12px",
          textAlign: "right",
          whiteSpace: "nowrap",
        }}
      >
        {!isSystem && v.status === "DRAFT" && canManage && (
          <button
            className="btn-secondary"
            style={{ marginRight: 6 }}
            onClick={() =>
              onEdit({
                code: set.code,
                name: set.name,
                payload: v.payload,
                versionId: v.id,
                title: `Edit Draft — ${label(set.code)}`,
              })
            }
          >
            Edit
          </button>
        )}
        {!isSystem && v.status === "DRAFT" && canPublish && (
          <button
            className="btn-primary"
            style={{ marginRight: 6 }}
            onClick={() => act(`/app/configuration/versions/${v.id}/publish`)}
          >
            Publish
          </button>
        )}
        <button
          className="btn-secondary"
          onClick={() => onPreview(set.code, v.payload)}
        >
          Preview
        </button>
      </td>
    </tr>
  );

  return (
    <div>
      {canManage && (
        <div
          style={{
            display: "flex",
            justifyContent: "flex-end",
            marginBottom: 12,
          }}
        >
          <button
            className="btn-primary"
            onClick={() =>
              onEdit({
                code: null,
                name: null,
                payload: null,
                versionId: null,
                title: newLabel,
              })
            }
            data-new-config
          >
            + {newLabel}
          </button>
        </div>
      )}
      {actionError && <ErrorState message={actionError} />}
      {error && <ErrorState message={error} />}
      {!error && loading && data.length === 0 && <LoadingState />}
      {!error && !loading && codes.length === 0 && (
        <EmptyState label="No configurations yet." />
      )}
      {codes.map((code) => {
        const { system, custom } = groups.get(code)!;
        const customActive = published(custom);
        const systemActive = published(system);
        // New Draft starts from what is in use: the active custom configuration, else the System Default.
        const source =
          customActive ??
          systemActive ??
          custom?.versions[0] ??
          system?.versions[0];
        return (
          <section
            key={code}
            className="card"
            style={{ marginBottom: 14, padding: 0, overflow: "hidden" }}
            data-config-group={code}
          >
            <div
              style={{
                background: "#f9fafb",
                padding: "10px 14px",
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                gap: 10,
                flexWrap: "wrap",
              }}
            >
              <div>
                <strong>{label(code)}</strong>
                <div style={{ fontSize: 12, marginTop: 2 }} data-active-config>
                  Active:{" "}
                  {customActive ? (
                    <span style={{ color: "#1d4ed8", fontWeight: 600 }}>
                      Custom Configuration — {custom!.name} v
                      {customActive.version_number}
                    </span>
                  ) : systemActive ? (
                    <span style={{ color: "#15803d", fontWeight: 600 }}>
                      System Default v{systemActive.version_number}
                    </span>
                  ) : (
                    <span style={{ color: "#b91c1c" }}>none published</span>
                  )}
                </div>
              </div>
              {canManage && (
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                  {source && (
                    <button
                      className="btn-secondary"
                      data-new-draft={code}
                      onClick={() =>
                        onEdit({
                          code,
                          name: custom?.name ?? null,
                          defaultName: `${label(code)} (custom)`,
                          payload: source.payload,
                          versionId: null,
                          title: `New Draft — ${label(code)}`,
                        })
                      }
                    >
                      + New Draft
                    </button>
                  )}
                  {customActive && system && (
                    <button
                      className="btn-secondary"
                      data-restore-default={code}
                      onClick={() =>
                        act(
                          `/app/configuration/sets/${custom!.id}/restore-default`,
                        )
                      }
                    >
                      Return to System Default
                    </button>
                  )}
                </div>
              )}
            </div>
            <div style={{ overflowX: "auto" }}>
              <table
                style={{
                  width: "100%",
                  fontSize: 13,
                  borderCollapse: "collapse",
                }}
              >
                <tbody>
                  {system && (
                    <>
                      <tr>
                        <td
                          colSpan={4}
                          style={{
                            padding: "8px 12px 2px",
                            fontSize: 12,
                            color: "#6b7280",
                            fontWeight: 600,
                          }}
                        >
                          System Default
                          <InfoTip label="System Default">
                            Provided by the system and protected. It is used
                            whenever you have no published custom configuration.
                            Use New Draft to start your own from it.
                          </InfoTip>
                        </td>
                      </tr>
                      {system.versions
                        .filter((v) => v.status === "PUBLISHED")
                        .map((v) => versionRow(system, v, true))}
                    </>
                  )}
                  {custom && (
                    <>
                      <tr>
                        <td
                          colSpan={4}
                          style={{
                            padding: "8px 12px 2px",
                            fontSize: 12,
                            color: "#6b7280",
                            fontWeight: 600,
                          }}
                        >
                          Custom Configuration — {custom.name}
                          <InfoTip label="Custom Configuration">
                            Your own configuration. Its published version
                            replaces the System Default for new documents;
                            documents already created keep their number and
                            layout. Return to System Default stops using it (its
                            history is kept).
                          </InfoTip>
                        </td>
                      </tr>
                      {custom.versions.map((v) => versionRow(custom, v, false))}
                    </>
                  )}
                </tbody>
              </table>
            </div>
          </section>
        );
      })}
    </div>
  );
}
