import { useEffect, useState } from "react";
import { apiClient } from "../api/client";
import type { AvailableWorkflowTransition } from "../types";

/**
 * The transitions the record's published workflow allows the current user right now
 * (GET /workflow/available-transitions) — the same rules the backend enforces when the action
 * is executed. `null` while loading or when the workflow cannot be read (pages then fall back to
 * their built-in actions; the backend still rejects anything the workflow does not allow).
 */
export function useWorkflowTransitions(
  resourceType: string,
  resourceId: string | undefined,
  status: string | undefined,
): AvailableWorkflowTransition[] | null {
  const [state, setState] = useState<{
    key: string;
    transitions: AvailableWorkflowTransition[] | null;
  } | null>(null);
  const key = `${resourceType}:${resourceId}:${status}`;

  useEffect(() => {
    if (!resourceId || !status) return;
    let alive = true;
    apiClient
      .get("/app/workflow/available-transitions", {
        params: { resource_type: resourceType, resource_id: resourceId },
      })
      .then(
        (res) =>
          alive && setState({ key, transitions: res.data.data.transitions }),
      )
      .catch(() => alive && setState({ key, transitions: null }));
    return () => {
      alive = false;
    };
  }, [resourceType, resourceId, status, key]);

  return state?.key === key ? state.transitions : null;
}

export interface ModuleAction {
  /** The module endpoint that performs the transition (e.g. "approve"). */
  action: string;
  /** The module's own button label (used when the workflow keeps the default action name). */
  label: string;
  permission: string;
  needsNote?: boolean;
}

const humanize = (code: string) => code.replace(/_/g, " ").toLowerCase();

/**
 * The buttons to show: one per transition the workflow allows, for each target status the module
 * has an action for (`byTarget`). A transition renamed in the Workflow Builder shows its new name;
 * otherwise the module label is used. Without workflow data the page's built-in list is used.
 */
export function workflowButtons<T extends ModuleAction>(
  available: AvailableWorkflowTransition[] | null,
  byTarget: Record<string, T>,
  fallback: T[],
): Array<T & { toStatus?: string }> {
  if (!available) return fallback;
  const seen = new Set<string>();
  return available.flatMap((t) => {
    const action = byTarget[t.to_status];
    if (!action || seen.has(action.action)) return [];
    seen.add(action.action);
    const renamed =
      t.action_label &&
      humanize(t.action_label) !== humanize(t.to_status) &&
      t.action_label !== t.action_code;
    return [
      {
        ...action,
        label: renamed ? t.action_label : action.label,
        toStatus: t.to_status,
      },
    ];
  });
}

/**
 * For pages whose actions open their own dialogs (schedule, complete…): keeps a built-in action
 * only when the workflow currently allows the transition it performs. Actions that are not a
 * workflow transition (`targetOf` has no entry) and pages without workflow data are unaffected.
 */
export function allowedByWorkflow(
  available: AvailableWorkflowTransition[] | null,
  targetOf: Record<string, string>,
): (action: { action: string }) => boolean {
  return (a) => {
    const target = targetOf[a.action];
    return (
      !available || !target || available.some((t) => t.to_status === target)
    );
  };
}
