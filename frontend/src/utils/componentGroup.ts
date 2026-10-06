import { t } from '../i18n/i18n';
/**
 * Picker/label format for Component Groups: "{ABBR} — {Name}" so users see the
 * 3-letter abbreviation used as a Product SKU element. Legacy groups without
 * an abbreviation fall back to the plain name.
 */
export function componentGroupLabel(group: { name: string; abbreviation?: string | null } | null | undefined): string {
  if (!group) return '—';
  return group.abbreviation ? `${group.abbreviation} — ${group.name}` : group.name;
}

/** Keeps only letters, uppercased, max 3 — mirrors the backend rule (exactly 3 letters A-Z). */
export function normalizeAbbreviationInput(value: string): string {
  return value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3);
}

export function abbreviationError(value: string): string | null {
  if (value.length === 0) return t('common.validation.abbreviationIsRequired');
  if (!/^[A-Z]{3}$/.test(value)) return t('common.validation.mustExactly3LettersZ');
  return null;
}
