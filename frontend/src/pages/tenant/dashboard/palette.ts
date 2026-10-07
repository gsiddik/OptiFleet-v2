/**
 * Dashboard chart colors (validated reference palette, light mode — the app has no dark theme).
 * Categorical slots are assigned in fixed order and follow the entity, never its rank. Status colors
 * mean good/warning/serious/critical only and always ship with a text label.
 */
export const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'] as const;

export const STATUS = { good: '#0ca30c', warning: '#fab219', serious: '#ec835a', critical: '#d03b3b' } as const;

/** Ordinal ramp (one hue) for ordered buckets such as ages: light → dark, ≥ 2:1 on the surface. */
export const ORDINAL_BLUE = ['#86b6ef', '#5598e7', '#2a78d6', '#1c5cab', '#104281'] as const;

/** Neutral for "no value / other". */
export const NEUTRAL = '#c3c2b7';

export const INK = { primary: '#111827', secondary: '#52514e', muted: '#6b7280', grid: '#e5e7eb', axis: '#c3c2b7' } as const;
