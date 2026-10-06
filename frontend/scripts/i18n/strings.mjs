#!/usr/bin/env node
/**
 * User-facing string audit and migration helper (TypeScript AST).
 *
 *   node scripts/i18n/strings.mjs audit [paths…]            report hard-coded user-facing strings
 *   node scripts/i18n/strings.mjs migrate <paths…> [--dry]  replace dataset-matched strings with t('key')
 *
 * Candidates: JSX text, strings in user-facing JSX attributes (label, placeholder, title, alt, aria-*,
 * header…), string / template literals used as text, and mixed JSX children ("Due {date}") that form one
 * sentence. Each is matched by its English text against the generated English resources (dataset 12 +
 * additions 17); templates and mixed children match a `{{param}}` entry with the same shape.
 *
 * Only unambiguous matches are rewritten. The same English text with different Indonesian translations
 * (e.g. Cancel = Batal / Batalkan) needs a context decision by hand. Strings evaluated at module level
 * are reported, never rewritten: a module constant would freeze one language.
 */
import ts from 'typescript';
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseCsv } from './generate.mjs';

const FRONTEND = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const ROOT = resolve(FRONTEND, '..');
const SRC = join(FRONTEND, 'src');
const LOCALES = join(SRC, 'i18n/locales');

const TEXT_ATTRS = new Set(['label', 'placeholder', 'title', 'alt', 'header', 'emptyLabel', 'emptyText', 'description', 'confirmLabel', 'cancelLabel', 'text', 'message', 'hint', 'helperText', 'tooltip', 'subtitle', 'heading', 'caption', 'legend', 'noun', 'buttonLabel', 'submitLabel', 'loadingLabel', 'busyLabel']);
const TEXT_PROPS = new Set([...TEXT_ATTRS, 'labelText', 'shortLabel', 'columnLabel', 'reason', 'error', 'warning', 'summary', 'detail', 'details', 'help', 'title']);
const NON_TEXT_ATTRS = new Set(['className', 'style', 'href', 'to', 'type', 'id', 'name', 'key', 'value', 'htmlFor', 'role', 'src', 'target', 'rel', 'accept', 'autoComplete', 'method', 'action', 'lang', 'inputMode', 'pattern', 'step', 'min', 'max', 'width', 'height', 'd', 'viewBox', 'fill', 'stroke', 'path', 'data-testid', 'domain', 'status', 'icon', 'variant', 'size', 'align', 'tab', 'mode']);
const CODE_CALLEES = /(^|\.)(t|tt|translated|message|includes|startsWith|endsWith|indexOf|split|replace|replaceAll|get|post|put|patch|delete|getItem|setItem|removeItem|querySelector|querySelectorAll|getElementById|addEventListener|createElement|match|test|hasPermission|statusLabel|statusDisplayKey|actionVerbLabel|navigate|useParams|useSearchParams|setSearchParams|resolveTabId|require|join|padStart|padEnd|toLocaleString|localeCompare|warn|log|info|debug|getAttribute|setAttribute|closest|matches|has|delete|append|set|useTabParam|useBreadcrumbLabel|setTab|setActiveTab|setStatus|setFilter|setMode|setSort|setView|fetchProtectedFile|download|apiClient\.\w+)$/;

// ---------------------------------------------------------------------------------------- resources
function flatten(tree, prefix = '', out = new Map()) {
  for (const [k, v] of Object.entries(tree)) {
    const key = prefix ? `${prefix}.${k}` : k;
    if (typeof v === 'string') out.set(key, v);
    else flatten(v, key, out);
  }
  return out;
}
function loadLocale(locale) {
  const out = new Map();
  for (const f of readdirSync(join(LOCALES, locale))) flatten(JSON.parse(readFileSync(join(LOCALES, locale, f), 'utf8')), f.replace(/\.json$/, ''), out);
  return out;
}

const ENTITIES = { '&amp;': '&', '&nbsp;': ' ', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&apos;': "'", '&#39;': "'", '&rarr;': '→', '&larr;': '←', '&mdash;': '—', '&ndash;': '–', '&hellip;': '…', '&times;': '×', '&middot;': '·' };
export const decode = (s) => s.replace(/&[a-z#0-9]+;/gi, (e) => ENTITIES[e.toLowerCase()] ?? e);
export const normalize = (s) => decode(s).replace(/\s+/g, ' ').trim();
const hasWords = (s) => /[A-Za-z]{2,}/.test(s);
/** A string literal that reads as text, not as a code / CSS value / identifier. */
const looksLikeText = (s) =>
  hasWords(s) &&
  !/^[A-Z0-9_]+$/.test(s) &&
  !/^[a-z0-9_.:/-]+$/.test(s) &&
  !/(^#[0-9a-f]{3,8}\b|\d(px|rem|em|%|vh|vw|fr)\b|rgba?\(|solid |dashed |minmax\(|calc\(|var\(--)/i.test(s) &&
  (/^[A-Z]/.test(s) || /\s/.test(s) || /[…?!.:]$/.test(s));
/** `Due {{date}} for {{name}}` → `Due {{#}} for {{#}}` (the shape a template literal is compared by). */
const shapeOf = (text) => normalize(text).replace(/\{\{\s*[\w.]+\s*\}\}/g, '{{#}}');
const paramNames = (text) => [...text.matchAll(/\{\{\s*([\w.]+)\s*\}\}/g)].map((m) => m[1]);

export function buildIndex() {
  const en = loadLocale('en');
  const id = loadLocale('id');
  const byText = new Map();
  const byShape = new Map();
  for (const [key, text] of en) {
    const n = normalize(text);
    if (!byText.has(n)) byText.set(n, []);
    byText.get(n).push(key);
    if (/\{\{/.test(text)) {
      const sh = shapeOf(text);
      if (!byShape.has(sh)) byShape.set(sh, []);
      byShape.get(sh).push(key);
    }
  }
  const sources = new Map();
  for (const f of ['docs/i18n/12-en-id-translation-dataset-final.csv']) {
    for (const r of parseCsv(readFileSync(join(ROOT, f), 'utf8'))) sources.set(r.translation_key, r.source_file ?? '');
  }
  return { en, id, byText, byShape, sources };
}

/** Keys whose texts are interchangeable (same Indonesian), the preferred one first; null if ambiguous. */
function choose(keys, fileRel, index, params) {
  if (!keys.length) return null;
  // Status labels are rendered through the status registry, never as literals: a literal "Open" or "New"
  // is almost always a verb or an adjective (Buka / Baru), not the status (Terbuka / Baru). Decide by hand.
  if (keys.every((k) => k.startsWith('status.'))) return { ambiguous: true, keys };
  keys = keys.filter((k) => !k.startsWith('status.'));
  const valid = params ? keys.filter((k) => paramNames(index.en.get(k)).length === params.length) : keys;
  if (!valid.length) return null;
  // Templates whose translations differ only in their placeholder names are equivalent (params are passed by position).
  const translations = new Set(valid.map((k) => (params ? shapeOf(index.id.get(k)) : index.id.get(k))));
  if (translations.size > 1) return { ambiguous: true, keys: valid };
  const own = valid.filter((k) => (index.sources.get(k) ?? '').includes(fileRel));
  const common = valid.filter((k) => k.startsWith('common.'));
  const key = (own[0] ?? common[0] ?? [...valid].sort()[0]);
  return { key, keys: valid };
}

// ------------------------------------------------------------------------------------------ analysis
function sourceFiles(paths) {
  const out = [];
  const walk = (p) => {
    if (statSync(p).isDirectory()) { if (!p.endsWith('locales')) readdirSync(p).forEach((f) => walk(join(p, f))); }
    else if (/\.(tsx|ts)$/.test(p) && !p.endsWith('.d.ts')) out.push(p);
  };
  paths.forEach((p) => walk(resolve(FRONTEND, p)));
  return out;
}

const insideFunction = (node) => { for (let n = node.parent; n; n = n.parent) if (ts.isFunctionLike(n)) return true; return false; };

function isCodeLiteral(node) {
  const p = node.parent;
  if (!p) return true;
  if (ts.isImportDeclaration(p) || ts.isExportDeclaration(p) || ts.isExternalModuleReference(p)) return true;
  if (ts.isLiteralTypeNode(p)) return true;
  if (ts.isPropertyAssignment(p) && p.name === node) return true;
  if (ts.isElementAccessExpression(p) && p.argumentExpression === node) return true;
  if (ts.isBinaryExpression(p) && [ts.SyntaxKind.EqualsEqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsEqualsToken, ts.SyntaxKind.EqualsEqualsToken, ts.SyntaxKind.ExclamationEqualsToken, ts.SyntaxKind.InKeyword].includes(p.operatorToken.kind)) return true;
  if (ts.isCaseClause(p)) return true;
  if ((ts.isCallExpression(p) || ts.isNewExpression(p)) && CODE_CALLEES.test(p.expression.getText())) return true;
  if (ts.isJsxAttribute(p) && NON_TEXT_ATTRS.has(p.name.getText())) return true;
  for (let n = p; n; n = n.parent) {
    if (ts.isJsxAttribute(n) && ['style', 'className', 'sx'].includes(n.name.getText())) return true;
    if (ts.isPropertyAssignment(n) && ['style', 'className'].includes(n.name.getText())) return true;
    if (ts.isJsxElement(n) || ts.isJsxSelfClosingElement(n) || ts.isFunctionLike(n)) break;
  }
  return false;
}

function textAttribute(node) {
  let p = node.parent;
  if (ts.isJsxExpression(p)) p = p.parent;
  return ts.isJsxAttribute(p) && (TEXT_ATTRS.has(p.name.getText()) || /^aria-(label|description|valuetext)$/.test(p.name.getText())) ? p : null;
}

/** True when the expression can safely be passed as a t() parameter (no JSX inside). */
const plainExpression = (expr) => { let ok = true; const v = (n) => { if (ts.isJsxElement(n) || ts.isJsxSelfClosingElement(n) || ts.isJsxFragment(n)) ok = false; ts.forEachChild(n, v); }; v(expr); return ok; };

/**
 * "Cancel" has two meanings (owner decision): dismiss a dialog / form = common.actions.cancel (Batal);
 * cancel a business record = common.actions.cancelRecord (Batalkan). Decided from the button's onClick.
 */
function cancelMeaning(node) {
  let el = node.parent;
  while (el && !ts.isJsxElement(el)) el = el.parent;
  const opening = el?.openingElement;
  const onClick = opening?.attributes.properties.find((a) => ts.isJsxAttribute(a) && a.name.getText() === 'onClick');
  const code = onClick?.initializer?.getText() ?? '';
  if (!code) return null;
  if (/\b(onClose|close\w*|dismiss\w*|reset\w*|navigate\(-1\))\b|\bset\w+\((null|false|''|undefined)\)|setEditing|setShow|setOpen|setAdding|setCreating|setConfirm/i.test(code) && !/cancel\w*\(|'cancel'|"cancel"/.test(code.replace(/onCancel/g, ''))) return 'common.actions.cancel';
  if (/\b\w*cancel\w*\(|'cancel'|"cancel"|setCancel\w*\(true\)/i.test(code) && !/onCancel\b/.test(code)) return 'common.actions.cancelRecord';
  if (/\bonCancel\b/.test(code)) return 'common.actions.cancel';
  return null;
}

export function analyze(file, index) {
  const text = readFileSync(file, 'utf8');
  const sf = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
  const fileRel = relative(ROOT, file);
  const found = [];
  const covered = new Set();
  const line = (pos) => sf.getLineAndCharacterOfPosition(pos).line + 1;
  const record = (entry) => found.push({ ...entry, line: line(entry.start) });

  const tryText = (node, kind, value, start, end) => {
    let n = normalize(value);
    if (!hasWords(n)) return;
    let suffix = '';
    let match = choose(index.byText.get(n) ?? [], fileRel, index);
    if (n === 'Cancel') { const key = cancelMeaning(node); match = key ? { key, keys: [key] } : { ambiguous: true, keys: ['common.actions.cancel', 'common.actions.cancelRecord'] }; }
    if (!match && /:$/.test(n)) { match = choose(index.byText.get(n.slice(0, -1).trim()) ?? [], fileRel, index); if (match) suffix = ':'; }
    if (keyedLabel(node)) return; // `label: 'X', labelKey: '…'`: shown through labelText()
    record({ kind, text: n, start, end, match, suffix, moduleLevel: !insideFunction(node), node });
  };

  const visit = (node) => {
    // Mixed JSX children forming one sentence: "Approve {lines} for {wo}?"
    if ((ts.isJsxElement(node) || ts.isJsxFragment(node)) && node.children.length > 1) {
      const kids = node.children.filter((c) => !(ts.isJsxText(c) && !c.getText(sf).trim()) || true);
      const parts = [];
      let ok = true, hasText = false, exprs = [];
      for (const c of kids) {
        if (ts.isJsxText(c)) { parts.push(c.getText(sf)); if (hasWords(decode(c.getText(sf)))) hasText = true; }
        else if (ts.isJsxExpression(c) && c.expression && plainExpression(c.expression) && !ts.isStringLiteral(c.expression)) { parts.push('{{#}}'); exprs.push(c.expression); }
        else if (ts.isJsxExpression(c) && c.expression && ts.isStringLiteral(c.expression) && c.expression.text === ' ') parts.push(' ');
        else { ok = false; break; }
      }
      if (ok && hasText && exprs.length) {
        const shape = normalize(parts.join(''));
        const match = choose(index.byShape.get(shape) ?? [], fileRel, index, exprs);
        const first = kids[0], last = kids[kids.length - 1];
        if (match) {
          record({ kind: 'jsx-mixed', text: shape, start: first.getStart(sf), end: last.getEnd(), match, exprs, moduleLevel: !insideFunction(node), node, leading: first.getText(sf).match(/^\s*/)[0], trailing: last.getText(sf).match(/\s*$/)[0] });
          kids.forEach((c) => covered.add(c));
        }
      }
    }
    if (ts.isJsxText(node) && !covered.has(node)) {
      const raw = node.getText(sf);
      if (raw.trim()) tryText(node, 'jsx-text', raw, node.getStart(sf) + raw.search(/\S/), node.getStart(sf) + raw.trimEnd().length);
    } else if ((ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) && !isCodeLiteral(node)) {
      const attr = textAttribute(node);
      const p = node.parent;
      const userText = attr || ts.isJsxExpression(p) || (ts.isPropertyAssignment(p) && TEXT_PROPS.has(p.name.getText())) || ts.isConditionalExpression(p) || ts.isBinaryExpression(p) || ts.isReturnStatement(p) || ts.isCallExpression(p) || ts.isArrayLiteralExpression(p) || ts.isVariableDeclaration(p) || ts.isPropertyAssignment(p) || ts.isArrowFunction(p);
      if (userText && (attr || looksLikeText(node.text) || index.byText.has(normalize(node.text)))) tryText(node, attr && !ts.isJsxExpression(node.parent) ? 'jsx-attr' : 'string', node.text, node.getStart(sf), node.getEnd());
    } else if (ts.isTemplateExpression(node) && !isCodeLiteral(node)) {
      const shape = normalize(node.head.text + node.templateSpans.map((s) => '{{#}}' + s.literal.text).join(''));
      if (hasWords(shape.replace(/\{\{#\}\}/g, ''))) {
        const exprs = node.templateSpans.map((s) => s.expression);
        record({ kind: 'template', text: shape, start: node.getStart(sf), end: node.getEnd(), match: choose(index.byShape.get(shape) ?? [], fileRel, index, exprs), exprs, moduleLevel: !insideFunction(node), node });
      }
    }
    ts.forEachChild(node, visit);
  };
  visit(sf);
  return { file, fileRel, text, sf, found };
}

export const statusOf = (f) => (f.moduleLevel ? 'moduleLevel' : !f.match ? (f.kind === 'template' ? 'template' : 'unmatched') : f.match.ambiguous ? 'ambiguous' : 'matched');

// ----------------------------------------------------------------------------------------- migration
/** Local bindings named `t` (a parameter, variable or function) — the import is then aliased. */
function bindsT(sf) {
  let found = false;
  const v = (n) => {
    if ((ts.isParameter(n) || ts.isVariableDeclaration(n) || ts.isFunctionDeclaration(n) || ts.isBindingElement(n)) && n.name && ts.isIdentifier(n.name) && n.name.text === 't') found = true;
    if (!found) ts.forEachChild(n, v);
  };
  v(sf);
  return found;
}

function paramsObject(keyText, exprs, sf) {
  const names = paramNames(keyText);
  return '{ ' + names.map((name, i) => `${/^[A-Za-z_$][\w$]*$/.test(name) ? name : JSON.stringify(name)}: ${exprs[i].getText(sf)}`).join(', ') + ' }';
}

/** A `label: '…'` property whose object already carries a `labelKey`. */
function keyedLabel(node) {
  const p = node.parent;
  return Boolean(p && ts.isPropertyAssignment(p) && p.initializer === node && p.name.getText() === 'label' && ts.isObjectLiteralExpression(p.parent) && p.parent.properties.some((q) => q.name?.getText() === 'labelKey'));
}

/** A `label: '…'` property of an object literal that has no `labelKey` yet. */
function labelProperty(node) {
  const p = node.parent;
  if (!p || !ts.isPropertyAssignment(p) || p.initializer !== node || p.name.getText() !== 'label' || !ts.isObjectLiteralExpression(p.parent)) return false;
  return !p.parent.properties.some((q) => q.name?.getText() === 'labelKey');
}

export function migrateFile(file, index, { dry = false } = {}) {
  const { fileRel, text, sf, found } = analyze(file, index);
  // The name `t` is imported under: `import { t }` → t, `import { t as tt }` → tt (a re-run keeps the alias).
  const imported = text.match(/import \{[^}]*\bt(?:\s+as\s+(\w+))?\s*[,}][^;]*from '[./]*\/?(?:\.\.\/)*i18n\/i18n'/);
  const importsT = Boolean(imported) || /const \{ t(?:, [^}]*)? \} = useTranslation\(\)/.test(text);
  const fn = imported ? (imported[1] ?? 't') : importsT ? 't' : bindsT(sf) ? 'tt' : 't';
  const edits = [];
  for (const f of found) {
    // Module-level `label: 'X'` in an object literal: keep the English (identity / legacy matching) and add
    // its key beside it; the render site shows it through labelText(). No import needed.
    if (f.moduleLevel && f.match && !f.match.ambiguous && !f.exprs && labelProperty(f.node)) {
      edits.push({ start: f.end, end: f.end, replacement: `, labelKey: '${f.match.key}'`, key: f.match.key, keyOnly: true });
      continue;
    }
    if (statusOf(f) !== 'matched') continue;
    const key = f.match.key;
    const call = f.exprs ? `${fn}('${key}', ${paramsObject(index.en.get(key), f.exprs, sf)})` : `${fn}('${key}')`;
    let replacement;
    if (f.kind === 'jsx-text') replacement = `{${call}}${f.suffix}`;
    else if (f.kind === 'jsx-mixed') replacement = `${f.leading}{${call}}${f.trailing}`;
    else if (f.kind === 'jsx-attr') replacement = `{${call}${f.suffix ? ` + '${f.suffix}'` : ''}}`;
    else replacement = f.suffix ? `${call} + '${f.suffix}'` : call;
    edits.push({ start: f.start, end: f.end, replacement, key });
  }
  edits.sort((a, b) => b.start - a.start);
  let out = text;
  let lastStart = Infinity;
  const applied = [];
  for (const e of edits) {
    if (e.end > lastStart) continue; // overlapping (nested) candidate: the outer one already covers it
    out = out.slice(0, e.start) + e.replacement + out.slice(e.end);
    lastStart = e.start;
    applied.push(e);
  }
  if (applied.some((e) => !e.keyOnly) && !importsT) {
    const rel = relative(dirname(file), join(SRC, 'i18n/i18n')).replace(/\\/g, '/');
    const spec = rel.startsWith('.') ? rel : `./${rel}`;
    const imp = fn === 't' ? `import { t } from '${spec}';\n` : `import { t as tt } from '${spec}';\n`;
    const imports = [...out.matchAll(/^import [^;]+;\n/gm)];
    const at = imports.length ? imports[imports.length - 1].index + imports[imports.length - 1][0].length : 0;
    out = out.slice(0, at) + imp + out.slice(at);
  }
  if (!dry && applied.length) writeFileSync(file, out);
  return { fileRel, applied: applied.length, report: found.map((f) => ({ line: f.line, kind: f.kind, status: statusOf(f), text: f.text, keys: f.match?.keys?.slice(0, 4) ?? [] })) };
}

// ----------------------------------------------------------------------------------------------- CLI
function main() {
  const args = process.argv.slice(2);
  const mode = args[0] ?? 'audit';
  const dry = args.includes('--dry');
  const paths = args.slice(1).filter((a) => !a.startsWith('--'));
  const index = buildIndex();
  const files = sourceFiles(paths.length ? paths : ['src']).filter((f) => !f.includes('/src/i18n/'));
  const totals = { matched: 0, ambiguous: 0, unmatched: 0, moduleLevel: 0, template: 0 };
  const rows = [];
  let applied = 0;
  for (const file of files) {
    const result = mode === 'migrate' ? migrateFile(file, index, { dry }) : { fileRel: relative(ROOT, file), applied: 0, report: analyze(file, index).found.map((f) => ({ line: f.line, kind: f.kind, status: statusOf(f), text: f.text, keys: f.match?.keys?.slice(0, 4) ?? [] })) };
    applied += result.applied;
    for (const r of result.report) { totals[r.status]++; rows.push({ file: result.fileRel, ...r }); }
  }
  console.log(JSON.stringify({ mode, files: files.length, ...(mode === 'migrate' ? { replaced: applied } : {}), totals }, null, 1));
  if (process.env.I18N_AUDIT_OUT) writeFileSync(process.env.I18N_AUDIT_OUT, JSON.stringify(rows, null, 1));
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
