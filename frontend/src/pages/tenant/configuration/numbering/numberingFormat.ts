import type { NumberingPayload, NumberingSegment } from "../../../../types";

/**
 * Document Numbering Format Builder ↔ the numbering engine's payload. The stored format keeps
 * tokens in braces ({TENANT}, {SEQ:6}), so a literal such as "DOCSTORE" or "RPO-" can never be
 * read as a token; the builder shows the same format without braces.
 *
 *   SEQ    (running number, no padding)      ⇄ {SEQ:1}
 *   SEQ:N  (fixed digits = Sequential Digit) ⇄ {SEQ:N}
 *   legacy {SEQ} (padding = sequence_padding, default 6) is shown as SEQ:N with that many digits
 *   (or SEQ when the padding is 1) — the numbers it generates are unchanged.
 */
const TOKEN = /\{([A-Z]+)(?::(\d+))?\}/g;

export function parseFormat(
  payload: Pick<NumberingPayload, "format" | "sequence_padding">,
): { segments: NumberingSegment[]; digits: number | null } {
  const format = payload.format ?? "";
  const segments: NumberingSegment[] = [];
  let digits: number | null = null;
  let last = 0;
  for (const match of format.matchAll(TOKEN)) {
    const index = match.index ?? 0;
    if (index > last)
      segments.push({ type: "literal", value: format.slice(last, index) });
    const [, name, n] = match;
    if (name === "SEQ") {
      const width =
        n !== undefined ? Number(n) : Number(payload.sequence_padding ?? 6);
      if (width <= 1) segments.push({ type: "token", token: "SEQ" });
      else {
        segments.push({ type: "token", token: "SEQ:N" });
        digits ??= width;
      }
    } else {
      segments.push({ type: "token", token: name });
    }
    last = index + match[0].length;
  }
  if (last < format.length)
    segments.push({ type: "literal", value: format.slice(last) });
  return { segments: mergeLiterals(segments), digits };
}

export function serializeFormat(
  segments: NumberingSegment[],
  digits: number | null,
): string {
  return segments
    .map((s) => {
      if (s.type === "literal") return s.value;
      if (s.token === "SEQ") return "{SEQ:1}";
      if (s.token === "SEQ:N") return `{SEQ:${digits ?? ""}}`;
      return `{${s.token}}`;
    })
    .join("");
}

/** The format as users read it: RPO-TENANT/WORKSHOP/MM/YYYY/SEQ:N. */
export function displayFormat(segments: NumberingSegment[]): string {
  return segments
    .map((s) => (s.type === "literal" ? s.value : s.token))
    .join("");
}

export function mergeLiterals(
  segments: NumberingSegment[],
): NumberingSegment[] {
  const out: NumberingSegment[] = [];
  for (const s of segments) {
    const prev = out[out.length - 1];
    if (s.type === "literal" && prev?.type === "literal")
      out[out.length - 1] = { type: "literal", value: prev.value + s.value };
    else if (s.type !== "literal" || s.value !== "") out.push(s);
  }
  return out;
}

export const usedTokens = (segments: NumberingSegment[]) =>
  new Set(
    segments
      .filter((s) => s.type === "token")
      .map((s) => (s as { token: string }).token),
  );

/** Inline validation shown before saving (the backend validates again). */
export function validateBuilder(
  segments: NumberingSegment[],
  digits: string,
  maxDigits: number,
): string[] {
  const errors: string[] = [];
  const tokens = usedTokens(segments);
  if (segments.length === 0) errors.push("Format is required.");
  if (!tokens.has("SEQ") && !tokens.has("SEQ:N"))
    errors.push("Add a running number (SEQ or SEQ:N) to the Format.");
  if (segments.some((s) => s.type === "literal" && /[{}]/.test(s.value)))
    errors.push("Custom text cannot contain { or }.");
  if (tokens.has("SEQ:N")) {
    const n = Number(digits);
    if (!/^\d+$/.test(digits) || n < 1 || n > maxDigits)
      errors.push(
        `Sequential Digit must be a whole number from 1 to ${maxDigits}.`,
      );
  }
  return errors;
}
