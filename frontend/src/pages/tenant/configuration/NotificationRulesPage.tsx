import { useEffect, useState } from "react";
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
  DocumentTypeOption,
  NotificationMessagePayload,
  NotificationMetadata,
  NotificationRuleItem,
} from "../../../types";
import { DocumentConfigList, type EditorRequest } from "./DocumentConfigList";
import { describeConditions } from "./notifications/conditions";
import { NotificationMessageEditor } from "./notifications/NotificationMessageEditor";
import { NotificationRuleModal } from "./notifications/NotificationRuleModal";
import type { RecipientLookups } from "./notifications/RecipientListEditor";

type Tab = "rules" | "messages";

/**
 * Configuration → Notification. Rules: when an event happens, who is notified, by which
 * channel, under which condition, and whom to escalate to — a form over the existing
 * NotificationRule (not versioned; System Default rules are read-only). Messages: the text
 * sent per event (versioned NOTIFICATION configuration with System Default / Custom, Draft →
 * Publish, Return to System Default).
 */
export function NotificationRulesPage() {
  const { hasPermission } = useAuth();
  const [tab, setTab] = useState<Tab>("rules");
  const [meta, setMeta] = useState<NotificationMetadata | null>(null);
  const [lookups, setLookups] = useState<RecipientLookups>({
    users: null,
    roles: null,
    permissions: null,
  });
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [editingRule, setEditingRule] = useState<
    NotificationRuleItem | "new" | null
  >(null);
  const [editingMessage, setEditingMessage] = useState<EditorRequest | null>(
    null,
  );
  const [preview, setPreview] = useState<{
    code: string;
    channels: Record<string, { subject: string | null; body: string }>;
  } | null>(null);
  const {
    data: rules,
    loading,
    error: listError,
  } = useApiList<NotificationRuleItem>(
    "/app/notification-rules",
    {},
    reloadKey,
  );
  const canManage = hasPermission("notification_rule.manage");

  useEffect(() => {
    apiClient
      .get("/app/configuration/metadata", { params: { type: "NOTIFICATION" } })
      .then((res) => setMeta(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
    // Pickers for "Specific User / Role / Permission" recipients, where the user may list them;
    // otherwise those recipients are typed.
    const load = (url: string, key: keyof RecipientLookups) =>
      apiClient
        .get(url, { params: { per_page: 200 } })
        .then((res) => setLookups((l) => ({ ...l, [key]: res.data.data })))
        .catch(() => undefined);
    if (hasPermission("user.view")) load("/app/users", "users");
    if (hasPermission("role.view")) {
      load("/app/roles", "roles");
      load("/app/permissions", "permissions");
    }
  }, [hasPermission]);

  if (error && !meta) return <ErrorState message={error} />;
  if (!meta) return <LoadingState />;

  const eventLabel = (code: string) =>
    meta.events.find((e) => e.code === code)?.label ?? code;
  const recipientSummary = (rule: NotificationRuleItem) =>
    rule.recipient_rules
      .map((r) => {
        const type = meta.recipient_types.find((t) => t.value === r.type);
        const who =
          type?.identifier === "user"
            ? (lookups.users?.find((u) => u.user_id === r.identifier)?.name ??
              "a user")
            : r.identifier;
        return who
          ? `${type?.label ?? r.type}: ${who}`
          : (type?.label ?? r.type);
      })
      .join(", ");
  const channelLabel = (c: string) =>
    meta.channels.find((x) => x.value === c)?.label ?? c;

  async function toggleActive(rule: NotificationRuleItem) {
    try {
      await apiClient.post(
        `/app/notification-rules/${rule.id}/${rule.is_active ? "deactivate" : "activate"}`,
      );
      setReloadKey((k) => k + 1);
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  async function previewMessage(
    code: string,
    payload: Record<string, unknown>,
  ) {
    try {
      const res = await apiClient.post("/app/configuration/preview", {
        type: "NOTIFICATION",
        code,
        payload,
      });
      setPreview({ code, channels: res.data.data.channels });
    } catch (err) {
      setError(extractApiError(err).message);
    }
  }

  const messageTypes: DocumentTypeOption[] = meta.events.map((e) => ({
    key: e.code,
    label: e.label,
    numbering: false,
    template: true,
    extra_tokens: [],
  }));

  const tabButton = (value: Tab, label: string) => (
    <button
      role="tab"
      aria-selected={tab === value}
      onClick={() => setTab(value)}
      style={{
        padding: "8px 16px",
        fontSize: 14,
        fontWeight: 600,
        background: "none",
        border: "none",
        borderBottom:
          tab === value ? "2px solid #1d4ed8" : "2px solid transparent",
        color: tab === value ? "#1d4ed8" : "#6b7280",
        cursor: "pointer",
      }}
    >
      {label}
    </button>
  );

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 4 }}>Notification</h1>
      <p style={{ color: "#6b7280", fontSize: 13, marginBottom: 16 }}>
        Choose who is told when something happens (Rules) and what they read
        (Messages). Subscription and billing notifications are managed by the
        platform.
      </p>
      <div
        role="tablist"
        aria-label="Notification configuration"
        style={{
          display: "flex",
          marginBottom: 14,
          borderBottom: "1px solid #e5e7eb",
          flexWrap: "wrap",
        }}
      >
        {tabButton("rules", "Rules")}
        {tabButton("messages", "Messages")}
      </div>
      {error && <ErrorState message={error} />}

      {tab === "rules" && (
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
                onClick={() => setEditingRule("new")}
                data-new-rule
              >
                + New Rule
              </button>
            </div>
          )}
          {listError && <ErrorState message={listError} />}
          {!listError && loading && <LoadingState />}
          {!listError && !loading && rules.length === 0 && (
            <EmptyState label="No notification rules configured." />
          )}
          {!listError && !loading && rules.length > 0 && (
            <div style={{ overflowX: "auto" }}>
              <table
                style={{
                  width: "100%",
                  fontSize: 13,
                  borderCollapse: "collapse",
                  border: "1px solid #e5e7eb",
                }}
              >
                <thead>
                  <tr style={{ background: "#f9fafb", textAlign: "left" }}>
                    <th style={{ padding: 10 }}>Event</th>
                    <th style={{ padding: 10 }}>Name</th>
                    <th style={{ padding: 10 }}>Recipients</th>
                    <th style={{ padding: 10 }}>Send by</th>
                    <th style={{ padding: 10 }}>Escalation</th>
                    <th style={{ padding: 10 }}>Status</th>
                    <th style={{ padding: 10 }} />
                  </tr>
                </thead>
                <tbody>
                  {rules.map((rule) => {
                    const fields =
                      meta.events.find((e) => e.code === rule.event_code)
                        ?.variables ?? [];
                    const when = describeConditions(
                      rule.condition_set,
                      fields,
                      meta.operators,
                    );
                    return (
                      <tr
                        key={rule.id}
                        style={{ borderTop: "1px solid #f1f5f9" }}
                        data-rule={rule.name}
                      >
                        <td style={{ padding: 10 }}>
                          {eventLabel(rule.event_code)}
                        </td>
                        <td style={{ padding: 10 }}>
                          {rule.name}
                          {rule.is_system && (
                            <span
                              style={{
                                marginLeft: 6,
                                fontSize: 11,
                                color: "#15803d",
                                fontWeight: 600,
                              }}
                            >
                              System Default
                              <InfoTip label="System Default rule">
                                Provided by the system and protected. Add your
                                own rule to notify other people.
                              </InfoTip>
                            </span>
                          )}
                          {when && (
                            <div style={{ fontSize: 12, color: "#6b7280" }}>
                              Only when {when}
                            </div>
                          )}
                        </td>
                        <td style={{ padding: 10 }}>
                          {recipientSummary(rule)}
                        </td>
                        <td style={{ padding: 10 }}>
                          {rule.channels.map(channelLabel).join(", ")}
                        </td>
                        <td style={{ padding: 10 }}>
                          {rule.escalation
                            ? `after ${rule.escalation.after_minutes} min`
                            : "—"}
                        </td>
                        <td style={{ padding: 10 }}>
                          <StatusBadge
                            status={rule.is_active ? "ACTIVE" : "INACTIVE"}
                          />
                        </td>
                        <td
                          style={{
                            padding: 10,
                            textAlign: "right",
                            whiteSpace: "nowrap",
                          }}
                        >
                          <button
                            className="btn-secondary"
                            style={{ marginRight: 6 }}
                            onClick={() => setEditingRule(rule)}
                          >
                            {canManage && !rule.is_system ? "Edit" : "View"}
                          </button>
                          {canManage && !rule.is_system && (
                            <button
                              className="btn-secondary"
                              onClick={() => toggleActive(rule)}
                            >
                              {rule.is_active ? "Deactivate" : "Activate"}
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {tab === "messages" && (
        <DocumentConfigList
          type="NOTIFICATION"
          managePermission="document_template.manage"
          publishPermission="document_template.publish"
          documentTypes={messageTypes}
          reloadKey={reloadKey}
          newLabel="New Event Message"
          describe={(payload) => {
            const channels = (payload as unknown as NotificationMessagePayload)
              .channels;
            return (
              <span style={{ color: "#374151" }}>
                {channels?.EMAIL?.subject
                  ? `Email: ${channels.EMAIL.subject}`
                  : (channels?.IN_APP?.body ?? "")}
              </span>
            );
          }}
          onEdit={setEditingMessage}
          onPreview={previewMessage}
        />
      )}

      {editingRule && (
        <NotificationRuleModal
          meta={meta}
          lookups={lookups}
          rule={editingRule === "new" ? null : editingRule}
          onClose={() => setEditingRule(null)}
          onSaved={() => {
            setEditingRule(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {editingMessage && (
        <NotificationMessageEditor
          meta={meta}
          target={editingMessage}
          onClose={() => setEditingMessage(null)}
          onSaved={() => {
            setEditingMessage(null);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
      {preview && (
        <div
          role="dialog"
          aria-label="Message preview"
          className="card"
          style={{
            position: "fixed",
            right: 16,
            bottom: 16,
            maxWidth: "min(480px, calc(100vw - 32px))",
            padding: 14,
            zIndex: 60,
            boxShadow: "0 10px 30px rgba(0,0,0,0.2)",
            fontSize: 13,
          }}
          data-message-preview
        >
          <div style={{ display: "flex", justifyContent: "space-between" }}>
            <strong>Preview — {eventLabel(preview.code)}</strong>
            <button
              onClick={() => setPreview(null)}
              aria-label="Close preview"
              style={{ background: "none", border: "none", cursor: "pointer" }}
            >
              ×
            </button>
          </div>
          {Object.entries(preview.channels).map(([channel, content]) => (
            <div key={channel} style={{ marginTop: 8 }}>
              <div style={{ color: "#6b7280", fontSize: 12 }}>
                {channelLabel(channel)}
              </div>
              {content.subject && (
                <div style={{ fontWeight: 600 }}>{content.subject}</div>
              )}
              <div style={{ whiteSpace: "pre-wrap" }}>{content.body}</div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
