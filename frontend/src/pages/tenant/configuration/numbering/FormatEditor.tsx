import { forwardRef, useEffect, useImperativeHandle, useRef } from "react";
import type { NumberingSegment } from "../../../../types";
import { mergeLiterals } from "./numberingFormat";

export interface FormatEditorHandle {
  /** Inserts a token chip at the last caret position (or at the end). */
  insertToken: (token: string) => void;
}

export const TOKEN_DRAG_TYPE = "application/x-optifleet-numbering-token";

const chipStyle =
  "display:inline-block;margin:0 1px;padding:0 6px;border-radius:4px;background:#dbeafe;color:#1e3a8a;font-weight:600;font-size:12px;line-height:20px;cursor:default;";

function chip(token: string, label?: string): HTMLSpanElement {
  const el = document.createElement("span");
  el.contentEditable = "false";
  el.dataset.token = token;
  el.setAttribute("style", chipStyle);
  el.setAttribute("title", label ? `${token} — ${label}` : token);
  el.textContent = token;
  return el;
}

function read(root: HTMLElement): NumberingSegment[] {
  const out: NumberingSegment[] = [];
  const walk = (node: Node) => {
    node.childNodes.forEach((child) => {
      if (child.nodeType === Node.TEXT_NODE)
        out.push({
          type: "literal",
          value: (child.textContent ?? "").replace(/[\r\n ]/g, (c) =>
            c === " " ? " " : "",
          ),
        });
      else if (child instanceof HTMLElement && child.dataset.token)
        out.push({ type: "token", token: child.dataset.token });
      else walk(child);
    });
  };
  walk(root);
  return mergeLiterals(out);
}

/**
 * The Format field: a one-line editor where custom text is typed freely and tokens are chips
 * (inserted from the cards by click — at the caret — or by drag-and-drop). A chip is one
 * unit, so literal text that happens to contain a token name stays text.
 */
export const FormatEditor = forwardRef<
  FormatEditorHandle,
  {
    initial: NumberingSegment[];
    labels: Record<string, string>;
    onChange: (segments: NumberingSegment[]) => void;
    invalid?: boolean;
  }
>(function FormatEditor({ initial, labels, onChange, invalid }, ref) {
  const root = useRef<HTMLDivElement>(null);
  const saved = useRef<Range | null>(null);

  // The DOM is the editing state; it is filled once from the initial segments.
  useEffect(() => {
    const el = root.current;
    if (!el) return;
    el.replaceChildren(
      ...initial.map((s) =>
        s.type === "literal"
          ? document.createTextNode(s.value)
          : chip(s.token, labels[s.token]),
      ),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const remember = () => {
      const sel = window.getSelection();
      if (sel && sel.rangeCount > 0 && root.current?.contains(sel.anchorNode))
        saved.current = sel.getRangeAt(0).cloneRange();
    };
    document.addEventListener("selectionchange", remember);
    return () => document.removeEventListener("selectionchange", remember);
  }, []);

  const emit = () => root.current && onChange(read(root.current));

  const insertAt = (range: Range | null, node: Node) => {
    const el = root.current;
    if (!el) return;
    if (range && el.contains(range.startContainer)) {
      // Never inside a chip: a caret within one moves to just after it.
      const start = range.startContainer;
      const inChip = (
        start instanceof Element ? start : start.parentElement
      )?.closest("[data-token]");
      if (inChip) {
        range.setStartAfter(inChip);
        range.collapse(true);
      }
      range.deleteContents();
      range.insertNode(node);
    } else {
      el.appendChild(node);
    }
    // insertNode splits text nodes (leaving empty ones the browser's caret trips over): merge them,
    // then put the caret right after what was inserted.
    el.normalize();
    const after = document.createRange();
    const next = node.nextSibling;
    if (next && next.nodeType === Node.TEXT_NODE) after.setStart(next, 0);
    else after.setStartAfter(node);
    after.collapse(true);
    // Back to the Format with the cursor after the inserted part, so typing continues there.
    el.focus();
    const sel = window.getSelection();
    sel?.removeAllRanges();
    sel?.addRange(after);
    saved.current = after.cloneRange();
    emit();
  };

  useImperativeHandle(ref, () => ({
    insertToken: (token: string) =>
      insertAt(saved.current, chip(token, labels[token])),
  }));

  return (
    <div
      ref={root}
      role="textbox"
      aria-label="Format"
      aria-invalid={invalid || undefined}
      contentEditable
      suppressContentEditableWarning
      data-format-editor
      onInput={emit}
      onKeyDown={(e) => {
        if (e.key === "Enter") e.preventDefault();
      }}
      onPaste={(e) => {
        e.preventDefault();
        const text = e.clipboardData
          .getData("text/plain")
          .replace(/[\r\n]/g, "");
        const sel = window.getSelection();
        insertAt(
          sel && sel.rangeCount ? sel.getRangeAt(0) : saved.current,
          document.createTextNode(text),
        );
      }}
      onMouseUp={(e) => {
        // A click on a chip puts the cursor right after it (chips are not editable inside).
        const chip = (e.target as HTMLElement).closest("[data-token]");
        if (!chip) return;
        const range = document.createRange();
        if (chip.nextSibling?.nodeType === Node.TEXT_NODE)
          range.setStart(chip.nextSibling, 0);
        else range.setStartAfter(chip);
        range.collapse(true);
        const sel = window.getSelection();
        sel?.removeAllRanges();
        sel?.addRange(range);
      }}
      onDragOver={(e) => {
        if (e.dataTransfer.types.includes(TOKEN_DRAG_TYPE)) e.preventDefault();
      }}
      onDrop={(e) => {
        const token = e.dataTransfer.getData(TOKEN_DRAG_TYPE);
        if (!token) return;
        e.preventDefault();
        const doc = document as Document & {
          caretRangeFromPoint?: (x: number, y: number) => Range | null;
          caretPositionFromPoint?: (
            x: number,
            y: number,
          ) => { offsetNode: Node; offset: number } | null;
        };
        let range: Range | null =
          doc.caretRangeFromPoint?.(e.clientX, e.clientY) ?? null;
        if (!range && doc.caretPositionFromPoint) {
          const pos = doc.caretPositionFromPoint(e.clientX, e.clientY);
          if (pos) {
            range = document.createRange();
            range.setStart(pos.offsetNode, pos.offset);
            range.collapse(true);
          }
        }
        insertAt(range, chip(token, labels[token]));
      }}
      style={{
        minHeight: 34,
        padding: "6px 10px",
        border: `1px solid ${invalid ? "#dc2626" : "#d1d5db"}`,
        borderRadius: 6,
        fontFamily: "ui-monospace, monospace",
        fontSize: 14,
        whiteSpace: "pre",
        overflowX: "auto",
        background: "#fff",
        outline: "none",
      }}
    />
  );
});
