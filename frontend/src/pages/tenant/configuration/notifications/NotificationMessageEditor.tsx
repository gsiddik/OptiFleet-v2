import { useRef, useState } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { FormField, inputStyle } from "../../../../components/FormField";
import { Modal } from "../../../../components/Modal";
import {
  VARIABLE_DRAG_TYPE,
  VariableTextEditor,
  type VariableTextEditorHandle,
} from "../../../../components/VariableTextEditor";
import type {
  NotificationMessagePayload,
  NotificationMetadata,
} from "../../../../types";
import type { EditorRequest } from "../DocumentConfigList";
import { t as tt } from '../../../../i18n/i18n';

type Channel = "IN_APP" | "EMAIL";
type Field = "IN_APP.body" | "EMAIL.subject" | "EMAIL.body";

/**
 * Notification message (a NOTIFICATION configuration): the In-App text and the Email subject /
 * text, written as plain text with variable cards (click or drag them in). Saved as the
 * existing {channels: {IN_APP: {body}, EMAIL: {subject, body}}}; the server checks the
 * variables and renders the preview.
 */
export function NotificationMessageEditor({
  meta,
  target,
  onClose,
  onSaved,
}: {
  meta: NotificationMetadata;
  target: EditorRequest;
  onClose: () => void;
  onSaved: () => void;
}) {
  const configurable = meta.events.filter((e) => !e.platform_locked);
  const initial = (target.payload as NotificationMessagePayload | null)
    ?.channels;
  const [code, setCode] = useState(target.code ?? configurable[0]?.code ?? "");
  const [name, setName] = useState(target.name ?? target.defaultName ?? "");
  const [changeSummary, setChangeSummary] = useState("");
  const [enabled, setEnabled] = useState<Record<Channel, boolean>>({
    IN_APP: initial ? !!initial.IN_APP : true,
    EMAIL: initial ? !!initial.EMAIL : false,
  });
  const [text, setText] = useState<Record<Field, string>>({
    "IN_APP.body": initial?.IN_APP?.body ?? "",
    "EMAIL.subject": initial?.EMAIL?.subject ?? "",
    "EMAIL.body": initial?.EMAIL?.body ?? "",
  });
  const [focused, setFocused] = useState<Field>("IN_APP.body");
  const [search, setSearch] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState<Record<
    string,
    { subject: string | null; body: string }
  > | null>(null);
  const editors = {
    "IN_APP.body": useRef<VariableTextEditorHandle>(null),
    "EMAIL.subject": useRef<VariableTextEditorHandle>(null),
    "EMAIL.body": useRef<VariableTextEditorHandle>(null),
  };

  const event = meta.events.find((e) => e.code === code);
  const variables = event?.variables ?? [];
  const shown = variables.filter((v) =>
    v.label.toLowerCase().includes(search.trim().toLowerCase()),
  );

  const payload = (): NotificationMessagePayload => ({
    channels: {
      ...(enabled.IN_APP ? { IN_APP: { body: text["IN_APP.body"] } } : {}),
      ...(enabled.EMAIL
        ? {
            EMAIL: {
              subject: text["EMAIL.subject"],
              body: text["EMAIL.body"],
            },
          }
        : {}),
    },
  });

  const problem = (): string | null => {
    if (!code) return tt('configuration.validation.chooseTheEvent');
    if (!name.trim()) return tt('configuration.validation.enterAName');
    if (!enabled.IN_APP && !enabled.EMAIL)
      return tt('configuration.validation.writeMessageLeastOneChannel');
    if (enabled.IN_APP && !text["IN_APP.body"].trim())
      return tt('configuration.help.writeAppMessage');
    if (enabled.EMAIL && !text["EMAIL.subject"].trim())
      return tt('configuration.help.writeTheEmailSubject');
    if (enabled.EMAIL && !text["EMAIL.body"].trim())
      return tt('configuration.help.writeTheEmailMessage');
    return null;
  };

  async function showPreview() {
    const p = problem();
    if (p) return setError(p);
    setError(null);
    try {
      const res = await apiClient.post("/app/configuration/preview", {
        type: "NOTIFICATION",
        code,
        payload: payload(),
      });
      setPreview(res.data.data.channels);
    } catch (e) {
      setError(extractApiError(e).message);
    }
  }

  async function save() {
    const p = problem();
    if (p) return setError(p);
    setError(null);
    setSaving(true);
    try {
      if (target.versionId)
        await apiClient.put(`/app/configuration/versions/${target.versionId}`, {
          payload: payload(),
          change_summary: changeSummary || null,
        });
      else
        await apiClient.post("/app/configuration/versions", {
          type: "NOTIFICATION",
          code,
          name: name.trim(),
          payload: payload(),
          change_summary: changeSummary || null,
        });
      onSaved();
    } catch (e) {
      setError(extractApiError(e).message);
    } finally {
      setSaving(false);
    }
  }

  const editor = (field: Field, label: string, multiline: boolean) => (
    <FormField label={label} required>
      <div onFocus={() => setFocused(field)}>
        <VariableTextEditor
          ref={editors[field]}
          value={text[field]}
          onChange={(v) => setText((t) => ({ ...t, [field]: v }))}
          variables={variables}
          multiline={multiline}
          ariaLabel={label}
        />
      </div>
    </FormField>
  );

  return (
    <Modal open title={target.title} onClose={onClose} width={980}>
      <div
        style={{ display: "flex", flexWrap: "wrap", gap: 20 }}
        className="notification-message-editor"
        data-notification-message-editor
      >
        <div style={{ flex: "1 1 420px", minWidth: 0 }}>
          <FormField label={tt('common.fields.event')} required>
            <select
              value={code}
              onChange={(e) => setCode(e.target.value)}
              style={inputStyle}
              disabled={!!target.code}
              aria-label={tt('common.fields.event')}
            >
              {(target.code ? meta.events : configurable).map((e) => (
                <option key={e.code} value={e.code}>
                  {e.label}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label={tt('common.fields.name')} required>
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              style={inputStyle}
              disabled={!!target.versionId}
              aria-label={tt('common.fields.name')}
            />
          </FormField>
          <label style={{ fontSize: 14, display: "block", margin: "6px 0" }}>
            <input
              type="checkbox"
              checked={enabled.IN_APP}
              onChange={(e) =>
                setEnabled({ ...enabled, IN_APP: e.target.checked })
              }
            />{" "}
            {tt('configuration.fields.inAppMessage')}
          </label>
          {enabled.IN_APP && editor("IN_APP.body", tt('configuration.fields.inAppMessage2'), true)}
          <label style={{ fontSize: 14, display: "block", margin: "6px 0" }}>
            <input
              type="checkbox"
              checked={enabled.EMAIL}
              onChange={(e) =>
                setEnabled({ ...enabled, EMAIL: e.target.checked })
              }
            />{" "}
            {tt('common.fields.email')}
          </label>
          {enabled.EMAIL && (
            <>
              {editor("EMAIL.subject", tt('configuration.fields.emailSubject2'), false)}
              {editor("EMAIL.body", tt('configuration.fields.emailMessage'), true)}
            </>
          )}
          <FormField label={tt('configuration.fields.changeSummary')}>
            <input
              value={changeSummary}
              onChange={(e) => setChangeSummary(e.target.value)}
              style={inputStyle}
              placeholder={tt('configuration.placeholders.whatChangedOptional')}
              aria-label={tt('configuration.fields.changeSummary')}
            />
          </FormField>
        </div>
        <aside
          aria-label={tt('configuration.fields.variables')}
          style={{ flex: "1 1 200px", maxWidth: 360 }}
        >
          <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 6 }}>
            {tt('configuration.fields.variables')}
          </div>
          <p style={{ fontSize: 12, color: "#6b7280", margin: "0 0 8px" }}>
            {tt('configuration.help.clickAddCursorFieldYouWriting')}
          </p>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={tt('configuration.fields.searchVariables')}
            aria-label={tt('configuration.fields.searchVariables')}
            style={{ ...inputStyle, marginBottom: 8 }}
          />
          <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
            {shown.map((v) => (
              <button
                key={v.key}
                type="button"
                className="btn-secondary"
                style={{ textAlign: "left", fontSize: 13 }}
                draggable
                onDragStart={(e) => {
                  e.dataTransfer.setData(VARIABLE_DRAG_TYPE, v.key);
                  e.dataTransfer.effectAllowed = "copy";
                }}
                onClick={() => editors[focused].current?.insertVariable(v.key)}
                data-message-variable={v.key}
              >
                + {v.label}
              </button>
            ))}
          </div>
        </aside>
      </div>
      {error && (
        <div
          role="alert"
          style={{ color: "#b91c1c", fontSize: 13, marginTop: 10 }}
          data-message-error
        >
          {error}
        </div>
      )}
      {preview && (
        <div
          className="card"
          style={{ marginTop: 12, padding: 12, fontSize: 13 }}
          data-message-preview
        >
          <strong>{tt('configuration.help.previewWithSampleValues')}</strong>
          {preview.IN_APP && (
            <div style={{ marginTop: 8 }}>
              <div style={{ color: "#6b7280", fontSize: 12 }}>{tt('configuration.fields.inApp')}</div>
              <div style={{ whiteSpace: "pre-wrap" }}>
                {preview.IN_APP.body}
              </div>
            </div>
          )}
          {preview.EMAIL && (
            <div style={{ marginTop: 8 }}>
              <div style={{ color: "#6b7280", fontSize: 12 }}>{tt('common.fields.email')}</div>
              <div style={{ fontWeight: 600 }}>{preview.EMAIL.subject}</div>
              <div style={{ whiteSpace: "pre-wrap" }}>{preview.EMAIL.body}</div>
            </div>
          )}
        </div>
      )}
      <div
        style={{
          display: "flex",
          justifyContent: "flex-end",
          gap: 8,
          marginTop: 16,
        }}
      >
        <button className="btn-secondary" onClick={onClose}>
          {tt('common.actions.cancel')}
        </button>
        <button className="btn-secondary" onClick={showPreview}>
          {tt('configuration.actions.preview')}
        </button>
        <button className="btn-primary" disabled={saving} onClick={save}>
          {tt('platform.contracts.actions.saveDraft')}
        </button>
      </div>
    </Modal>
  );
}
