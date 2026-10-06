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

// Pure helpers (no API / React), importable by unit tests.
export { allowedByWorkflow, workflowButtons, type ModuleAction } from "./workflowButtons";
