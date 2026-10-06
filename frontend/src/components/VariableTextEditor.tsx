import {
  forwardRef,
  useEffect,
  useImperativeHandle,
  useRef,
  type CSSProperties,
} from "react";
import { t } from '../i18n/i18n';

export interface VariableOption {
  key: string;
  label: string;
}

export interface VariableTextEditorHandle {
  /** Inserts the variable at the cursor (or at the end) and puts the cursor after it. */
  insertVariable: (key: string) => void;
}

/** dataTransfer type for dragging a variable card into a VariableTextEditor. */
export const VARIABLE_DRAG_TYPE = "application/x-optifleet-variable";

const TOKEN = /\{\{\s*([#^/]?)\s*([a-zA-Z0-9_.]+)\s*\}\}/g;

const chipStyle =
  "display:inline-block;padding:0 6px;margin:0 1px;border-radius:10px;background:#dbeafe;color:#1e40af;" +
  "font-size:12px;font-weight:600;line-height:18px;cursor:default;white-space:nowrap";

/**
 * Plain text with variables, edited without template syntax: each {{variable}} shows as a chip
 * with its friendly name; the value stays the existing template text ("Breakdown on
 * {{vehicle.registration_number}}"). Typed braces never become a variable ("{{" is broken up
 * to "{ {"). Conditional markers ({{#x}} / {{^x}} / {{/x}}) written elsewhere are kept as chips
 * so nothing is lost.
 */
export const VariableTextEditor = forwardRef<
  VariableTextEditorHandle,
  {
    value: string;
    onChange: (value: string) => void;
    variables: VariableOption[];
    multiline?: boolean;
    ariaLabel: string;
    invalid?: boolean;
    disabled?: boolean;
    placeholder?: string;
  }
>(function VariableTextEditor(
  {
    value,
    onChange,
    variables,
    multiline,
    ariaLabel,
    invalid,
    disabled,
    placeholder,
  },
  ref,
) {
  const root = useRef<HTMLDivElement>(null);
  const saved = useRef<Range | null>(null);
  const lastEmitted = useRef<string | null>(null);
  const labelOf = (key: string) =>
    variables.find((v) => v.key === key)?.label ?? key;

  const chip = (raw: string, sigil: string, key: string) => {
    const el = document.createElement("span");
    el.contentEditable = "false";
    el.dataset.var = raw;
    el.setAttribute("style", chipStyle);
    el.textContent =
      sigil === "#"
        ? t('common.fields.ifValue', { value: labelOf(key) })
        : sigil === "^"
          ? t('common.fields.ifNoValue', { value: labelOf(key) })
          : sigil === "/"
            ? t('common.fields.endOfValue', { value: labelOf(key) })
            : labelOf(key);
    el.title = el.textContent;
    return el;
  };

  // Load the value into the editor unless it is what the editor itself just produced.
  useEffect(() => {
    const el = root.current;
    if (!el || value === lastEmitted.current) return;
    const nodes: Node[] = [];
    let last = 0;
    for (const m of value.matchAll(TOKEN)) {
      if (m.index! > last)
        nodes.push(document.createTextNode(value.slice(last, m.index)));
      nodes.push(chip(`{{${m[1]}${m[2]}}}`, m[1], m[2]));
      last = m.index! + m[0].length;
    }
    if (last < value.length)
      nodes.push(document.createTextNode(value.slice(last)));
    el.replaceChildren(...nodes);
    lastEmitted.current = value;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, variables]);

  const read = (): string => {
    const el = root.current;
    if (!el) return "";
    const walk = (n: Node): string => {
      if (n.nodeType === Node.TEXT_NODE)
        return (n.textContent ?? "")
          .replace(/ /g, " ")
          .replace(/\{\{/g, "{ {")
          .replace(/\}\}/g, "} }");
      if (!(n instanceof HTMLElement)) return "";
      if (n.dataset.var) return n.dataset.var;
      // New lines are "\n" text (Enter is handled here); a <br> is only the browser's filler.
      if (n.tagName === "BR") return "";
      const inner = Array.from(n.childNodes).map(walk).join("");
      // Browsers may wrap new lines in <div>; each starts a line.
      return n.tagName === "DIV" && multiline ? `\n${inner}` : inner;
    };
    const text = Array.from(el.childNodes).map(walk).join("");
    return multiline ? text : text.replace(/\n/g, " ");
  };

  const emit = () => {
    const text = read();
    lastEmitted.current = text;
    onChange(text);
  };

  const remember = () => {
    const sel = window.getSelection();
    if (sel && sel.rangeCount && root.current?.contains(sel.anchorNode))
      saved.current = sel.getRangeAt(0).cloneRange();
  };

  const placeCaret = (range: Range) => {
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(range);
    saved.current = range.cloneRange();
  };

  const insertNode = (node: Node, at?: Range | null) => {
    const el = root.current;
    if (!el) return;
    let range = at ?? saved.current;
    if (range && el.contains(range.startContainer)) {
      const start = range.startContainer;
      const inChip = (
        start instanceof Element ? start : start.parentElement
      )?.closest("[data-var]");
      if (inChip) {
        range = range.cloneRange();
        range.setStartAfter(inChip);
        range.collapse(true);
      }
      range.deleteContents();
      range.insertNode(node);
    } else {
      el.appendChild(node);
    }
    const after = document.createRange();
    after.setStartAfter(node);
    after.collapse(true);
    el.focus();
    placeCaret(after);
    emit();
  };

  useImperativeHandle(ref, () => ({
    insertVariable: (key) => insertNode(chip(`{{${key}}}`, "", key)),
  }));

  const style: CSSProperties = {
    minHeight: multiline ? 96 : 34,
    padding: "6px 10px",
    border: `1px solid ${invalid ? "#dc2626" : "#d1d5db"}`,
    borderRadius: 6,
    background: disabled ? "#f3f4f6" : "#fff",
    fontSize: 14,
    lineHeight: "22px",
    whiteSpace: multiline ? "pre-wrap" : "nowrap",
    overflowX: multiline ? undefined : "auto",
    overflowWrap: "anywhere",
    outline: "none",
  };

  return (
    <div
      ref={root}
      role="textbox"
      aria-label={ariaLabel}
      aria-multiline={multiline ? true : undefined}
      aria-invalid={invalid || undefined}
      aria-disabled={disabled || undefined}
      contentEditable={!disabled}
      suppressContentEditableWarning
      data-placeholder={placeholder}
      className="variable-text-editor"
      style={style}
      onInput={emit}
      onKeyUp={remember}
      onMouseUp={(e) => {
        // A click on a chip puts the cursor right after it (chips are not editable inside).
        const target = (e.target as HTMLElement).closest("[data-var]");
        if (target) {
          const range = document.createRange();
          range.setStartAfter(target);
          range.collapse(true);
          placeCaret(range);
        } else remember();
      }}
      onBlur={remember}
      onKeyDown={(e) => {
        if (e.key !== "Enter") return;
        e.preventDefault();
        if (!multiline) return;
        const sel = window.getSelection();
        const range = sel?.rangeCount ? sel.getRangeAt(0) : null;
        const node = document.createTextNode("\n");
        insertNode(node, range);
        // A new line at the very end only shows with something after it.
        const el = root.current!;
        if (el.lastChild === node) {
          el.appendChild(document.createElement("br"));
          const after = document.createRange();
          after.setStartAfter(node);
          after.collapse(true);
          placeCaret(after);
        }
      }}
      onPaste={(e) => {
        e.preventDefault();
        const text = e.clipboardData.getData("text/plain");
        const sel = window.getSelection();
        insertNode(
          document.createTextNode(multiline ? text : text.replace(/\n/g, " ")),
          sel?.rangeCount ? sel.getRangeAt(0) : null,
        );
      }}
      onDragOver={(e) => {
        if (e.dataTransfer.types.includes(VARIABLE_DRAG_TYPE))
          e.preventDefault();
      }}
      onDrop={(e) => {
        const key = e.dataTransfer.getData(VARIABLE_DRAG_TYPE);
        if (!key) return;
        e.preventDefault();
        const doc = document as Document & {
          caretRangeFromPoint?: (x: number, y: number) => Range | null;
        };
        const range = doc.caretRangeFromPoint?.(e.clientX, e.clientY) ?? null;
        insertNode(chip(`{{${key}}}`, "", key), range);
      }}
    />
  );
});
