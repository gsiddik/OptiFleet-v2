import type { ApplicationLimits } from "../inspection/inspectionTypes";
import { t } from '../../../../i18n/i18n';

/** Usage restrictions as one line (positions, load, speed, operation, notes). */
export function restrictionText(limits: ApplicationLimits): string {
  return [
    limits.positions?.length
      ? t('tire.fields.positionsValue', { value: limits.positions.join(", ") })
      : null,
    limits.max_load_kg != null && limits.max_load_kg !== ""
      ? t('tire.help.maxLoadMaxLoadKgKg', { max_load_kg: limits.max_load_kg })
      : null,
    limits.max_speed_kmh != null && limits.max_speed_kmh !== ""
      ? t('tire.help.maxSpeedMaxSpeedKmhKm', { max_speed_kmh: limits.max_speed_kmh })
      : null,
    limits.operations?.length
      ? t('tire.fields.operationValue', { value: limits.operations.join(", ") })
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
