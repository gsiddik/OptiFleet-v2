/**
 * Stable tab identity (i18n structural preparation).
 *
 * A tab is identified by a stable `id`, which is used for state, `?tab=` deep links and
 * permission gating. `label` is display text only, so it can be translated later without
 * breaking links, active state or saved URLs.
 */
export interface TabDef<Id extends string = string> {
  readonly id: Id;
  readonly label: string;
}

const normalize = (value: string): string => value.toLowerCase().replace(/[^a-z0-9]/g, '');

/**
 * Resolves a `?tab=` value to a tab id. It accepts the stable id, an explicit alias, or a
 * legacy English label, so old links (`?tab=Contract`, `?tab=Module%20Entitlements`) keep
 * working. Matching ignores case, spaces and punctuation. Unknown values return `fallback`.
 */
export function resolveTabId<Id extends string>(
  tabs: readonly TabDef<Id>[],
  raw: string | null | undefined,
  fallback: Id,
  aliases: Readonly<Record<string, Id>> = {},
): Id {
  if (!raw) return fallback;
  const exact = tabs.find((t) => t.id === raw);
  if (exact) return exact.id;
  const key = normalize(raw);
  if (!key) return fallback;
  const alias = Object.keys(aliases).find((k) => normalize(k) === key);
  if (alias) return aliases[alias];
  const match = tabs.find((t) => normalize(t.id) === key || normalize(t.label) === key);
  return match ? match.id : fallback;
}

/** Display label for a tab id; falls back to the id itself. */
export function tabLabel<Id extends string>(tabs: readonly TabDef<Id>[], id: Id): string {
  return tabs.find((t) => t.id === id)?.label ?? id;
}
