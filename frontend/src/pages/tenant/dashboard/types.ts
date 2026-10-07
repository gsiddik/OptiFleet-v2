/** Contracts of the tenant dashboard API (backend: App\Domain\Dashboard). */

export type WidgetKind = 'current' | 'period';
export type WidgetUnit = 'count' | 'money' | 'days' | 'mixed';
export type FilterKey = 'branch' | 'workshop' | 'warehouse' | 'period';

export interface CatalogWidget {
  id: string;
  kind: WidgetKind;
  unit: WidgetUnit;
  filters: FilterKey[];
  drilldown: boolean;
}

export interface FilterOption {
  id: string;
  name: string;
  code: string | null;
}

export interface DashboardCatalog {
  presets: { id: string; widgets: string[] }[];
  widgets: CatalogWidget[];
  filters: {
    options: { branches: FilterOption[]; workshops: FilterOption[]; warehouses: FilterOption[] };
    month_options: number[];
    default_months: number;
  };
  timezone: string;
  currency: string;
  today: string;
  scope: { tenant_wide: boolean };
}

export interface Limitation {
  code: string;
  params?: Record<string, string | number>;
}

export interface WidgetEnvelope<T = Record<string, unknown>> {
  id: string;
  kind: WidgetKind;
  unit: WidgetUnit;
  currency: string;
  timezone: string;
  generated_at: string;
  as_of_date: string;
  period: { months: string[]; current_month: string; from: string; to_exclusive: string } | null;
  filters: Record<string, string | number | null>;
  limitations: Limitation[];
  data: T;
}

export interface DetailEnvelope<Row = Record<string, unknown>> extends Omit<WidgetEnvelope, 'data'> {
  data: Row[];
  meta: { page: number; per_page: number; total: number; last_page: number } | null;
}

/** Global filter state (ids are validated server-side against the user's scope). */
export interface DashboardFilters {
  branch_id: string;
  workshop_id: string;
  warehouse_id: string;
  months: number;
}
