import type { WorkspaceReservationItem } from "../../../types";

/** Local calendar day (YYYY-MM-DD) bounds as epoch ms. */
function dayBounds(day: string): [number, number] {
  const start = new Date(`${day}T00:00:00`).getTime();
  return [start, start + 24 * 60 * 60 * 1000];
}

/** Assignments whose window touches the given local day, ordered by start. */
export function reservationsOnDay(
  reservations: WorkspaceReservationItem[],
  day: string,
): WorkspaceReservationItem[] {
  const [from, to] = dayBounds(day);
  return reservations
    .filter(
      (r) =>
        new Date(r.start_at).getTime() < to &&
        new Date(r.end_at).getTime() > from,
    )
    .sort((a, b) => a.start_at.localeCompare(b.start_at));
}

/**
 * Peak number of assignments running at the same moment during the day — the same rule the backend
 * uses for capacity (capacity N = up to N concurrent Work Orders), so "Occupied k / N" matches what
 * the scheduling dialogs accept.
 */
export function peakConcurrency(
  reservations: WorkspaceReservationItem[],
  day: string,
): number {
  const [from, to] = dayBounds(day);
  const windows = reservations
    .map(
      (r) =>
        [
          Math.max(new Date(r.start_at).getTime(), from),
          Math.min(new Date(r.end_at).getTime(), to),
        ] as const,
    )
    .filter(([s, e]) => e > s);
  let peak = 0;
  for (const [point] of windows) {
    peak = Math.max(
      peak,
      windows.filter(([s, e]) => s <= point && e > point).length,
    );
  }
  return peak;
}
