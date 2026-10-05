# Goods Receipt Consolidation & User-Friendly Configuration — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 7e8ee00 (merged into the branch as
4da7093, no content difference at start).

| Phase | Scope | Status | Commit |
|---|---|---|---|
| 1 | Goods Receipt moved to Inventory (replaces the duplicate "Receiving" entry) | DONE | e937351 |
| 2–4, 7 | Document Type Registry, Document Numbering builder (no JSON) | DONE | 84a4fcd |
| 5–6 | Document Template visual editor + variable / block builder | DONE | 00cb8ac |
| 8 | Notification rules + messages as forms (no JSON) | DONE | 3218608 |
| 12 | Seeders (showcase configurations, defaults alignment) | DONE | 9b3c4e4 |
| 9–11, 13–18 | Defaults protection, UX, security, test matrices, gates | DONE | see below |

## Owner decision (DECISION REQUIRED, answered)

**Numbering initials (TENANT / BRANCH / WORKSHOP / WAREHOUSE)** — optional override. Filled: that
text is used for every document of the configuration. Empty: the code of the tenant / branch /
workshop / warehouse the document belongs to (existing behaviour). The preview uses the typed
value, or a sample code from the tenant when empty.

No other business decision was needed: every default template converts to the visual editor
losslessly (tested), and the notification forms map the existing schema 1:1.

## Phase 1 — Goods Receipt

- Inventory → "Receiving" pointed at the same Goods Receipt list as Procurement → Goods Receipt.
  One entry remains: **Inventory → Goods Receipt** (`/app/goods-receipts`, `goods_receipt.view`,
  module PROCUREMENT). Routes, page, PO "Receive" deep links, permissions and the backend are
  unchanged; no logic was duplicated or removed.

## Document Type Registry (Phase 7)

`DocumentTypeRegistry` is the single list of document types with their display names, whether
they support numbering, and (from `TemplateVariableRegistry`) templates. Numbering / template
metadata, the editors' Document Type dropdowns, and `store` validation (unknown type → 422) use it.

## Document Numbering (Phases 2–4)

- Builder: Document Type, Name, a Format editor with token cards (DOC, TENANT, BRANCH, WORKSHOP,
  WAREHOUSE, YYYY, YY, MMMM, MMM, MM, DD, SEQ, SEQ:N; ITEMTYPE / CG for Product SKU) inserted at
  the cursor by click or drag; typed text is literal ("RPO-", "DOCSTORE" stays literal); tokens may
  repeat. Parameter fields appear only for tokens in use (Doc Code Name, initials, Sequential
  Digit — numeric text, 1–12). Reset rule, Restart Numbering.
- Live **Format Preview** comes from the backend (`DocumentNumberingService::preview`), the only
  generator; the stored payload is the existing JSON (`format`, `doc_code`, initials, …).
- Example (owner): `RPO-TENANT/WORKSHOP/MM/YYYY/SEQ:N`, Tenant Initial ALP, Workshop Initial WSBDG,
  6 digits, October 2026 → Expected `RPO-ALP/WSBDG/10/2026/000001`, Actual
  `RPO-ALP/WSBDG/10/2026/000001` (DocumentNumberingBuilderTest, ConfigurationSeederTest, e2e).
- Server validation: known tokens only, `:N` on SEQ only (1–12), no stray braces, initials /
  doc code ≤ 30 characters without braces.
- System Default vs Custom shown per document type; New Draft starts from the active
  configuration; Return to System Default archives the tenant's published custom version.

## Document Template (Phases 5–6)

- Full-screen editor: Document Type, Name, Change Summary; WYSIWYG toolbar (bold, italic,
  underline, headings, paragraph, alignment, lists, table, undo / redo); variables panel with
  search and categories; variables and blocks inserted by click or drag (keyboard accessible);
  variables as chips, Jobs / Findings / Items as visual repeating containers (table row or block).
  Block fields are only insertable inside their block.
- Saved as `{html, editor}`: the server compiles the HTML from the editor document
  (`TemplateDocumentCompiler`), allowlisting tags / attributes / CSS; the client's HTML is never
  trusted. `TemplateValidator` checks variables and scope on publish. Preview uses sample data in a
  sandboxed iframe.
- Legacy: all 15 default templates survive the sanitizer losslessly and open in the editor without
  the "unsupported" banner; an unsupported legacy structure would disable saving instead of losing
  content.
- Configuration editor requests skip TrimStrings / ConvertEmptyStringsToNull so typed spacing is
  kept exactly.

## Notification (Phase 8)

Audit: rules (`NotificationRule`, not versioned) were edited as raw JSON; message templates
(versioned `NOTIFICATION` configurations) had no editor.

- **Rules** tab: list with event names, recipients, conditions in words, escalation; a form for
  create / edit / view — event, name, channels, recipient rows (type + user / role / permission
  picker or email), "Only send when…" (event fields, friendly operators, match all / any), and
  "Escalate if not handled" (wait minutes, escalation recipients, status condition). System Default
  rules open read-only; nested condition groups are shown and kept unchanged.
- **Messages** tab: System Default / Custom per event; In-App text and Email subject / text with
  variable chips (shared `VariableTextEditor`); preview with sample values; Draft → Publish →
  Return to System Default. Typed braces never become variables.
- Backend: metadata `type=NOTIFICATION`, NOTIFICATION preview, rule shape validation on create
  and update (channels, recipient types and identifiers, operators, escalation), tenant message
  templates only for configurable events. No new semantics.

## Seeders (Phase 12)

- Platform numbering defaults cover every numbering document type and use builder tokens only.
- `ConfigurationShowcaseSeeder` (demo layer, BETA tenant — `beta.admin@optifleet.test`): RPO
  example (published), `{DOC}/{YYYY}/{SEQ:1}`, `{BRANCH}-{YY}-{MM}-{SEQ:4}`,
  `{WAREHOUSE}/{MMMM}/{YYYY}/{SEQ:5}`, `DOCSTORE-{DOC}-{YYYY}{MM}/{DOC}{SEQ:4}` (drafts), a Work Order
  template from the visual editor (variables, Jobs table, Findings block, formatting), a low-stock
  rule with condition and escalation. ALPHA keeps System Defaults. Idempotent.

## Security / integrity

Permission checks unchanged (`numbering.*`, `document_template.*`, `notification_rule.manage`;
no role names). Tenant scoping unchanged. Template HTML sanitized server-side; no expressions
(conditions are ConditionEvaluator operators). Historical documents keep their numbers and
rendered content; published versions are never overwritten.

## Validation

See the final report of this session for the executed results (targeted tests, full non-Mongo
regression, lint, build, seeders, e2e). MongoDB tests: NOT RUN (MongoDB unavailable).
