import type { AvailableWorkflowTransition } from "../types";
import { isDefaultActionLabel } from "../i18n/workflowActionVerbs";
import { labelText } from "../i18n/i18n";

export interface ModuleAction {
  /** The module endpoint that performs the transition (e.g. "approve"). */
  action: string;
  /** The module's own button label in English (used when the workflow keeps the default action name). */
  label: string;
  /** Translation key of `label`; the shown label follows the UI language. */
  labelKey?: string;
  permission: string;
  needsNote?: boolean;
}


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
  // The output label is final (translated default or tenant rename): labelKey is dropped so no caller re-translates it.
  if (!available) return fallback.map((a) => ({ ...a, label: labelText(a), labelKey: undefined }));
  const seen = new Set<string>();
  return available.flatMap((t) => {
    const action = byTarget[t.to_status];
    if (!action || seen.has(action.action)) return [];
    seen.add(action.action);
    // A workflow label equal to the module's own English label is the default, not a tenant rename.
    const renamed = !isDefaultActionLabel(t) && t.action_label !== action.label;
    return [
      {
        ...action,
        // A tenant's own action name is shown as configured; the module default follows the UI language.
        label: renamed && t.action_label ? t.action_label : labelText(action),
        labelKey: undefined,
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
