import type {
  WorkflowPayload,
  WorkflowStatusDef,
  WorkflowTransitionDef,
} from "../../../../types";

/** A transition in the builder: the stored definition plus a stable id for the canvas. */
export interface EditableTransition extends WorkflowTransitionDef {
  _id: string;
}

export type Positions = Record<string, { x: number; y: number }>;

export const COLUMN_GAP = 270;
export const ROW_GAP = 120;

let counter = 0;
export const newTransitionId = () => `t${Date.now().toString(36)}${counter++}`;

export function toEditable(payload: WorkflowPayload | null | undefined): {
  statuses: WorkflowStatusDef[];
  transitions: EditableTransition[];
  extra: Record<string, unknown>;
} {
  const {
    statuses = [],
    transitions = [],
    ...extra
  } = payload ?? {
    statuses: [],
    transitions: [],
  };
  return {
    statuses: statuses.map((s) => ({ ...s })),
    transitions: transitions.map((t) => ({ ...t, _id: newTransitionId() })),
    extra,
  };
}

/** The payload saved to the server: the same shape the runtime reads; nothing visual in it. */
export function toPayload(
  statuses: WorkflowStatusDef[],
  transitions: EditableTransition[],
  extra: Record<string, unknown>,
): WorkflowPayload {
  return {
    ...extra,
    statuses,
    transitions: transitions.map(({ _id, ...t }) => {
      void _id;
      return t;
    }),
  };
}

export const labelOf = (s: WorkflowStatusDef) => s.display_name || s.code;

/** Default action code for a transition into `to` (the convention of the seeded workflows). */
export const defaultActionCode = (to: string) => to.toLowerCase();

/**
 * Deterministic left-to-right layout: each status goes in the column of its shortest distance
 * from a start status (breadth-first); statuses no start reaches go in a last column; within a
 * column the order of the workflow's status list is kept. Same input → same positions.
 */
export function autoLayout(
  statuses: WorkflowStatusDef[],
  transitions: WorkflowTransitionDef[],
): Positions {
  const depth = new Map<string, number>();
  const queue: string[] = [];
  for (const s of statuses)
    if (s.is_start) {
      depth.set(s.code, 0);
      queue.push(s.code);
    }
  while (queue.length) {
    const current = queue.shift()!;
    for (const t of transitions)
      if (t.from_status === current && !depth.has(t.to_status)) {
        depth.set(t.to_status, depth.get(current)! + 1);
        queue.push(t.to_status);
      }
  }
  const maxDepth = Math.max(0, ...depth.values());
  const rows = new Map<number, number>();
  const positions: Positions = {};
  for (const s of statuses) {
    const column = depth.get(s.code) ?? maxDepth + 1;
    const row = rows.get(column) ?? 0;
    rows.set(column, row + 1);
    positions[s.code] = { x: column * COLUMN_GAP, y: row * ROW_GAP };
  }
  return positions;
}

/** A free spot right of everything for a newly added status. */
export function freeSpot(positions: Positions): { x: number; y: number } {
  const values = Object.values(positions);
  if (values.length === 0) return { x: 0, y: 0 };
  return {
    x: Math.max(...values.map((p) => p.x)) + COLUMN_GAP,
    y: Math.min(...values.map((p) => p.y)),
  };
}
