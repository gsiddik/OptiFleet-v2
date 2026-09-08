import type { KpiResult } from './KpiCard';
import type { Freshness } from './FreshnessBanner';

export interface AnalyticsResponse {
  metrics: Record<string, unknown>[];
  kpis: KpiResult[];
  freshness: Freshness;
  period: { from: string; to: string };
}

export interface HighlightField {
  key: string; // dot-path, e.g. "availability.percentage"
  label: string;
}

export interface AnalyticsDomainConfig {
  title: string;
  description: string;
  endpoint: string; // e.g. "/app/analytics/fleet"
  exportSlug: string; // e.g. "fleet"
  permission: string; // e.g. "analytics.fleet.view"
  dimensionField: string; // e.g. "branch_id"
  dimensionLabel: string; // e.g. "Branch"
  highlightFields: HighlightField[];
}

export function readPath(obj: Record<string, unknown>, path: string): unknown {
  return path.split('.').reduce<unknown>((acc, key) => {
    if (acc && typeof acc === 'object' && key in (acc as Record<string, unknown>)) {
      return (acc as Record<string, unknown>)[key];
    }
    return undefined;
  }, obj);
}

export function formatCell(value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'number') return Math.round(value * 100) / 100 + '';
  if (Array.isArray(value)) return `${value.length} item(s)`;
  if (typeof value === 'object') return '{…}';
  return String(value);
}
