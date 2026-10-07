import type { DashboardFilters, FilterKey } from './types';

/** Only the filters a widget declares are sent, so an unrelated filter never fragments its cache. */
export function widgetParams(filters: DashboardFilters, applies: FilterKey[], extra: Record<string, unknown> = {}): Record<string, unknown> {
  const params: Record<string, unknown> = { ...extra };
  if (applies.includes('branch') && filters.branch_id) params.branch_id = filters.branch_id;
  if (applies.includes('workshop') && filters.workshop_id) params.workshop_id = filters.workshop_id;
  if (applies.includes('warehouse') && filters.warehouse_id) params.warehouse_id = filters.warehouse_id;
  if (applies.includes('period')) params.months = filters.months;
  return params;
}
