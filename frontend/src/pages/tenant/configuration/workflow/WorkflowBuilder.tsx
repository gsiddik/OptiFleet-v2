import {
  Background,
  MarkerType,
  MiniMap,
  ReactFlow,
  ReactFlowProvider,
  applyNodeChanges,
  useReactFlow,
  type Connection,
  type Edge,
  type EdgeChange,
  type NodeChange,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { useCallback, useEffect, useMemo, useState } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { inputStyle } from "../../../../components/FormField";
import { useAuth } from "../../../../auth/AuthContext";
import type {
  ConfigurationSetItem,
  WorkflowIssue,
  WorkflowLayoutData,
  WorkflowMetadata,
  WorkflowPayload,
  WorkflowStatusDef,
} from "../../../../types";
import type { EditorRequest } from "../DocumentConfigList";
import { StateConfigurationPanel } from "./StateConfigurationPanel";
import { TransitionConfigurationPanel } from "./TransitionConfigurationPanel";
import { WorkflowNode, type WorkflowFlowNode } from "./WorkflowNode";
import { WorkflowValidationPanel } from "./WorkflowValidationPanel";
import {
  autoLayout,
  defaultActionCode,
  freeSpot,
  labelOf,
  newTransitionId,
  toEditable,
  toPayload,
  type EditableTransition,
  type Positions,
} from "./workflowGraph";
import { t as tt } from '../../../../i18n/i18n';

export type WorkflowBuilderMode = "edit" | "layout" | "view";

export interface WorkflowBuilderTarget extends EditorRequest {
  /** edit: a draft or a new draft; layout: a published custom version (card positions only); view: read-only. */
  mode: WorkflowBuilderMode;
}

const nodeTypes = { status: WorkflowNode };

type Selection =
  | { kind: "status"; code: string }
  | { kind: "transition"; id: string }
  | null;

/**
 * Visual Workflow Builder. Statuses are cards, transitions are arrows: drag cards to arrange
 * them, drag from a card's right dot to another card to add a transition, drag either end of an
 * arrow to another card to change where it starts or ends, select and press Delete to remove.
 * Everything edits the workflow definition the runtime enforces (statuses + transitions); card
 * positions are saved separately as the version's layout.
 */
export function WorkflowBuilder(props: {
  target: WorkflowBuilderTarget;
  onClose: () => void;
  onSaved: () => void;
}) {
  return (
    <ReactFlowProvider>
      <Builder {...props} />
    </ReactFlowProvider>
  );
}

function Builder({
  target,
  onClose,
  onSaved,
}: {
  target: WorkflowBuilderTarget;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { hasPermission } = useAuth();
  const flow = useReactFlow();
  const readOnly = target.mode !== "edit";
  const [code, setCode] = useState<string>(target.code ?? "");
  const [name, setName] = useState(target.name ?? target.defaultName ?? "");
  const [changeSummary, setChangeSummary] = useState("");
  const [meta, setMeta] = useState<WorkflowMetadata | null>(null);
  const [permissions, setPermissions] = useState<Array<{
    name: string;
    description: string | null;
  }> | null>(null);
  const initial = useMemo(
    () => toEditable(target.payload as WorkflowPayload | null),
    [target.payload],
  );
  const [statuses, setStatuses] = useState<WorkflowStatusDef[]>(
    initial.statuses,
  );
  const [transitions, setTransitions] = useState<EditableTransition[]>(
    initial.transitions,
  );
  const [extra] = useState(initial.extra);
  // Card positions (and React Flow's measured sizes / selection) by status code.
  const [placed, setPlaced] = useState<
    Record<string, Pick<WorkflowFlowNode, "position" | "measured" | "selected">>
  >({});
  const [savedPositions, setSavedPositions] = useState<Positions | null>(null);
  const [savedViewport, setSavedViewport] =
    useState<WorkflowLayoutData["viewport"]>(null);
  const layoutVersion = target.versionId ?? target.sourceVersionId ?? null;
  const [ready, setReady] = useState(!layoutVersion);
  const [selection, setSelection] = useState<Selection>(null);
  const [validation, setValidation] = useState<{
    errors: WorkflowIssue[];
    warnings: WorkflowIssue[];
  } | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [addStatus, setAddStatus] = useState("");
  const [newStatusCode, setNewStatusCode] = useState("");

  const catalog = meta?.catalog ?? null;
  const targets = catalog?.targets ?? null;

  // Metadata (catalog + actions) for the resource type; permission names for the pickers.
  useEffect(() => {
    if (!code) return;
    apiClient
      .get("/app/configuration/metadata", {
        params: { type: "WORKFLOW", code },
      })
      .then((res) => setMeta(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [code]);
  useEffect(() => {
    if (!target.code)
      apiClient
        .get("/app/configuration/metadata", { params: { type: "WORKFLOW" } })
        .then((res) => setMeta(res.data.data))
        .catch(() => undefined);
    if (hasPermission("role.view"))
      apiClient
        .get("/app/permissions")
        .then((res) => setPermissions(res.data.data))
        .catch(() => undefined);
  }, [target.code, hasPermission]);

  // A new workflow starts from the resource type's System Default.
  useEffect(() => {
    if (target.payload || !code) return;
    apiClient
      .get("/app/configuration/sets", {
        params: { type: "WORKFLOW", per_page: 200 },
      })
      .then((res) => {
        const system = (res.data.data as ConfigurationSetItem[]).find(
          (s) => s.code === code && s.tenant_id === null,
        );
        const published = system?.versions.find(
          (v) => v.status === "PUBLISHED",
        );
        const loaded = toEditable(
          (published?.payload as WorkflowPayload | undefined) ?? {
            statuses: [],
            transitions: [],
          },
        );
        setStatuses(loaded.statuses);
        setTransitions(loaded.transitions);
        setPlaced({});
        setSavedPositions(null);
        setTimeout(() => flow.fitView({ padding: 0.2 }), 100);
      });
  }, [code, target.payload, flow]);

  // Saved layout of this version (or of the version a new draft starts from).
  useEffect(() => {
    if (!layoutVersion) return;
    apiClient
      .get(`/app/configuration/versions/${layoutVersion}/layout`)
      .then((res) => {
        const layout = res.data.data as WorkflowLayoutData;
        setSavedPositions(layout.positions);
        setSavedViewport(layout.viewport);
      })
      .catch(() => setSavedPositions(null))
      .finally(() => setReady(true));
  }, [layoutVersion]);

  // Server-side validation of the current graph, shown on the cards / arrows and in the panel.
  const payload = useMemo(
    () => toPayload(statuses, transitions, extra),
    [statuses, transitions, extra],
  );
  useEffect(() => {
    if (!code || statuses.length === 0) return;
    const timer = setTimeout(() => {
      apiClient
        .post("/app/configuration/workflow/validate", { code, payload })
        .then((res) => setValidation(res.data.data))
        .catch(() => undefined);
    }, 400);
    return () => clearTimeout(timer);
  }, [code, payload, statuses.length]);

  const issuesFor = useCallback(
    (statusCode: string, kind: "errors" | "warnings") =>
      (validation?.[kind] ?? [])
        .filter((i) => i.status === statusCode)
        .map((i) => i.message),
    [validation],
  );
  const transitionProblem = useCallback(
    (index: number) =>
      (validation?.errors ?? []).some((i) => i.transition === index),
    [validation],
  );

  // Cards, derived from the workflow: a card keeps the position it was given (moved, saved
  // layout, or the deterministic auto-layout for a workflow without one).
  const auto = useMemo(
    () => autoLayout(statuses, transitions),
    [statuses, transitions],
  );
  const nodes: WorkflowFlowNode[] = statuses.map((s) => ({
    id: s.code,
    type: "status",
    position: placed[s.code]?.position ??
      savedPositions?.[s.code] ??
      auto[s.code] ?? { x: 0, y: 0 },
    measured: placed[s.code]?.measured,
    selected: placed[s.code]?.selected,
    draggable: target.mode !== "view",
    data: {
      label: labelOf(s),
      code: s.code,
      isStart: !!s.is_start,
      isEnd: !transitions.some((t) => t.from_status === s.code),
      errors: issuesFor(s.code, "errors"),
      warnings: issuesFor(s.code, "warnings"),
    },
  }));
  const remember = (next: WorkflowFlowNode[]) =>
    setPlaced(
      Object.fromEntries(
        next.map((n) => [
          n.id,
          { position: n.position, measured: n.measured, selected: n.selected },
        ]),
      ),
    );

  const edges: Edge[] = transitions.map((t, index) => ({
    id: t._id,
    source: t.from_status,
    target: t.to_status,
    label: t.action_label || t.action_code,
    reconnectable: !readOnly,
    selected: selection?.kind === "transition" && selection.id === t._id,
    markerEnd: {
      type: MarkerType.ArrowClosed,
      width: 18,
      height: 18,
      color: transitionProblem(index) ? "#dc2626" : "#475569",
    },
    style: {
      stroke: transitionProblem(index) ? "#dc2626" : "#475569",
      strokeWidth: 1.6,
    },
    labelStyle: { fontSize: 11, fill: "#1f2937" },
    labelBgStyle: { fill: "#ffffff" },
    labelBgPadding: [4, 2] as [number, number],
    ariaLabel: tt('configuration.fields.transitionValueStatusStatus', { value: t.action_label || t.action_code, from_status: t.from_status, to_status: t.to_status }),
    data: { from: t.from_status, to: t.to_status },
  }));

  const touched = () => {
    setDirty(true);
    setMessage(null);
  };

  /** Why a transition between two statuses is not allowed (null when it is). */
  const connectionProblem = (
    from: string,
    to: string,
    ignoreId?: string,
  ): string | null => {
    if (!from || !to) return tt('configuration.validation.chooseBothStatuses');
    if (from === to)
      return tt('configuration.help.transitionCannotStartEndSameStatus');
    if (targets && !targets.includes(to))
      return tt('configuration.empty.noActionOptiFleetMovesDocument', { to: to });
    if (
      transitions.some(
        (t) =>
          t._id !== ignoreId && t.from_status === from && t.to_status === to,
      )
    )
      return tt('configuration.help.thereAlreadyTransition', { from: from, to: to });
    return null;
  };

  const uniqueActionCode = (from: string, to: string, ignoreId?: string) => {
    const base = defaultActionCode(to);
    let candidate = base;
    let n = 2;
    while (
      transitions.some(
        (t) =>
          t._id !== ignoreId &&
          t.from_status === from &&
          t.action_code === candidate,
      )
    )
      candidate = `${base}_${n++}`;
    return candidate;
  };

  const addTransition = (from: string, to: string) => {
    const problem = connectionProblem(from, to);
    if (problem) return setMessage(problem);
    const toStatus = statuses.find((s) => s.code === to);
    const t: EditableTransition = {
      _id: newTransitionId(),
      from_status: from,
      to_status: to,
      action_code: uniqueActionCode(from, to),
      action_label: toStatus ? labelOf(toStatus) : to,
    };
    setTransitions((ts) => [...ts, t]);
    setSelection({ kind: "transition", id: t._id });
    touched();
  };

  const updateTransition = (next: EditableTransition) => {
    const current = transitions.find((t) => t._id === next._id);
    if (
      current &&
      (current.from_status !== next.from_status ||
        current.to_status !== next.to_status)
    ) {
      const problem = connectionProblem(
        next.from_status,
        next.to_status,
        next._id,
      );
      if (problem) return setMessage(problem);
      if (
        current.from_status !== next.from_status &&
        transitions.some(
          (t) =>
            t._id !== next._id &&
            t.from_status === next.from_status &&
            t.action_code === next.action_code,
        )
      )
        next = {
          ...next,
          action_code: uniqueActionCode(
            next.from_status,
            next.to_status,
            next._id,
          ),
        };
    }
    setTransitions((ts) => ts.map((t) => (t._id === next._id ? next : t)));
    touched();
  };

  const removeTransitions = (ids: string[]) => {
    setTransitions((ts) => ts.filter((t) => !ids.includes(t._id)));
    setSelection(null);
    touched();
  };

  const removeStatuses = (codes: string[]) => {
    setStatuses((ss) => ss.filter((s) => !codes.includes(s.code)));
    setTransitions((ts) =>
      ts.filter(
        (t) => !codes.includes(t.from_status) && !codes.includes(t.to_status),
      ),
    );
    setPlaced((p) =>
      Object.fromEntries(Object.entries(p).filter(([c]) => !codes.includes(c))),
    );
    setSelection(null);
    touched();
  };

  /** Delete / Backspace on the canvas removes the selected arrow or status (confirmed). */
  const deleteSelection = () => {
    if (readOnly || !selection) return;
    if (selection.kind === "transition") {
      if (
        window.confirm(
          tt('configuration.confirm.removeTransitionDraftActiveWorkflowChanges'),
        )
      )
        removeTransitions([selection.id]);
    } else if (
      window.confirm(
        tt('configuration.confirm.removeCodeTransitionsDraftActiveWorkflow', { code: selection.code }),
      )
    ) {
      removeStatuses([selection.code]);
    }
  };

  const onNodesChange = (changes: NodeChange<WorkflowFlowNode>[]) => {
    remember(
      applyNodeChanges(
        changes.filter((c) => c.type !== "remove"),
        nodes,
      ),
    );
    if (changes.some((c) => c.type === "position" && c.dragging === false))
      setDirty(true);
    const selected = changes.find((c) => c.type === "select" && c.selected);
    if (selected && "id" in selected)
      setSelection({ kind: "status", code: selected.id });
    // Delete key (after onBeforeDelete confirmed): the status and its transitions leave the draft.
    const removed = changes.flatMap((c) => (c.type === "remove" ? [c.id] : []));
    if (removed.length) removeStatuses(removed);
  };

  const onEdgesChange = (changes: EdgeChange[]) => {
    const selected = changes.find((c) => c.type === "select" && c.selected);
    if (selected && "id" in selected)
      setSelection({ kind: "transition", id: selected.id });
    const removed = changes.flatMap((c) => (c.type === "remove" ? [c.id] : []));
    if (removed.length) removeTransitions(removed);
  };

  const onReconnect = (old: Edge, connection: Connection) => {
    const current = transitions.find((t) => t._id === old.id);
    if (!current || !connection.source || !connection.target) return;
    updateTransition({
      ...current,
      from_status: connection.source,
      to_status: connection.target,
    });
  };

  // Unsaved changes: warn before leaving the page.
  useEffect(() => {
    if (!dirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [dirty]);

  const close = () => {
    if (dirty && !window.confirm(tt('configuration.confirm.leaveWithoutSavingChanges'))) return;
    onClose();
  };

  const positions = (): Positions =>
    Object.fromEntries(
      nodes.map((n) => [n.id, { x: n.position.x, y: n.position.y }]),
    );

  async function saveLayout(versionId: string) {
    await apiClient.put(`/app/configuration/versions/${versionId}/layout`, {
      positions: positions(),
      viewport: flow.getViewport(),
    });
  }

  async function save(publish: boolean) {
    setError(null);
    if (!code) return setError(tt('configuration.validation.chooseTheDocumentType2'));
    if (!name.trim() && !target.versionId) return setError(tt('configuration.validation.enterAName'));
    if (publish && validation && validation.errors.length > 0)
      return setError(tt('configuration.errors.fixErrorsBeforePublishing'));
    if (
      publish &&
      !window.confirm(
        tt('configuration.confirm.publishWorkflowDocumentsCreatedNowFollow'),
      )
    )
      return;
    setSaving(true);
    try {
      let versionId = target.versionId;
      if (target.mode === "edit") {
        if (versionId)
          await apiClient.put(`/app/configuration/versions/${versionId}`, {
            payload,
            change_summary: changeSummary || null,
          });
        else
          versionId = (
            await apiClient.post("/app/configuration/versions", {
              type: "WORKFLOW",
              code,
              name: name.trim(),
              payload,
              change_summary: changeSummary || null,
            })
          ).data.data.id as string;
      }
      if (versionId) await saveLayout(versionId);
      if (publish && versionId)
        await apiClient.post(
          `/app/configuration/versions/${versionId}/publish`,
        );
      setDirty(false);
      onSaved();
    } catch (e) {
      setError(extractApiError(e).message);
    } finally {
      setSaving(false);
    }
  }

  const applyAutoLayout = () => {
    remember(nodes.map((n) => ({ ...n, position: auto[n.id] ?? n.position })));
    setDirty(true);
    setTimeout(() => flow.fitView({ padding: 0.2 }), 50);
  };
  const resetLayout = () => {
    remember(
      nodes.map((n) => ({
        ...n,
        position: savedPositions?.[n.id] ?? auto[n.id] ?? n.position,
      })),
    );
    setTimeout(() => flow.fitView({ padding: 0.2 }), 50);
  };

  const missingStatuses = (catalog?.statuses ?? []).filter(
    (s) => !statuses.some((x) => x.code === s.code),
  );
  const addCatalogStatus = (statusCode: string) => {
    const def = catalog?.statuses.find((s) => s.code === statusCode);
    const statusDef: WorkflowStatusDef = {
      code: statusCode,
      display_name: def?.display_name ?? statusCode,
      is_start: false,
    };
    setStatuses((ss) => [...ss, statusDef]);
    // A new card goes to a free spot right of the others.
    setPlaced((p) => ({
      ...p,
      [statusCode]: {
        position: freeSpot(
          Object.fromEntries(nodes.map((n) => [n.id, n.position])),
        ),
      },
    }));
    setSelection({ kind: "status", code: statusCode });
    touched();
  };

  const selectedStatus =
    selection?.kind === "status"
      ? statuses.find((s) => s.code === selection.code)
      : undefined;
  const selectedTransition =
    selection?.kind === "transition"
      ? transitions.find((t) => t._id === selection.id)
      : undefined;
  const canPublish = hasPermission("workflow.publish");
  const resourceName =
    meta?.resource_types.find((r) => r.code === code)?.name ?? code;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={target.title}
      data-workflow-builder
      style={{
        position: "fixed",
        inset: 0,
        zIndex: 60,
        background: "#f8fafc",
        display: "flex",
        flexDirection: "column",
      }}
    >
      <header
        style={{
          display: "flex",
          alignItems: "center",
          gap: 10,
          padding: "10px 16px",
          background: "#fff",
          borderBottom: "1px solid #e5e7eb",
          flexWrap: "wrap",
        }}
      >
        <strong style={{ fontSize: 16, marginRight: 8 }}>{target.title}</strong>
        {target.code ? (
          <span style={{ fontSize: 13, color: "#475569" }}>{resourceName}</span>
        ) : (
          <select
            value={code}
            onChange={(e) => setCode(e.target.value)}
            style={{ ...inputStyle, width: 220 }}
            aria-label={tt('configuration.fields.documentType2')}
          >
            <option value="">{tt('configuration.fields.chooseDocumentType')}</option>
            {(meta?.resource_types ?? []).map((r) => (
              <option key={r.code} value={r.code}>
                {r.name}
              </option>
            ))}
          </select>
        )}
        {target.mode === "edit" && !target.versionId && (
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={tt('configuration.placeholders.name')}
            aria-label={tt('configuration.placeholders.name')}
            style={{ ...inputStyle, width: 220 }}
          />
        )}
        {target.mode === "edit" && (
          <input
            value={changeSummary}
            onChange={(e) => setChangeSummary(e.target.value)}
            placeholder={tt('configuration.placeholders.changeSummaryOptional')}
            aria-label={tt('configuration.fields.changeSummary2')}
            style={{ ...inputStyle, width: 220 }}
          />
        )}
        <span style={{ flex: 1 }} />
        {dirty && (
          <span style={{ fontSize: 12, color: "#b45309" }} data-unsaved>
            {tt('configuration.warnings.unsavedChanges')}
          </span>
        )}
        <button className="btn-secondary" onClick={close}>
          {readOnly && !dirty ? tt('common.actions.close') : tt('common.actions.cancel')}
        </button>
        {target.mode === "layout" && (
          <button
            className="btn-primary"
            disabled={saving}
            onClick={() => save(false)}
          >
            {tt('configuration.actions.saveLayout')}
          </button>
        )}
        {target.mode === "edit" && (
          <>
            <button
              className="btn-secondary"
              disabled={saving}
              onClick={() => save(false)}
            >
              {tt('platform.contracts.actions.saveDraft')}
            </button>
            {canPublish && (
              <button
                className="btn-primary"
                disabled={saving}
                onClick={() => save(true)}
              >
                {tt('configuration.actions.saveAndPublish')}
              </button>
            )}
          </>
        )}
      </header>

      <div
        role="toolbar"
        aria-label={tt('configuration.tooltips.workflowTools')}
        style={{
          display: "flex",
          gap: 6,
          padding: "8px 16px",
          background: "#fff",
          borderBottom: "1px solid #e5e7eb",
          flexWrap: "wrap",
          alignItems: "center",
        }}
      >
        {target.mode === "edit" && catalog && (
          <>
            <select
              value={addStatus}
              onChange={(e) => setAddStatus(e.target.value)}
              style={{ ...inputStyle, width: 200 }}
              aria-label={tt('configuration.fields.statusToAdd')}
              disabled={missingStatuses.length === 0}
            >
              <option value="">
                {missingStatuses.length
                  ? tt('configuration.fields.addState')
                  : tt('configuration.filters.allStatusesWorkflow')}
              </option>
              {missingStatuses.map((s) => (
                <option key={s.code} value={s.code}>
                  {s.display_name}
                </option>
              ))}
            </select>
            <button
              className="btn-secondary"
              disabled={!addStatus}
              onClick={() => {
                addCatalogStatus(addStatus);
                setAddStatus("");
              }}
            >
              {tt('configuration.actions.addState')}
            </button>
          </>
        )}
        {target.mode === "edit" && !catalog && code && (
          <>
            <input
              value={newStatusCode}
              onChange={(e) =>
                setNewStatusCode(
                  e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, "_"),
                )
              }
              placeholder="NEW_STATUS"
              aria-label={tt('configuration.fields.newStatusCode')}
              style={{ ...inputStyle, width: 160 }}
            />
            <button
              className="btn-secondary"
              disabled={
                !newStatusCode || statuses.some((s) => s.code === newStatusCode)
              }
              onClick={() => {
                addCatalogStatus(newStatusCode);
                setNewStatusCode("");
              }}
            >
              {tt('configuration.actions.addState')}
            </button>
          </>
        )}
        <button
          className="btn-secondary"
          onClick={() => flow.zoomIn()}
          aria-label={tt('configuration.actions.zoomIn')}
        >
          +
        </button>
        <button
          className="btn-secondary"
          onClick={() => flow.zoomOut()}
          aria-label={tt('configuration.actions.zoomOut')}
        >
          −
        </button>
        <button
          className="btn-secondary"
          onClick={() => flow.fitView({ padding: 0.2 })}
        >
          {tt('configuration.actions.fitView')}
        </button>
        {target.mode !== "view" && (
          <>
            <button className="btn-secondary" onClick={applyAutoLayout}>
              {tt('configuration.actions.autoLayout')}
            </button>
            <button className="btn-secondary" onClick={resetLayout}>
              {tt('configuration.actions.resetLayout')}
            </button>
          </>
        )}
        <span style={{ fontSize: 12, color: "#6b7280", marginLeft: 8 }}>
          {readOnly
            ? target.mode === "layout"
              ? tt('configuration.help.publishedWorkflowLayoutOnly')
              : tt('configuration.help.systemDefaultReadOnlyUseNew')
            : tt('configuration.help.workflowBuilderHowTo')}
        </span>
      </div>

      {(message || error) && (
        <div
          role="alert"
          data-builder-message
          style={{
            padding: "6px 16px",
            fontSize: 13,
            background: error ? "#fef2f2" : "#fffbeb",
            color: error ? "#991b1b" : "#92400e",
          }}
        >
          {error ?? message}
        </div>
      )}

      <div style={{ flex: 1, display: "flex", minHeight: 0 }}>
        <div
          style={{ flex: 1, minWidth: 0, position: "relative" }}
          data-workflow-canvas
          onKeyDown={(e) => {
            if (
              (e.key === "Delete" || e.key === "Backspace") &&
              !(
                e.target instanceof HTMLInputElement ||
                e.target instanceof HTMLSelectElement ||
                e.target instanceof HTMLTextAreaElement
              )
            ) {
              e.preventDefault();
              deleteSelection();
            }
          }}
        >
          {ready && (
            <ReactFlow<WorkflowFlowNode, Edge>
              fitView={!savedViewport}
              fitViewOptions={{ padding: 0.2 }}
              defaultViewport={savedViewport ?? undefined}
              nodes={nodes}
              edges={edges}
              nodeTypes={nodeTypes}
              onNodesChange={onNodesChange}
              onEdgesChange={onEdgesChange}
              onConnect={(c) =>
                c.source && c.target && addTransition(c.source, c.target)
              }
              onReconnect={onReconnect}
              onPaneClick={() => setSelection(null)}
              nodesConnectable={!readOnly}
              edgesReconnectable={!readOnly}
              deleteKeyCode={null}
              isValidConnection={(c) =>
                !readOnly && !!c.source && !!c.target && c.source !== c.target
              }
              minZoom={0.2}
              maxZoom={2}
              proOptions={{ hideAttribution: true }}
            >
              <Background gap={20} color="#e2e8f0" />
              <MiniMap pannable zoomable style={{ height: 90 }} />
            </ReactFlow>
          )}
        </div>
        <aside
          aria-label={tt('configuration.tooltips.configuration')}
          style={{
            width: 320,
            maxWidth: "40vw",
            borderLeft: "1px solid #e5e7eb",
            background: "#fff",
            padding: 14,
            overflowY: "auto",
          }}
        >
          {selectedStatus ? (
            <StateConfigurationPanel
              status={selectedStatus}
              statuses={statuses}
              transitions={transitions}
              targets={targets}
              readOnly={readOnly}
              onChange={(next) => {
                setStatuses((ss) =>
                  ss.map((s) => (s.code === next.code ? next : s)),
                );
                touched();
              }}
              onAddTransition={(to) => addTransition(selectedStatus.code, to)}
              onSelectTransition={(id) =>
                setSelection({ kind: "transition", id })
              }
              onRemove={() => {
                if (
                  window.confirm(
                    tt('configuration.confirm.removeValueTransitionsDraft', { value: labelOf(selectedStatus) }),
                  )
                )
                  removeStatuses([selectedStatus.code]);
              }}
            />
          ) : selectedTransition ? (
            <TransitionConfigurationPanel
              transition={selectedTransition}
              statuses={statuses}
              targets={targets}
              permissions={permissions}
              actions={meta?.actions ?? []}
              readOnly={readOnly}
              onChange={updateTransition}
              onDelete={() => {
                if (window.confirm(tt('configuration.confirm.deleteTransitionDraft')))
                  removeTransitions([selectedTransition._id]);
              }}
            />
          ) : (
            <div>
              <h3 style={{ fontSize: 15, margin: "0 0 8px" }}>{tt('configuration.sections.statuses')}</h3>
              <p style={{ fontSize: 12, color: "#6b7280", margin: "0 0 8px" }}>
                {tt('configuration.help.selectStatusArrowConfigure')}
              </p>
              {statuses.map((s) => (
                <button
                  key={s.code}
                  type="button"
                  className="btn-secondary"
                  style={{
                    display: "block",
                    width: "100%",
                    textAlign: "left",
                    marginBottom: 4,
                    fontSize: 12,
                  }}
                  onClick={() => setSelection({ kind: "status", code: s.code })}
                >
                  {labelOf(s)}
                  {s.is_start ? tt('configuration.actions.start') : ""}
                </button>
              ))}
            </div>
          )}
          <div
            style={{
              borderTop: "1px solid #e5e7eb",
              marginTop: 16,
              paddingTop: 10,
            }}
          >
            <h3 style={{ fontSize: 14, margin: "0 0 6px" }}>{tt('configuration.sections.validation')}</h3>
            <WorkflowValidationPanel
              result={validation}
              transitionIdAt={(i) => transitions[i]?._id}
              onSelectStatus={(c) => setSelection({ kind: "status", code: c })}
              onSelectTransition={(id) =>
                setSelection({ kind: "transition", id })
              }
            />
          </div>
        </aside>
      </div>
    </div>
  );
}
