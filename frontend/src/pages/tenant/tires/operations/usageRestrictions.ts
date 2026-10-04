import type { ApplicationLimits } from "../inspection/inspectionTypes";

/** Usage restrictions as one line (positions, load, speed, operation, notes). */
export function restrictionText(limits: ApplicationLimits): string {
  return [
    limits.positions?.length
      ? `Positions: ${limits.positions.join(", ")}`
      : null,
    limits.max_load_kg != null && limits.max_load_kg !== ""
      ? `Max load ${limits.max_load_kg} kg`
      : null,
    limits.max_speed_kmh != null && limits.max_speed_kmh !== ""
      ? `Max speed ${limits.max_speed_kmh} km/h`
      : null,
    limits.operations?.length
      ? `Operation: ${limits.operations.join(", ")}`
      : null,
    limits.notes || null,
  ]
    .filter(Boolean)
    .join(" · ");
}

/** True when the limits name allowed positions and `position` is not one of them. */
export function outsideAllowedPositions(
  limits: ApplicationLimits | null | undefined,
  position: string,
): boolean {
  const allowed = (limits?.positions ?? [])
    .map((p) => p.trim().toUpperCase())
    .filter(Boolean);
  return allowed.length > 0 && !allowed.includes(position.trim().toUpperCase());
}
