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
| S5 | Error code decoupling | ERROR_CODE_DECOUPLING (98 of 125) | DONE; 25 rows BLOCKED_BY_MONGODB_TEST_ENVIRONMENT, rich-text rows ACCEPTED_I18N_ROLLOUT_ITEM |
| S6 | Document generation locale snapshot | PRINT_LOCALE_SNAPSHOT (D1, D3, D4) | DONE |
| S7 | Laravel validation localization prep | FRAMEWORK_VALIDATION_LOCALIZATION (36) | DONE |
| S8 | Database localization prep | DATABASE_LOCALIZATION (422 of 422 after the system role code) | DONE |
| — | Readiness closure | System role code, rich text / plural rollout items, concurrency test | DONE; 25 MongoDB rows BLOCKED |

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

## S5 — Error code decoupling

**Rule**: no logic branches on English text. A machine-readable code is carried next to the
(unchanged) English text, and code paths read the code.

**Change**
- `Messages::make(key, params)` returns `{code, params}` (code = dataset key; unknown key throws),
  and `Messages::render()` turns it back into text. `Messages::text()` accepts nested messages and
  list parameters, so a composite reason renders from codes alone.
- Used-tire decision engine (`UsedTireDecisionEngine`):
  - every reason, open item and follow-up is built as `{code, params}`;
  - wear patterns, damage types, damage locations, damage fields and grooves map to explicit keys
    instead of humanized enum strings;
  - the output adds `reason_codes`, `open_item_codes` and `follow_up_codes`; the existing `reasons`,
    `open_items` and `follow_ups` text arrays are rendered from the codes and are byte-identical.
- `tire_used_inspections` gains nullable `reason_codes` and `follow_up_codes` (jsonb). Migration
  `2026_10_12_000001` is additive and guarded by `hasColumn`. Inspections recorded before it keep
  `NULL` codes and their stored text; nothing is backfilled or rewritten.
- `CodedValidationException::forField(field, key, params)` is a `ValidationException` that also
  carries codes. The API error renderer adds `codes: {field: {code, params}}` beside the unchanged
  `message` / `errors`. The 422 contract is additive.
- `TireOperationService`: the three position errors (not a position of the vehicle, no tire data yet,
  already in an open operation) use coded exceptions.
- Frontend `TireOperationFormPage`: the selection notice is `{code, text}`. The "Open Vehicle Details"
  link is shown for `POSITION_NO_TIRE_DATA` instead of `notice.includes('no tire data')`.
  `ApiErrorShape.codes` is typed.
- Tire import (`TireImportService`): headers and the sheet name are matched by stable column id. Each
  column accepts its English or Indonesian header (case-insensitive), and the sheet `Fill Here` or
  `Isi Di Sini`. The generated template and error texts are unchanged.
- `UsedTireUsageRestrictions`: the installation warning is one template
  (`tire.reasons.positionRestrictionWarning`) instead of two fragments.

**Unchanged**: business rules, decision outcomes, thresholds, HTTP status codes, response fields
already in the contract, and the English wording of every message (asserted in tests).

**Dataset**
- 26 new code keys with Indonesian translations (damage types, locations, fields, grooves, wear
  patterns and composite reasons). The two fragments of the restriction warning are superseded.
- ERROR_CODE_DECOUPLING: 98 of 124 resolved.

**Tests**
- `Unit/UsedTireDecisionEngineTest`: every reason and follow-up has a code that renders to exactly
  its text.
- `Feature/TireUsedInspectionTest`: codes are stored next to the unchanged text.
- `Feature/TireOperationTest`: position errors carry `codes` with the dataset key and parameters.
- `Feature/TireImportTest`: an Indonesian-header workbook with the `Isi Di Sini` sheet imports.
- `Unit/MessagesTest`: the new keys pass the dataset parity check.
- Browser e2e, 4 checks: the no-tire-data notice text is unchanged; the Vehicle Details link is
  driven by the code and points to `?tab=wheels`; no page errors.
- Frontend: `tsc -b`, 23 unit tests and lint (27 pre-existing warnings, 0 errors) pass.
- Full backend regression: 1132 passed. MongoDB-backed Analytics / Intelligence suites NOT RUN (no
  MongoDB or PHP `mongodb` extension in this environment).

**Open (26 rows)**
- 25 Intelligence rows (data readiness, RUL, feature extractors, training, diagnostics,
  recommendations, platform intelligence controllers). These paths are MongoDB-backed. MongoDB and the
  PHP `mongodb` extension are not available here, so their tests are NOT RUN. They are left
  unchanged rather than modified without verification. They do not branch on English text; the
  remaining work is to emit `{code, params}` alongside the text.
- 1 row in `TireOperationParts.tsx`: a sentence with an inline link (rich text). It needs a
  `Trans`-style component and belongs to the rollout.

**Remaining risk**: low.
- `codes` is an additive response field.
- The migration is additive with nullable columns.
- Older inspections have no codes. A reader must fall back to the stored text when
  `reason_codes` is `NULL`.

## S6 — Document generation locale snapshot (PRINT_LOCALE_SNAPSHOT)

Owner decisions applied:
- **D1 language priority:** explicit Print/Export choice → user preferred locale → tenant default →
  English. A document is never bilingual.
- **D3 formatting:** the document locale decides number and date presentation only:
  - `id`: "1.234,56" and "6 Oktober 2026";
  - `en`: "1,234.56" and "October 6, 2026".
- **D4 reprint integrity:** a reprint uses the locale and template version of the original generation.

**Schema** (migration `2026_10_12_000002`, additive and idempotent)
- `document_generations` has:
  - `id`, `tenant_id`, `document_type`, `source_entity_type`, `source_entity_id`;
  - `recipient_partner_id` (only for the RFQ, which is printed per invited vendor);
  - `locale`;
  - `template_id` (configuration set), `template_version_id`, `template_version`;
  - `generated_at` (microseconds), `generated_by`, `created_at`.
- There is no file path or checksum: PDFs are rendered on demand, as before, so nothing is stored.
- **Immutable.** The model refuses update and delete, and a PostgreSQL trigger refuses `UPDATE`. The
  only exception is the `generated_by` foreign key's own `ON DELETE SET NULL`.
- `tenants.default_locale` and `users.preferred_locale` are nullable. Null means not set, so English.
  There is no UI to set them yet; the language selector is rollout work.

**Behaviour** (`DocumentGenerationService`, controller trait `PrintsDocuments`)

| Endpoint | Meaning |
|---|---|
| `GET …/print` | Reprint the latest generation. Only the very first print of a document creates one (in `?locale=` or the D1 default), under a PostgreSQL advisory lock, so concurrent first prints create one row. |
| `GET …/print?generation=<id>` | Reprint that generation. The id must belong to this tenant and this document, otherwise 404. |
| `GET …/print/generations` | History, newest first, with a sequence number. |
| `POST …/print/generations {locale?}` | Generate New Version: a new row from the current effective template. History is never overwritten. |

- Covers all 7 printed documents:
  - Work Order;
  - Maintenance Memo;
  - Workshop Invoice;
  - RFQ (per vendor);
  - Purchase Order;
  - Return Order;
  - Work Authorization Letter (`…/authorization`, `…/authorization/generations`).
- Existing URLs, permissions, tenant and data-scope checks are unchanged. The Return Order print still
  records `printed_at`.
- PDF responses carry `X-Document-Generation-Id`, `X-Document-Locale` and
  `X-Document-Template-Version`.
- Rendering uses the **pinned** `ConfigurationVersion`, even after it is archived.
  - A version may carry per-locale bodies (`payload.locales.<locale>.html`). Without one, its single
    `html` serves every locale, so no HTML is duplicated per language.
  - Per-locale bodies pass the same variable whitelist on publish, and only `en` / `id` keys are
    accepted.
- The RFQ built-in body (no published template) is recorded with a null template version and renders
  as `default`.
- `{{generated_at}}` is the generation's time, so a reprint shows the original generation time.
- `DisplayFormat::money/quantity/date/dateTime` take the locale. The context builders format with the
  generation's locale at render time. Stored values and identifiers are never localized, and nothing
  localized is written to business tables.

**Frontend**: a "Versions" button next to each print action opens the document's history:
- language, template version and generation time for each version;
- Open, which reprints that generation;
- Generate New Version, with a language choice of Default, English or Bahasa Indonesia.

The existing Print buttons are unchanged and now reprint the latest version.

**Behaviour change (approved, D3/D4)**
- Printed dates were ISO (`2026-10-06`). English documents now read "October 6, 2026", and date-times
  "October 6, 2026 14:05". English numbers are unchanged.
- A plain Print no longer silently picks up a newly published template. Generate New Version does.
  The comment in `DocumentTemplateTest` described the old behaviour; its assertions (the PDF still
  renders after a republish) pass.
- `PurchaseOrderFromQuotationTest` asserted the ISO date in the print context. Its assertion now
  expects the D3 presentation ("October 1, 2026", "1 Oktober 2026"); the stored-date assertions are
  unchanged.

**Tests**
- `Feature/DocumentGenerationLocaleTest` (6 tests):
  - A: an explicit `id` print stores `id`, with id date formatting.
  - B: after the user and tenant switch to `en`, a reprint is still `id` and creates no new row.
  - C: Generate New Version with `en` adds a row and leaves the history unchanged.
  - D: after a template republish, the old generation renders its own (archived) version, and a plain
    print stays on it.
  - The D1 priority chain, including 422 for an unsupported locale.
  - Immutability at both the model and database level.
  - Per-locale bodies, with fallback and whitelist validation.
  - Tenant and document isolation of generation ids.
  - A single first generation across repeated prints.
- `Unit/DisplayFormatTest`: `en` / `id` money, quantity, date and date-time; values are not mutated.
- All existing print tests pass unchanged: DocumentTemplate, RfqVendor, PurchaseReturn,
  PurchaseOrderQuantity, WorkOrderExternalService, ExternalWorkOrder, WorkshopInvoice and
  ConfigurationAuditAndRegression (93 tests).
- Browser e2e, 7 checks:
  - Print returns a generation;
  - the Versions list;
  - generating a Bahasa Indonesia version opens it;
  - reopening the older version keeps its locale;
  - the history count;
  - no page errors.
- Frontend: `tsc -b`, build, 23 unit tests, lint (27 pre-existing warnings, 0 errors).
- Full backend regression: 1137 passed, 1 failed. The failure was the ISO-date assertion above, fixed
  and re-run green with its whole test class. The existing `Unit/DisplayFormatTest` cases are kept, and
  the locale cases are added to that class (4 passed). MongoDB suites NOT RUN.

**Remaining risk**
- **Live data.** A generation pins locale and template, not data. A reprint renders the document's
  current data, exactly as before; the WAL already prints from its own frozen snapshot columns. Storing
  rendered output would need the `file_path`/`checksum` columns, which were left out as not needed now.
- **Concurrency.** The advisory lock serializes generation creation per document. Covered since the
  readiness closure by a true-parallel multi-process test (`DocumentGenerationConcurrencyTest`, see
  *Readiness closure*).
- **Template labels.** Document labels inside templates (headings, captions) are still English
  template text. Indonesian bodies are authored per version under `locales.id` at rollout.

## S7 — Laravel validation localization prep

**Change**
- `backend/lang/en/*.php` publishes the framework defaults unchanged (`lang:publish`). A test asserts
  that `lang/en/validation.php` is identical to the framework file, so English wording cannot drift.
- `backend/lang/id/validation.php` holds the 36 Indonesian framework messages from dataset `12`
  (FRAMEWORK_VALIDATION_LOCALIZATION).
  - Per-key fallback to English (`app.fallback_locale`) covers any rule not listed.
  - Empty `custom` (field-specific wording) and `attributes` (field display names) sections are the
    place for rollout wording. Without them, Laravel derives names from field keys, as in English.
- Business validation messages are not Laravel rules. They stay in the keyed `Messages` catalog (S4)
  and `CodedValidationException` (S5), whose dataset keys already have their Indonesian translations.
- `ResolveRequestLocale` middleware (alias `request.locale`, on every authenticated API route, after
  `tenant.context`) resolves the request locale with the same chain as documents: user preferred
  locale → tenant default → English. Browser `Accept-Language` is deliberately not used.
- **Off by default:** `app.runtime_locale_resolution` (env `APP_RUNTIME_LOCALE_RESOLUTION`) is
  `false`, so every response stays English until the rollout. The preference columns are also unset
  everywhere.

**Unchanged**: every validation rule, HTTP status code and the `message` / `errors` shape. Only the
wording can change, and only when the flag is on.

**Dataset**: FRAMEWORK_VALIDATION_LOCALIZATION 36 of 36.

**Tests** (`Feature/ValidationLocalizationTest`, 5 tests)
- With the flag off, a user and tenant on `id` still get English.
- With the flag on: nothing set gives English, the tenant default `id` gives Indonesian, and the
  user's `en` preference wins over it (and the reverse).
- A rule without an Indonesian message falls back to English.
- Every `lang/id` message equals dataset `12`, its English source equals `lang/en`, and it keeps
  every `:placeholder`. Every dataset framework row is present.
- `lang/en/validation.php` equals the framework defaults.
- Full backend regression: 1145 passed. MongoDB suites NOT RUN.

**Remaining risk**: low.
- Attribute display names (`vehicle id`) are still derived from field keys. Their Indonesian names are
  rollout content.
- Switching the flag on is a rollout decision. The frontend still shows mostly English UI.

## S8 — Database localization prep

No generic translation table and no `*_en` / `*_id` columns.

| Data | Strategy | Where |
|---|---|---|
| Platform-seeded system values: modules, vehicle categories, component groups, product categories, UoMs, tool / equipment types, storage requirements (83 values) | Canonical code + translation resource | `ReferenceLabels::SYSTEM_VALUES` maps `table → code → [dataset key, seeded English]`. `key()` returns the key only while the stored name is still the seeded default; a renamed value is the administrator's own text and is shown as stored. |
| Tenant-editable document templates | Localized versioned config | `payload.locales.<locale>.html` (S6) |
| Tenant-editable workflow action labels | Localized versioned config | `payload.locales.<locale>.action_labels.<action_code>`. `WorkflowLabels::actionLabel()` falls back to the transition's `action_label`, then the code. Validated on publish: supported locale, existing action code, non-empty text. |
| Tenant-editable notification wording | Localized versioned config | `payload.locales.<locale>.channels.<CHANNEL>.{subject, body}`. `NotificationTemplateService::render(..., ?locale)` falls back to the version's own channel. Validated on publish: supported locale, declared channel, body / subject rules, the event's variable whitelist. |
| System text written into a record (contract notes, default complaints, inspection condition text, transfer notes) | Preserved as stored | It becomes the record's own text (D2) and is never re-localized. |
| Configuration change summaries written by seeders | Preserved as stored | Historical metadata. |

**Runtime**
- Workflow action labels on the available-transitions API and the simulator use `app()->getLocale()`.
  This is English unless S7 runtime locale resolution is switched on.
- Notifications are rendered without a locale (English). Passing the recipient's locale is a rollout
  step.
- Template / workflow / notification payloads without `locales` behave exactly as before. Existing
  tenant configurations are untouched; nothing is migrated.

**Dataset**: DATABASE_LOCALIZATION 418 of 420.
- 91 seeded system-value rows.
- 269 seeded defaults of tenant-editable configuration. Their Indonesian content is seeded into
  `locales.id` at rollout.
- 48 frontend rows reclassified as static UI text or code-mapped labels, not stored in the database.
- 6 rows of text stored in records, preserved.
- 4 runtime validation messages reclassified.

**Open (2 rows, owner decision)**
- `Platform Superadmin` / `Full platform access.`: the seeded platform role.
- `roles` has no canonical code column; the role is identified by name. Mapping it needs either a
  `code` column on `roles` (schema change) or treating the seeded name as its identifier. Not decided
  unilaterally.

**Tests**
- `Feature/ReferenceLabelsTest` (3 tests):
  - the registry matches the seeded system rows exactly, with the same names;
  - every key exists in dataset `12` with the seeded English and an Indonesian text;
  - a renamed value is shown as stored.
- `Feature/LocalizedConfigurationTest` (2 tests):
  - notification and workflow per-locale wording with fallback;
  - publish-time validation rejects an unsupported locale, an unknown variable, an undeclared channel,
    an unknown action and an empty label.
- Workflow and notification suites pass unchanged: WorkflowBuilder, WorkflowEngine,
  WorkflowDefaultLabels, WorkflowMigration, NotificationEngine, NotificationConfigurationForm.
- Final full backend regression: 1150 passed. MongoDB suites NOT RUN.

**Remaining risk**
- No editor UI authors `locales` yet; that is rollout UI work.
- The workflow builder carries unknown payload keys through a save (`toPayload` spreads `extra`), so
  `locales` survives a workflow edit.
- The document template editor saves only `{editor, html}`. A new template version created there has
  no `locales` until the editor supports them. Earlier versions, and the generations pinned to them,
  are unaffected.

## Readiness closure

Work to close the blockers left after S8, per product owner decisions.

### Resolved blockers

| Blocker | Rows | Resolution |
|---|---:|---|
| DATABASE_LOCALIZATION | 2 (Platform Superadmin name / description) | Canonical `roles.code` (see *System role result*). Semantic keys `roles.system.platformSuperadmin.name` / `.description` supersede the name-derived keys; `ReferenceLabels` maps `roles.PLATFORM_SUPERADMIN`. DATABASE_LOCALIZATION 422 of 422. |
| Plural "(s)", count-free | 1 | `Allowed Item Type(s)` → `Allowed Item Types` (column header listing types, no count). Indonesian unchanged. |

### Accepted rollout items

| Item | Rows | Decision |
|---|---:|---|
| Rich text in `TireOperationParts.tsx` (`MissingTireNotice`) | 1 (+3 superseded fragments) | **ACCEPTED_I18N_ROLLOUT_ITEM** (owner). The dataset now holds one complete sentence, `tire.help.missingTireDataNotice`: `<position>{{position}}</position> has no tire data yet. Complete it in <link>Vehicle Details → Wheels Configuration</link> before continuing.` / `… belum memiliki data ban. Lengkapi di <link>Detail Kendaraan → Konfigurasi Roda</link> sebelum melanjutkan.` `<position>` is the `PositionLabel` component and `<link>` the router link (plain text without a vehicle). Rendered at rollout with the library's rich-text mechanism (e.g. `<Trans components={{ position, link }} />`). Not split, no concatenation workaround, no `Trans` installed now. |
| English plurals "(s)" | 31 dataset rows | **I18N_PLURALIZATION_ROLLOUT_ITEM** (flag on each row). Every remaining user-facing "(s)" depends on a count or list length (e.g. `{{count}} repairable damage(s)…`, `missing required module(s): {{modules}}`). English moves to ICU `_one` / `_other` with the i18n library; Indonesian already has its single form. Rewriting them now would be a grammar workaround. Out of scope: operator console output (`php artisan …` commands) and text stored into records (`n damage(s) recorded`, preserved as stored text, D2). The other `(s)` hits in the source are code (`(s) =>` lambdas). |

### Mongo blocker status

**BLOCKED_BY_MONGODB_TEST_ENVIRONMENT** (25 ERROR_CODE_DECOUPLING rows).
- Checked in this session: there is no `mongod` / `mongosh` binary, the PHP `mongodb` extension is not
  loaded, and `127.0.0.1:27017` refuses connections. The Analytics / Intelligence suites cannot run.
- The Intelligence code is therefore not modified, and no row is marked done from static inspection.
  Each row carries the note `BLOCKED_BY_MONGODB_TEST_ENVIRONMENT`. Report `14` shows them in the
  *Blocked (MongoDB test environment)* column.
- To finish, in an environment with MongoDB and `ext-mongodb`, add a stable UPPER_SNAKE_CASE code next
  to the unchanged English text (e.g. `INSUFFICIENT_TIRE_DATA`, `MODEL_NOT_READY`). Do not change
  scoring, prediction, aggregation, query semantics or thresholds. Then run the Analytics,
  Intelligence and full backend suites.

### System role result

- **Migration `2026_10_13_000001_add_code_to_roles`**
  - Adds `roles.code`: nullable, `string(64)`.
  - Partial unique index `roles_code_unique` on `(COALESCE(tenant_id, ''), code) WHERE code IS NOT
    NULL`: unique within the platform and within each tenant (a future per-tenant `TENANT_ADMIN` can
    exist once per tenant).
  - Idempotent and non-destructive.
  - Backfills `PLATFORM_SUPERADMIN` only when exactly one platform-scoped system role named
    "Platform Superadmin" has no code; otherwise nothing is guessed.
- **Model `Role`**
  - `code` must be UPPER_SNAKE_CASE and is immutable once set.
  - `Role::platformSuperadmin()` finds the role by code.
  - `Role::ensurePlatformSuperadmin()` adopts a pre-code role in place, so no duplicate is created.
- **Identity by name → by code**
  - `PlatformSuperadminRoleSeeder` and `DemoDataSeeder` use `ensurePlatformSuperadmin()`.
  - The `platform:create-admin` command uses `platformSuperadmin()`.
- **API**
  - Role responses add a read-only `code`.
  - The store / update requests do not accept `code`, so tenant roles stay `null`.
  - Frontend type `RoleItem.code`.
- **Dependency audit**
  - Locked-role checks in `RolePermissionService` and the role controllers use
    `scope = platform && is_system`, not the name; unchanged.
  - `ApprovalResolver` resolves `ROLE` approvers by tenant role **name**. That is tenant workflow
    configuration and is not changed: changing it would alter workflow semantics.
  - Tenant "Tenant Admin" roles (seeders only, one per tenant) are not backfilled.
- **Tests: `Feature/SystemRoleCodeTest`** (7 tests)
  - A: seeded with its code; reseeding creates no duplicate.
  - B: a tenant custom role has code `null`; the API cannot set one.
  - C: a duplicate `PLATFORM_SUPERADMIN` is rejected; the same tenant code is allowed in two tenants
    and rejected twice in one.
  - D / E: the backfill keeps name, permissions and user assignments, and is idempotent; the seeder
    adopts the existing role.
  - An ambiguous match is not backfilled.
  - The code is immutable and must be UPPER_SNAKE_CASE; renaming the display name keeps the identity.
  - `platform:create-admin` finds a renamed role by code.
- **Role regression**
  - DeploymentSeeder, DemoDatasetSeeder, RoleTenantIsolation, RolePermissionManagement,
    RewiredPermissions and ReferenceLabels pass.

### Workflow action labels

- **Status / action separation:** done. 34 legacy status-form label rows point to their
  `workflow.actionVerb.*` key. The two other action labels, "Revise" and "Close (External Invoice
  Paid)", are already verbs. Together these are the 36 reviewed.
- **Seeders:** every seeded transition label is the verb (Approve, Reject, Cancel, Complete, Hold, …).
  `WorkflowDefaultLabelsTest` asserts it, and workflow state codes are unchanged.
- **Remaining legacy entries:** none in source or seeds. Workflow versions already published in a
  tenant keep their stored labels, because pinned versions are never rewritten. The UI maps the legacy
  status-form default label to the verb (`isDefaultActionLabel`), and a tenant-customized label is
  shown as stored.

### Concurrency test result

`Feature/DocumentGenerationConcurrencyTest`: **PASS**.
- **Setup:** 8 separate PHP processes (`tests/Concurrency/first_print_worker.php`), each with its own
  database connection, are released at a shared start time.
  - All 8 request the first print of a never-printed document.
  - 3 rounds.
  - The tenant is committed through a separate connection and removed afterwards.
- **Expected and observed:** in each round there is exactly one generation, and all 8 workers receive
  its id.
- **Measured:** workers started within 13 ms of each other, and their completions were serialized by
  the advisory lock.
- **Negative control:** with the lock temporarily removed, the test failed with 8 distinct
  generations. So the test detects the race; the change was reverted.

### Document reprint characteristic

A reprint is **TEMPLATE/LOCALE HISTORICAL, TRANSACTION DATA CURRENT**. The generation pins the locale
and the template version; the business data shown is the document's current data at reprint time.
This is not a fully immutable document snapshot: no PDF or data snapshot is stored, so the
implementation does not produce a legally immutable historical PDF. The one exception is the Work
Authorization Letter, which renders from its own frozen snapshot columns. A fully immutable archive
would need stored output (`file_path` / `checksum`) or a data snapshot, which is a separate decision.

### Checkpoint (paused by owner)

The work is paused at this checkpoint until the owner says "Continue".
- **Done and pushed:** system role code (`1b9371e`), and this checkpoint commit (rich text, plurals,
  concurrency test, readiness docs).
- **Verified in this session:**
  - targeted backend tests: SystemRoleCode, ReferenceLabels, role suites, the concurrency test with
    its negative control;
  - frontend type-check, build, 23 unit tests, lint (0 errors, 27 existing warnings);
  - browser checks: tabs, status, S3, S4, S5, S6 documents, roles.
- **Not finished:** the final full backend regression was interrupted at 323 tests passed, 0 failed,
  for the pause, so it is NOT RUN to completion.
- **On "Continue":** rerun the full backend suite (`t.sh`). If it is green, confirm the readiness below
  and deliver the final report. If not, fix and re-evaluate.

### Final readiness

**Provisional: READY_FOR_I18N_IMPLEMENTATION_EXCEPT_MONGODB.** It becomes final only after the full
backend regression above passes. Every condition holds except the MongoDB-backed verification:
- terminology decisions: 0 unresolved;
- system-role identifier: stable (`roles.code`);
- all non-Mongo structural blockers resolved;
- rich text and plurals explicitly accepted as rollout items.

The only remaining structural blocker is the 25 Intelligence rows: **BLOCKED_BY_MONGODB_TEST_ENVIRONMENT**.
The implementation plan is `docs/i18n/16-i18n-implementation-plan.md`.
