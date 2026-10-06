import type { TemplateCatalog, TemplateEditorNode } from "../../../../types";
import { t } from '../../../../i18n/i18n';

/**
 * Document Template editor ⇄ stored template. The stored HTML keeps the existing grammar
 * ({{path}}, {{#block}}…{{/block}}); in the editor a variable is a chip ([Vehicle Registration])
 * and a block is a marked container — either the one element it repeats (e.g. a table row) or
 * a wrapper around several. Saving sends the editor document (JSON); the server builds and
 * sanitizes the HTML from it.
 */

export const ALLOWED_ATTRS = [
  "style",
  "colspan",
  "rowspan",
  "border",
  "cellpadding",
  "cellspacing",
  "align",
  "valign",
  "width",
  "height",
];
const TABLE_PARENTS = new Set([
  "TABLE",
  "TBODY",
  "THEAD",
  "TFOOT",
  "TR",
  "COLGROUP",
]);
const BLOCK_TAGS = new Set([
  "DIV",
  "P",
  "TABLE",
  "UL",
  "OL",
  "LI",
  "H1",
  "H2",
  "H3",
  "H4",
  "H5",
  "H6",
  "BLOCKQUOTE",
  "HR",
  "TR",
  "TBODY",
]);

export interface LabelLookup {
  variable: (path: string, block: string | null) => string;
  block: (name: string) => string;
}

export function labelLookup(catalog: TemplateCatalog | null): LabelLookup {
  const top = new Map((catalog?.variables ?? []).map((v) => [v.key, v.label]));
  const blocks = new Map((catalog?.blocks ?? []).map((b) => [b.name, b]));
  return {
    variable: (path, block) =>
      (block && blocks.get(block)?.fields.find((f) => f.key === path)?.label) ||
      top.get(path) ||
      path,
    // A block is a repeating section (Jobs, Findings, Items) or a yes / no condition on a variable.
    block: (name) => blocks.get(name)?.label ?? top.get(name) ?? name,
  };
}

export function variableChip(
  doc: Document,
  path: string,
  label: string,
): HTMLSpanElement {
  const el = doc.createElement("span");
  el.dataset.var = path;
  el.contentEditable = "false";
  el.className = "tpl-var";
  el.title = `{{${path}}}`;
  el.textContent = label;
  return el;
}

export function markSection(
  el: HTMLElement,
  name: string,
  inverted: boolean,
  mode: "element" | "block" | "inline",
  label: string,
) {
  el.dataset.section = name;
  el.dataset.sectionMode = mode;
  el.dataset.sectionLabel = inverted ? t('configuration.help.whenThereNoLabel', { label: label }) : label;
  if (inverted) el.dataset.inverted = "1";
  el.classList.add("tpl-section");
}

/** Converts stored template HTML into editor DOM inside `root`. Returns structures it could not show. */
export function loadHtml(
  root: HTMLElement,
  html: string,
  labels: LabelLookup,
): string[] {
  const unsupported: string[] = [];
  const protectedHtml = html.replace(
    /\{\{(#|\^|\/)?\s*([a-zA-Z0-9_.]+)\s*\}\}/g,
    (_m, sigil: string | undefined, name: string) =>
      sigil === "#" || sigil === "^"
        ? `<!--open:${sigil}:${name}-->`
        : sigil === "/"
          ? `<!--close:${name}-->`
          : `<!--var:${name}-->`,
  );
  const doc = root.ownerDocument;
  const parsed = new DOMParser().parseFromString(
    `<div id="tpl-root">${protectedHtml}</div>`,
    "text/html",
  );
  const source = parsed.getElementById("tpl-root")!;
  pairSections(source, labels, unsupported);
  replaceVariables(source, labels, null);
  root.replaceChildren(
    ...Array.from(source.childNodes).map((n) => doc.importNode(n, true)),
  );
  return unsupported;
}

function pairSections(
  parent: Element,
  labels: LabelLookup,
  unsupported: string[],
) {
  let child = parent.firstChild;
  while (child) {
    const next = child.nextSibling;
    if (
      child.nodeType === Node.COMMENT_NODE &&
      /^open:/.test(child.nodeValue ?? "")
    ) {
      const [, sigil, name] = (child.nodeValue ?? "").split(":");
      // The matching close among the same siblings (nesting of the same name counted).
      let depth = 0;
      let close: ChildNode | null = null;
      for (let n = child.nextSibling; n; n = n.nextSibling) {
        if (n.nodeType !== Node.COMMENT_NODE) continue;
        if (
          n.nodeValue === `open:#:${name}` ||
          n.nodeValue === `open:^:${name}`
        )
          depth++;
        if (n.nodeValue === `close:${name}`) {
          if (depth === 0) {
            close = n;
            break;
          }
          depth--;
        }
      }
      if (!close) {
        unsupported.push(
          t('configuration.warnings.blockValueStartsEndsDifferentPlaces', { value: labels.block(name) }),
        );
        child = next;
        continue;
      }
      const inner: ChildNode[] = [];
      for (let n = child.nextSibling; n && n !== close; n = n.nextSibling)
        inner.push(n);
      const elements = inner.filter((n) => n.nodeType === Node.ELEMENT_NODE);
      const onlyWhitespace = inner.every(
        (n) =>
          n.nodeType === Node.ELEMENT_NODE ||
          (n.nodeType === Node.TEXT_NODE && !(n.textContent ?? "").trim()),
      );
      const inverted = sigil === "^";
      const owner = parent.ownerDocument;
      if (elements.length === 1 && onlyWhitespace) {
        markSection(
          elements[0] as HTMLElement,
          name,
          inverted,
          "element",
          labels.block(name),
        );
      } else if (TABLE_PARENTS.has(parent.tagName)) {
        unsupported.push(
          t('configuration.warnings.blockValueRepeatsSeveralTableParts', { value: labels.block(name) }),
        );
        child = close.nextSibling;
        continue;
      } else {
        const blockLevel = elements.some((e) =>
          BLOCK_TAGS.has((e as Element).tagName),
        );
        const wrapper = owner.createElement(blockLevel ? "div" : "span");
        markSection(
          wrapper,
          name,
          inverted,
          blockLevel ? "block" : "inline",
          labels.block(name),
        );
        parent.insertBefore(wrapper, child);
        inner.forEach((n) => wrapper.appendChild(n));
      }
      parent.removeChild(child);
      const after = close.nextSibling;
      parent.removeChild(close);
      child = after;
      continue;
    }
    child = next;
  }
  for (const el of Array.from(parent.children))
    pairSections(el, labels, unsupported);
}

function replaceVariables(
  parent: Node,
  labels: LabelLookup,
  block: string | null,
) {
  for (const n of Array.from(parent.childNodes)) {
    if (n.nodeType === Node.COMMENT_NODE) {
      const value = n.nodeValue ?? "";
      if (value.startsWith("var:")) {
        const path = value.slice(4);
        parent.replaceChild(
          variableChip(
            parent.ownerDocument!,
            path,
            labels.variable(path, block),
          ),
          n,
        );
      } else {
        parent.removeChild(n); // stray markers of an unsupported block are dropped from the view
      }
    } else if (n instanceof HTMLElement) {
      replaceVariables(
        n,
        labels,
        n.dataset.section && !n.dataset.inverted ? n.dataset.section : block,
      );
    }
  }
}

/** Builds editor DOM from a stored editor document (JSON). */
export function loadEditorState(
  root: HTMLElement,
  nodes: TemplateEditorNode[],
  labels: LabelLookup,
) {
  const doc = root.ownerDocument;
  const build = (
    node: TemplateEditorNode,
    block: string | null,
  ): Node | null => {
    if (node.t === "text") return doc.createTextNode(node.v);
    if (node.t === "var")
      return variableChip(doc, node.path, labels.variable(node.path, block));
    if (node.t === "el") {
      const el = doc.createElement(node.tag);
      for (const [k, v] of Object.entries(node.attrs ?? {}))
        if (ALLOWED_ATTRS.includes(k)) el.setAttribute(k, v);
      node.children.forEach((c) => {
        const built = build(c, block);
        if (built) el.appendChild(built);
      });
      return el;
    }
    const scope = node.inverted ? block : node.name;
    if (
      node.mode === "element" &&
      node.children.length === 1 &&
      node.children[0].t === "el"
    ) {
      const el = build(node.children[0], scope) as HTMLElement;
      markSection(
        el,
        node.name,
        !!node.inverted,
        "element",
        labels.block(node.name),
      );
      return el;
    }
    const wrapper = doc.createElement(node.mode === "inline" ? "span" : "div");
    markSection(
      wrapper,
      node.name,
      !!node.inverted,
      node.mode === "inline" ? "inline" : "block",
      labels.block(node.name),
    );
    node.children.forEach((c) => {
      const built = build(c, scope);
      if (built) wrapper.appendChild(built);
    });
    return wrapper;
  };
  root.replaceChildren(
    ...(nodes.map((n) => build(n, null)).filter(Boolean) as Node[]),
  );
}

/** Reads the editor DOM into the editor document sent to the server. */
export function readEditor(root: HTMLElement): TemplateEditorNode[] {
  const read = (n: Node): TemplateEditorNode[] => {
    if (n.nodeType === Node.TEXT_NODE)
      return n.textContent
        ? [{ t: "text", v: n.textContent.replace(/ /g, " ") }]
        : [];
    if (!(n instanceof HTMLElement)) return [];
    if (n.dataset.var) return [{ t: "var", path: n.dataset.var }];
    const children = Array.from(n.childNodes).flatMap(read);
    const element = (): TemplateEditorNode => ({
      t: "el",
      tag: n.tagName.toLowerCase(),
      attrs: attrsOf(n),
      children,
    });
    if (n.dataset.section) {
      const mode =
        (n.dataset.sectionMode as "element" | "block" | "inline") ?? "block";
      return [
        {
          t: "section",
          name: n.dataset.section,
          inverted: n.dataset.inverted === "1" || undefined,
          mode,
          children: mode === "element" ? [element()] : children,
        },
      ];
    }
    return [element()];
  };
  return Array.from(root.childNodes).flatMap(read);
}

function attrsOf(el: HTMLElement): Record<string, string> {
  const out: Record<string, string> = {};
  for (const name of ALLOWED_ATTRS) {
    const value = el.getAttribute(name);
    if (value !== null && value !== "") out[name] = value;
  }
  return out;
}

/** The repeating block the caret is in (innermost), or null. */
export function blockAt(node: Node | null, root: HTMLElement): string | null {
  for (let n: Node | null = node; n && n !== root; n = n.parentNode) {
    if (n instanceof HTMLElement && n.dataset.section && !n.dataset.inverted)
      return n.dataset.section;
  }
  return null;
}
