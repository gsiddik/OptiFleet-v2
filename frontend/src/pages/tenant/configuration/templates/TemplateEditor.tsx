import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { apiClient, extractApiError } from "../../../../api/client";
import { FormField, inputStyle } from "../../../../components/FormField";
import { InfoTip } from "../../../../components/InfoTip";
import { Modal } from "../../../../components/Modal";
import { ErrorState, LoadingState } from "../../../../components/States";
import type {
  DocumentTypeOption,
  TemplateCatalog,
  TemplateEditorState,
  TemplateVariable,
} from "../../../../types";
import {
  blockAt,
  labelLookup,
  loadEditorState,
  loadHtml,
  markSection,
  readEditor,
  variableChip,
} from "./templateDom";

const VAR_DRAG_TYPE = "application/x-optifleet-template-variable";

/** A caret inside a variable chip moves to just after the chip (chips are one unit). */
function outsideChip(range: Range): Range {
  const start = range.startContainer;
  const chip = (
    start instanceof Element ? start : start.parentElement
  )?.closest("[data-var]");
  if (!chip) return range;
  const after = document.createRange();
  after.setStartAfter(chip);
  after.collapse(true);
  return after;
}

export interface TemplateEditorTarget {
  code: string | null;
  name: string | null;
  defaultName?: string;
  payload: { html?: string; editor?: TemplateEditorState } | null;
  versionId: string | null;
  title: string;
}

const EDITOR_CSS = `
[data-template-editor] { outline: none; }
[data-template-editor] .tpl-var { display:inline-block; padding:0 6px; margin:0 1px; border-radius:4px; background:#dbeafe; color:#1e3a8a; font-weight:600; font-size:0.92em; line-height:1.5; cursor:default; }
[data-template-editor] .tpl-var::before { content:'['; } [data-template-editor] .tpl-var::after { content:']'; }
[data-template-editor] .tpl-section { outline:2px dashed #a78bfa; outline-offset:2px; background:rgba(167,139,250,0.08); }
[data-template-editor] div.tpl-section, [data-template-editor] span.tpl-section { display:block; position:relative; padding:18px 6px 6px; margin:8px 0; }
[data-template-editor] span.tpl-section[data-section-mode="inline"] { display:inline; padding:0 4px; }
[data-template-editor] div.tpl-section::before, [data-template-editor] span.tpl-section[data-section-mode="block"]::before { content:'Repeats for each: ' attr(data-section-label); position:absolute; top:0; left:6px; font-size:11px; font-weight:600; color:#6d28d9; }
[data-template-editor] table { border-collapse: collapse; }
[data-template-editor] td, [data-template-editor] th { border:1px solid #d1d5db; min-width:40px; padding:4px; }
`;

const TOOLBAR: { label: string; title: string; run: () => void }[] = [
  { label: "B", title: "Bold", run: () => document.execCommand("bold") },
  { label: "I", title: "Italic", run: () => document.execCommand("italic") },
  {
    label: "U",
    title: "Underline",
    run: () => document.execCommand("underline"),
  },
  {
    label: "H1",
    title: "Heading 1",
    run: () => document.execCommand("formatBlock", false, "h1"),
  },
  {
    label: "H2",
    title: "Heading 2",
    run: () => document.execCommand("formatBlock", false, "h2"),
  },
  {
    label: "H3",
    title: "Heading 3",
    run: () => document.execCommand("formatBlock", false, "h3"),
  },
  {
    label: "¶",
    title: "Paragraph",
    run: () => document.execCommand("formatBlock", false, "p"),
  },
  {
    label: "⯇",
    title: "Align left",
    run: () => document.execCommand("justifyLeft"),
  },
  {
    label: "≡",
    title: "Align center",
    run: () => document.execCommand("justifyCenter"),
  },
  {
    label: "⯈",
    title: "Align right",
    run: () => document.execCommand("justifyRight"),
  },
  {
    label: "• List",
    title: "Bulleted list",
    run: () => document.execCommand("insertUnorderedList"),
  },
  {
    label: "1. List",
    title: "Numbered list",
    run: () => document.execCommand("insertOrderedList"),
  },
  { label: "↶", title: "Undo", run: () => document.execCommand("undo") },
  { label: "↷", title: "Redo", run: () => document.execCommand("redo") },
];

/**
 * Configuration → Document Template editor: a document editor (bold / italic / underline,
 * headings, alignment, lists, tables) with variables shown as chips and repeating blocks (Jobs,
 * Findings, Items) shown as marked containers. Variables and blocks are inserted from cards —
 * by click / Enter at the cursor, or by drag-and-drop. Saving sends the editor document; the
 * server builds the printable template from it and checks every variable.
 */
export function TemplateEditor({
  documentTypes,
  target,
  onClose,
  onSaved,
}: {
  documentTypes: DocumentTypeOption[];
  target: TemplateEditorTarget;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [code, setCode] = useState(target.code ?? "");
  const [name, setName] = useState(target.name ?? target.defaultName ?? "");
  const [changeSummary, setChangeSummary] = useState("");
  const [catalog, setCatalog] = useState<TemplateCatalog | null>(null);
  const [catalogError, setCatalogError] = useState<string | null>(null);
  const [unsupported, setUnsupported] = useState<string[]>([]);
  const [search, setSearch] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const [caretBlock, setCaretBlock] = useState<string | null>(null);
  const editor = useRef<HTMLDivElement>(null);
  const saved = useRef<Range | null>(null);
  const loaded = useRef(false);
  const labels = useMemo(() => labelLookup(catalog), [catalog]);

  useEffect(() => {
    if (!code) return;
    setCatalog(null);
    apiClient
      .get("/app/configuration/metadata", {
        params: { type: "TEMPLATE", code },
      })
      .then((res) => setCatalog(res.data.data.catalog))
      .catch((e) => setCatalogError(extractApiError(e).message));
  }, [code]);

  // Fill the editor once (when the variable labels are known).
  useEffect(() => {
    const root = editor.current;
    if (!root || !catalog || loaded.current) return;
    loaded.current = true;
    if (target.payload?.editor?.nodes)
      loadEditorState(root, target.payload.editor.nodes, labels);
    else if (target.payload?.html)
      setUnsupported(loadHtml(root, target.payload.html, labels));
    else root.innerHTML = "<p><br></p>";
  }, [catalog, labels, target.payload]);

  useEffect(() => {
    const remember = () => {
      const sel = window.getSelection();
      if (
        sel &&
        sel.rangeCount > 0 &&
        editor.current?.contains(sel.anchorNode)
      ) {
        saved.current = sel.getRangeAt(0).cloneRange();
        setCaretBlock(blockAt(sel.anchorNode, editor.current));
      }
    };
    document.addEventListener("selectionchange", remember);
    return () => document.removeEventListener("selectionchange", remember);
  }, []);

  function placeAfter(node: Node) {
    const root = editor.current!;
    root.normalize();
    const range = document.createRange();
    range.setStartAfter(node);
    range.collapse(true);
    const sel = window.getSelection();
    root.focus();
    sel?.removeAllRanges();
    sel?.addRange(range);
    saved.current = range.cloneRange();
  }

  /**
   * Where a table or block goes: after the paragraph / heading / list the cursor is in (a block
   * never goes inside a line of text), otherwise at the cursor.
   */
  function blockPosition(at: Range | null): Range | null {
    const root = editor.current;
    if (!root || !at || !root.contains(at.startContainer)) return null;
    let line: Node | null = null;
    for (
      let n: Node | null = at.startContainer;
      n && n !== root;
      n = n.parentNode
    ) {
      if (
        n instanceof HTMLElement &&
        /^(P|H[1-6]|LI|UL|OL|BLOCKQUOTE)$/.test(n.tagName)
      )
        line = n;
      if (n instanceof HTMLElement && /^(TD|TH|DIV)$/.test(n.tagName)) break;
    }
    if (!line) return at;
    const range = document.createRange();
    range.setStartAfter(line);
    range.collapse(true);
    return range;
  }

  function insertNode(node: Node, at: Range | null) {
    const root = editor.current;
    if (!root) return;
    const range =
      at && root.contains(at.startContainer) ? outsideChip(at) : null;
    if (range) {
      range.deleteContents();
      range.insertNode(node);
    } else root.appendChild(node);
    placeAfter(node);
  }

  /** Inserts a variable chip — a block field only inside its own block. */
  function insertVariable(
    variable: TemplateVariable,
    block: string | null,
    at: Range | null = saved.current,
  ) {
    setMessage(null);
    const where = at
      ? blockAt(outsideChip(at).startContainer, editor.current!)
      : null;
    if (block && where !== block) {
      setMessage(
        `"${variable.label}" belongs to the ${labels.block(block)} block: put the cursor inside a ${labels.block(block)} block (or insert one) first.`,
      );
      return;
    }
    insertNode(variableChip(document, variable.key, variable.label), at);
  }

  function insertBlock(name: string, asTable: boolean) {
    setMessage(null);
    const block = catalog?.blocks.find((b) => b.name === name);
    if (!block) return;
    if (asTable) {
      const table = document.createElement("table");
      table.setAttribute("border", "1");
      table.setAttribute("cellpadding", "4");
      table.setAttribute("style", "width:100%;border-collapse:collapse");
      const body = table.appendChild(document.createElement("tbody"));
      const head = body.appendChild(document.createElement("tr"));
      const row = body.appendChild(document.createElement("tr"));
      for (const field of block.fields) {
        head.appendChild(document.createElement("th")).textContent =
          field.label;
        row
          .appendChild(document.createElement("td"))
          .appendChild(variableChip(document, field.key, field.label));
      }
      markSection(row, name, false, "element", block.label);
      insertNode(table, blockPosition(saved.current));
    } else {
      const wrapper = document.createElement("div");
      markSection(wrapper, name, false, "block", block.label);
      const p = wrapper.appendChild(document.createElement("p"));
      block.fields.forEach((field, i) => {
        if (i) p.appendChild(document.createTextNode(" — "));
        p.appendChild(variableChip(document, field.key, field.label));
      });
      insertNode(wrapper, blockPosition(saved.current));
    }
  }

  function insertTable() {
    const table = document.createElement("table");
    table.setAttribute("border", "1");
    table.setAttribute("cellpadding", "4");
    table.setAttribute("style", "width:100%;border-collapse:collapse");
    const body = table.appendChild(document.createElement("tbody"));
    for (let r = 0; r < 2; r++) {
      const tr = body.appendChild(document.createElement("tr"));
      for (let c = 0; c < 3; c++)
        tr.appendChild(
          document.createElement(r === 0 ? "th" : "td"),
        ).appendChild(document.createElement("br"));
    }
    insertNode(table, blockPosition(saved.current));
  }

  const document_ = (): TemplateEditorState => ({
    version: 1,
    nodes: editor.current ? readEditor(editor.current) : [],
  });

  async function showPreview() {
    setSaveError(null);
    try {
      const res = await apiClient.post("/app/configuration/preview", {
        type: "TEMPLATE",
        code,
        editor: document_().nodes,
      });
      setPreview(res.data.data.html);
    } catch (e) {
      setSaveError(extractApiError(e).message);
    }
  }

  async function save() {
    setSaveError(null);
    if (!code) return setSaveError("Choose the Document Type.");
    if (!name.trim()) return setSaveError("Name is required.");
    if (
      !editor.current?.textContent?.trim() &&
      !editor.current?.querySelector("[data-var]")
    )
      return setSaveError("The document is empty.");
    setSaving(true);
    try {
      const payload = { editor: document_() };
      if (target.versionId)
        await apiClient.put(`/app/configuration/versions/${target.versionId}`, {
          payload,
          change_summary: changeSummary || null,
        });
      else
        await apiClient.post("/app/configuration/versions", {
          type: "TEMPLATE",
          code,
          name: name.trim(),
          payload,
          change_summary: changeSummary || null,
        });
      onSaved();
    } catch (e) {
      setSaveError(extractApiError(e).message);
    } finally {
      setSaving(false);
    }
  }

  const matches = (v: TemplateVariable) =>
    !search ||
    `${v.label} ${v.category}`.toLowerCase().includes(search.toLowerCase());
  const categories = useMemo(() => {
    const map = new Map<string, TemplateVariable[]>();
    for (const v of catalog?.variables ?? [])
      if (matches(v)) map.set(v.category, [...(map.get(v.category) ?? []), v]);
    return [...map.entries()];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catalog, search]);

  const card = (v: TemplateVariable, block: string | null): ReactNode => (
    <button
      key={`${block ?? ""}:${v.key}`}
      type="button"
      draggable
      data-variable-card={block ? `${block}.${v.key}` : v.key}
      title={`${v.description} (${v.type})`}
      onDragStart={(e) => {
        e.dataTransfer.setData(VAR_DRAG_TYPE, JSON.stringify({ v, block }));
        e.dataTransfer.effectAllowed = "copy";
      }}
      onClick={() => insertVariable(v, block)}
      style={{
        display: "flex",
        justifyContent: "space-between",
        gap: 6,
        width: "100%",
        textAlign: "left",
        padding: "5px 8px",
        marginBottom: 4,
        border: "1px solid #bfdbfe",
        borderRadius: 6,
        background: "#eff6ff",
        cursor: "grab",
        fontSize: 12,
      }}
    >
      <span style={{ fontWeight: 600, color: "#1e3a8a" }}>{v.label}</span>
      <span style={{ color: "#6b7280", fontSize: 11 }}>{v.type}</span>
    </button>
  );

  const toolbarButton = (label: string, title: string, run: () => void) => (
    <button
      key={title}
      type="button"
      title={title}
      aria-label={title}
      onMouseDown={(e) => e.preventDefault()}
      onClick={run}
      className="btn-secondary"
      style={{ padding: "3px 8px", fontSize: 12, minWidth: 30 }}
    >
      {label}
    </button>
  );

  return (
    <div
      role="dialog"
      aria-label={target.title}
      style={{
        position: "fixed",
        inset: 0,
        background: "#f9fafb",
        zIndex: 60,
        overflowY: "auto",
      }}
      data-template-editor-page
    >
      <style>{EDITOR_CSS}</style>
      <div style={{ maxWidth: 1280, margin: "0 auto", padding: 16 }}>
        <div
          style={{
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            gap: 10,
            flexWrap: "wrap",
            marginBottom: 12,
          }}
        >
          <h1 style={{ fontSize: 20, margin: 0 }}>{target.title}</h1>
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn-secondary" onClick={onClose}>
              Cancel
            </button>
            <button
              className="btn-secondary"
              onClick={showPreview}
              disabled={!catalog}
            >
              Preview
            </button>
            <button
              className="btn-primary"
              onClick={save}
              disabled={saving || !catalog || unsupported.length > 0}
            >
              {saving ? "Saving…" : "Save Draft"}
            </button>
          </div>
        </div>
        <div
          className="card"
          style={{
            display: "grid",
            gridTemplateColumns:
              "repeat(auto-fit, minmax(min(100%, 240px), 1fr))",
            gap: "0 14px",
            marginBottom: 12,
          }}
        >
          <FormField
            label="Document Type"
            required
            hint="The printed document this template is for. The list contains every document the system can print."
          >
            <select
              aria-label="Document Type"
              value={code}
              disabled={!!target.code}
              onChange={(e) => setCode(e.target.value)}
              style={inputStyle}
            >
              <option value="">Select…</option>
              {documentTypes.map((d) => (
                <option key={d.key} value={d.key}>
                  {d.label}
                </option>
              ))}
            </select>
          </FormField>
          <FormField
            label="Name"
            required
            hint="A name for this template, e.g. Work Order with company logo text."
          >
            <input
              aria-label="Name"
              value={name}
              disabled={!!target.name}
              onChange={(e) => setName(e.target.value)}
              style={inputStyle}
            />
          </FormField>
          <FormField label="Change Summary">
            <input
              aria-label="Change Summary"
              value={changeSummary}
              onChange={(e) => setChangeSummary(e.target.value)}
              placeholder="What changed and why (optional)"
              style={inputStyle}
            />
          </FormField>
        </div>
        {catalogError && <ErrorState message={catalogError} />}
        {unsupported.length > 0 && (
          <div
            role="alert"
            className="card"
            style={{
              borderColor: "#f59e0b",
              background: "#fffbeb",
              fontSize: 13,
              marginBottom: 12,
            }}
            data-unsupported-template
          >
            <strong>
              This template uses a layout the visual editor cannot show exactly.
            </strong>{" "}
            To avoid changing it by accident, it cannot be saved from here:
            <ul style={{ margin: "6px 0 0" }}>
              {unsupported.map((u) => (
                <li key={u}>{u}</li>
              ))}
            </ul>
          </div>
        )}
        {saveError && (
          <div
            role="alert"
            style={{ color: "#b91c1c", fontSize: 13, marginBottom: 8 }}
            data-template-error
          >
            {saveError}
          </div>
        )}
        {!code ? (
          <div className="card" style={{ fontSize: 14 }}>
            Choose the Document Type to start.
          </div>
        ) : !catalog ? (
          <LoadingState />
        ) : null}
        <div
          style={{
            display: code && catalog ? "flex" : "none",
            gap: 12,
            alignItems: "flex-start",
            flexWrap: "wrap",
          }}
        >
          <div style={{ flex: "1 1 560px", minWidth: 0 }}>
            <div
              role="toolbar"
              aria-label="Formatting"
              style={{
                display: "flex",
                flexWrap: "wrap",
                gap: 4,
                marginBottom: 6,
              }}
            >
              {TOOLBAR.map((t) => toolbarButton(t.label, t.title, t.run))}
              {toolbarButton("Table", "Insert table", insertTable)}
            </div>
            <div
              ref={editor}
              contentEditable
              suppressContentEditableWarning
              role="textbox"
              aria-multiline
              aria-label="Document Editor"
              data-template-editor
              onMouseUp={(e) => {
                // A click on a chip puts the cursor right after it (chips are not editable inside).
                const chip = (e.target as HTMLElement).closest("[data-var]");
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
                if (e.dataTransfer.types.includes(VAR_DRAG_TYPE))
                  e.preventDefault();
              }}
              onDrop={(e) => {
                const raw = e.dataTransfer.getData(VAR_DRAG_TYPE);
                if (!raw) return;
                e.preventDefault();
                const { v, block } = JSON.parse(raw) as {
                  v: TemplateVariable;
                  block: string | null;
                };
                const doc = document as Document & {
                  caretRangeFromPoint?: (x: number, y: number) => Range | null;
                };
                insertVariable(
                  v,
                  block,
                  doc.caretRangeFromPoint?.(e.clientX, e.clientY) ??
                    saved.current,
                );
              }}
              style={{
                minHeight: 420,
                background: "#fff",
                border: "1px solid #d1d5db",
                borderRadius: 8,
                padding: 24,
                fontFamily: "sans-serif",
                fontSize: 13,
                overflowX: "auto",
              }}
            />
            {message && (
              <div
                role="status"
                style={{
                  color: "#92400e",
                  background: "#fffbeb",
                  fontSize: 12,
                  padding: "6px 8px",
                  borderRadius: 6,
                  marginTop: 6,
                }}
                data-editor-message
              >
                {message}
              </div>
            )}
          </div>
          <aside
            style={{ flex: "0 1 300px", minWidth: 0, maxWidth: "100%" }}
            aria-label="Variables"
            data-variable-panel
          >
            <div className="card" style={{ padding: 12 }}>
              <div style={{ fontWeight: 600, fontSize: 14, marginBottom: 4 }}>
                Variables
                <InfoTip label="Variables">
                  Click a card to insert it at the cursor, or drag it into the
                  document. In the printed document it is replaced by the real
                  value.
                </InfoTip>
              </div>
              <input
                aria-label="Search variables"
                placeholder="Search…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                style={{ ...inputStyle, marginBottom: 8 }}
              />
              {categories.map(([category, vars]) => (
                <div key={category} style={{ marginBottom: 8 }}>
                  <div
                    style={{
                      fontSize: 11,
                      color: "#6b7280",
                      textTransform: "uppercase",
                      margin: "4px 0",
                    }}
                  >
                    {category}
                  </div>
                  {vars.map((v) => card(v, null))}
                </div>
              ))}
              {(catalog?.blocks ?? []).map((b) => (
                <div
                  key={b.name}
                  style={{
                    borderTop: "1px solid #e5e7eb",
                    paddingTop: 8,
                    marginTop: 8,
                  }}
                  data-block-card={b.name}
                >
                  <div
                    style={{ fontWeight: 600, fontSize: 13, color: "#6d28d9" }}
                  >
                    {b.label} Block
                    <InfoTip label={`${b.label} Block`}>
                      {b.description} Its fields can only be used inside the
                      block.
                    </InfoTip>
                  </div>
                  <div style={{ display: "flex", gap: 6, margin: "6px 0" }}>
                    <button
                      type="button"
                      className="btn-secondary"
                      style={{ fontSize: 12, padding: "3px 8px" }}
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => insertBlock(b.name, true)}
                      data-insert-block-table={b.name}
                    >
                      + {b.label} table
                    </button>
                    <button
                      type="button"
                      className="btn-secondary"
                      style={{ fontSize: 12, padding: "3px 8px" }}
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => insertBlock(b.name, false)}
                      data-insert-block={b.name}
                    >
                      + {b.label} block
                    </button>
                  </div>
                  <div
                    style={{
                      fontSize: 11,
                      color: caretBlock === b.name ? "#15803d" : "#6b7280",
                      marginBottom: 4,
                    }}
                  >
                    {caretBlock === b.name
                      ? `Cursor is inside a ${b.label} block — fields can be inserted.`
                      : `Fields (inside a ${b.label} block):`}
                  </div>
                  {b.fields.filter(matches).map((f) => card(f, b.name))}
                </div>
              ))}
            </div>
          </aside>
        </div>
      </div>
      {preview !== null && (
        <Modal
          open
          title="Preview (sample data)"
          onClose={() => setPreview(null)}
          width={860}
        >
          <iframe
            title="Template preview"
            sandbox=""
            srcDoc={preview}
            style={{
              width: "100%",
              height: "65vh",
              border: "1px solid #e5e7eb",
              borderRadius: 6,
              background: "#fff",
            }}
            data-template-preview
          />
        </Modal>
      )}
    </div>
  );
}
