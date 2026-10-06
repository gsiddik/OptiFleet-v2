# i18n Structural Preparation — Status

Continuation checkpoint for the structural i18n preparation defined in
`docs/i18n/15-structural-i18n-execution-plan.md`.

- **Entry condition:** terminology fully resolved. `AWAITING_DECISION` = 0 in
  `docs/i18n/12-en-id-translation-dataset-final.csv`.
- **Scope:** make the source structurally translatable. This is **not** the i18n rollout: no i18n
  library is installed, no `t()` replacement and no language selector.
- **Progress tracking:** each phase marks its blocker resolved in `12` (`resolved_blockers` column).
  The per-blocker counts are in `14` → *Structural blocker progress*.

## Phases

| # | Phase | Blocker | Status |
|---:|---|---|---|
| S1 | Stable tab IDs | STABLE_TAB_ID (21 rows) | DONE |
| S2 | Status display label registry | STATUS_DISPLAY_REGISTRY (46 → 150 rows) | DONE |
| S3 | Runtime label mapping (+ workflow action verbs) | RUNTIME_LABEL_GENERATION (115 of 122), WORKFLOW_ACTION_LABEL_CORRECTION (33) | DONE |
| S4 | Full sentence templates | FULL_SENTENCE_TEMPLATE (192), RUNTIME_LABEL_GENERATION (remaining 7) | DONE |

## S1 — Stable tab IDs

**Change**
- `utils/tabs.ts`: `TabDef {id, label}` and `resolveTabId()`. The resolver accepts a stable id, an alias,
  or a legacy English label, ignoring case, spaces and punctuation.
- `hooks/useTabParam.ts`: the active tab is kept by id and mirrored in `?tab=<id>`.
  - Selection replaces the history entry, so it adds no extra Back step.
  - Other query parameters are kept.
  - The default tab removes `?tab=`.
- Pages converted:
  - **Work Order detail**: 16 tab ids. Visibility rules (orphaned, QC step, Issuance & Return,
    Tire Operations position) are keyed by id.
  - **Vehicle detail**: `wheels` keeps the existing `?tab=wheels` links. Permission gating is keyed by id,
    and a deep-linked tab the user may not see now falls back to Overview.
  - **Tenant detail (platform)**.
  - **Contract detail**: back link now uses `?tab=contract`.

**Backward compatibility**
- Old label links (`?tab=Contract`, `?tab=Module%20Entitlements`, `?tab=Issuance%20%26%20Return`)
  still open the right tab.
- Work Order and Vehicle now also accept `?tab=` for every tab. Before, only `?tab=wheels` worked on
  Vehicle and Work Order had no deep links.

**Tests**
- `frontend/tests/unit/tabs.test.ts`: 5 tests for the resolver (stable id, legacy label, alias,
  fallback, label lookup). Run with `npm run test:unit` (Node test runner, no new dependency).
- Browser e2e: 18 checks covering deep link by id, legacy label, click → URL, Overview removes the
  param, hidden/unknown tab falls back, back/forward, reload, other params kept, and platform tenant
  tabs. Existing Work Order tab e2e: 7/7.
- `tsc -b`: clean. Lint: 27 pre-existing warnings, 0 errors (unchanged).

**Remaining risk**: low. Tab labels are still English literals; rendering them through translation keys
is part of the i18n rollout.

## S2 — Status display label registry

**Change**
- `frontend/src/i18n/statusRegistry.ts`: 148 canonical codes, each mapped to a semantic key
  (`status.underReview`) and an English label.
  - `domain` splits ISSUED into `status.document.issued` (PO / RFQ) and `status.stock.issued`
    (part requests, planned parts).
  - An unknown code is shown as-is (never reformatted), with a one-time dev warning.
  - Lowercase legacy values (`active`) resolve too.
- `StatusBadge` renders `statusLabel(code, domain)`, and the local `SCRAPPED → SCRAP` map is gone. Colours
  stay keyed by the canonical code.
- Converted to the registry:
  - status filter chips on 17 list pages;
  - status `<option>` lists: Tire Operations, PR line status, MR inspection group;
  - 9 raw `{x.status}` renders;
  - the External WO invoice page, whose duplicated label maps were removed. Its badge now receives the
    code instead of the label, so it gets its proper colour.
- Dataset `12`:
  - 104 new `status.*` keys with Indonesian labels;
  - 44 existing `status.*` rows now carry the readable English label;
  - `status.paid` fixed from "LUNAS" to "Lunas".

**Not changed**: canonical status values, API filters (`?status=IN_PROGRESS`), workflow codes, the
database.

**Visible effect (English)**: badges and chips show readable labels instead of raw codes, e.g.
`UNDER_REVIEW` → "Under Review" (badges remain upper-cased by CSS) and `QC_PENDING` → "QC Pending".

**Tests**
- `tests/unit/statusRegistry.test.ts`, 5 tests:
  - every canonical code found in backend constants, workflow/status seeders, migration enums and
    frontend status lists has a registry entry (the scan is guarded against finding nothing);
  - every registry key has a final Indonesian translation in `12`;
  - the ISSUED domain split;
  - labels come from the registry;
  - unknown codes pass through unchanged.
- Browser e2e, 11 checks:
  - Work Order / Maintenance Request chips show labels;
  - list badges carry no raw codes;
  - chip clicks still send `status=IN_PROGRESS` / `status=ISSUED`;
  - PO and part-request ISSUED badges use their own domain;
  - External WO invoice badges.
- `tsc -b` clean; lint 27 pre-existing warnings, 0 errors.

**Remaining risk**: low. Non-status enums shown in badges (severity, event type) still pass through
unchanged and are handled in S3.

## S3 — Runtime label mapping

**Frontend**
- `i18n/locale.ts`: `AppLocale` (`en` | `id`), the system fallback `en`, `intlTag()`, and a cached
  `monthNames()` from `Intl`.
  - The resolution chain is not wired to any UI: no language selector and no i18n library.
- Formatters take an explicit locale instead of the browser's:
  - `utils/date.ts`: `formatDate`, `formatDateTime`, plus the new `formatTimestampDate` and `formatTime`;
  - `utils/number.ts`: `formatNumber` (new);
  - `utils/quantity.ts`: `formatQty` (now locale-aware).
- 82 `toLocaleString` / `toLocaleDateString` / `toLocaleTimeString` calls replaced, so output no
  longer depends on the browser locale:
  - timestamps go through `formatDateTime` / `formatTimestampDate` / `formatTime`;
  - odometers and KPI counts go through `formatNumber`;
  - platform contract, invoice, payment and pricing amounts go through the existing decimal-safe
    `formatMoney`. They used float `Number()` before.
- The hard-coded English month arrays are replaced by `Intl` (`date.ts`, Vehicle purchase month).
- Removed `replace(/_/g, ' ')` humanizers:
  - workflow automated actions now use the new `i18n/workflowAutomatedActions.ts`;
  - MR inspection group status, component asset status history and return status now use
    `statusLabel`;
  - document type now falls back to the code;
  - breadcrumb: the 11 segments without a label now have explicit labels.
- `i18n/workflowActionVerbs.ts` holds the owner-approved verb per target status.
  `isDefaultActionLabel()` replaces the old humanize comparison in `workflowButtons()`:
  - The legacy status-form label, the default verb and the action code all count as "not renamed".
  - It is a strict superset of the old rule, so existing tenants see the same buttons. A tenant's
    real rename still wins.

**Backend**
- `App\Domain\Shared\Support\StatusLabels`: mirror of the frontend status registry (key + English,
  ISSUED domains).
- `App\Domain\Workflow\Support\WorkflowActionVerbs`: verb per target status.
- Seeders:
  - `WorkflowDefaultsSeeder`: status `display_name` comes from `StatusLabels` (e.g. "QC Pending", no
    longer "Qc Pending"), and the default `action_label` is the verb (e.g. "Approve", no longer
    "Approved");
  - set names come from an explicit resource map, with identical text;
  - `AddWorkOrderExternalStatusSeeder` and `CorrectWorkOrderExternalTransitionsSeeder` were changed the
    same way.

**Historical integrity**
- The seeders publish only when no published version exists, so existing tenants' published and pinned
  configurations are unchanged. Only fresh installs get the new default labels.
- Canonical status codes, action codes and transitions are unchanged.

**Dataset**
- New keys: 11 `breadcrumb.*` and 10 `workflow.automatedAction.*`.
- RUNTIME_LABEL_GENERATION: 115 of 122 rows resolved. The other 7 are sentence templates, handled in
  S4.
- WORKFLOW_ACTION_LABEL_CORRECTION: 33 of 33 resolved.

**Tests**
- Frontend unit tests (20 in total across the four files):
  - `formatting.test.ts`: dates, months, numbers and quantities in `en` and `id`, with `en` output
    unchanged;
  - `breadcrumbLabels.test.ts`: every static route segment in `App.tsx` has a label;
  - `workflowLabels.test.ts`: verbs, rename detection, every backend automated action has a label, and
    every key exists in `12`.
- Backend:
  - `Unit/StatusLabelsTest` (7 tests): parity with the frontend status and verb registries, the domain
    split, and no reformatting;
  - `Feature/WorkflowDefaultLabelsTest` (3 tests): seeded display names come from the registry, default
    action labels are verbs, codes are canonical, and set names are unchanged;
  - existing workflow suites (Builder / Engine / Migration) green.
- Browser e2e, 7 checks:
  - Work Order IN_PROGRESS buttons keep their module labels on legacy configurations;
  - no browser-locale date strings;
  - Intl month list;
  - grouped odometer;
  - explicit breadcrumb;
  - platform date format;
  - no page errors.
- `npm run build` passes. Lint: 27 pre-existing warnings, 0 errors.

**Visible effect (English)**
- Timestamps that used the browser format now read "06 Oct 2026, 14:05".
- Scheduler times are 24-hour.
- Platform money shows two decimals.
- Automated action checkboxes read "Send notification".

## S4 — Full sentence templates

**Change**
- Backend `App\Domain\Shared\Support\Messages` and frontend `i18n/messages.ts` are keyed catalogs.
  - Each entry is one complete English sentence with named `{{param}}` placeholders, keyed by its
    dataset key.
  - `Messages::text()` / `message()` render it. A missing parameter stays visible, and an unknown
    backend key throws.
  - This is the English source only: no i18n library, no `__()`, no `t()`.
- 64 backend call sites in 40 files and 12 frontend call sites now render a template instead of
  concatenating fragments. Areas covered:
  - entitlement, bundle and module errors;
  - notification and template validation;
  - procurement and goods receipt;
  - work order, part request, return and sale errors;
  - tire import, inspection reasons, wheel configuration, decision engine;
  - role permissions, file upload limits, maintenance assessment;
  - intelligence data-readiness reasons;
  - seeded set names;
  - contract notes, default complaints and transfer notes.
- Grammar-changing branches are separate keys, never a word passed as a parameter:
  - approve vs cancel part request, each with or without a Work Order number;
  - repair vs retread form title, and duplicate vs invalid import row;
  - with or without product code, additional work, detail or leak test;
  - "closest to body", and updated vs unmapped mapping history;
  - truck type suffix, and an unset vehicle type;
  - approved / rejected / cancelled part request.
- Humanized codes inside sentences (bead and inner-liner conditions) now come from explicit condition
  keys.

**English output**: unchanged. Every template reproduces the previous concatenated text byte for byte
(asserted in tests). Existing backend tests that assert error messages pass unchanged.

**Dataset**
- 25 new split keys with Indonesian translations.
- 6 templates aligned to the source English.
- The superseded templates and the 91 retired fragments point to their replacements (`superseded_by`).
- FULL_SENTENCE_TEMPLATE: 192 of 192 resolved. RUNTIME_LABEL_GENERATION: 122 of 122.

**Tests**
- Backend `Unit/MessagesTest` (3 tests):
  - every catalog key exists in `12` with identical English and the same parameters in the
    Indonesian;
  - rendering reproduces the previous English;
  - a missing parameter stays visible and an unknown key throws.
- Frontend `messages.test.ts` (3 tests): the same parity and rendering checks. A shared CSV reader lives
  in `tests/unit/support/dataset.ts`.
- Browser e2e, 4 checks:
  - the part-request approve confirmation is one whole sentence;
  - wheel position descriptions render;
  - no unrendered `{{placeholders}}` appear;
  - no page errors.
- Lint: 27 pre-existing warnings, 0 errors. `npm run build` passes.

**Remaining risk**: low.
- Pluralization still uses "(s)" in English. ICU `_one` / `_other` forms are part of the rollout; the
  Indonesian strings need no plural forms.
- System-generated notes stored in records (contract notes, default complaints) are stored as rendered
  English, as before. Storing `{code, params}` instead is S5 / rollout work.
