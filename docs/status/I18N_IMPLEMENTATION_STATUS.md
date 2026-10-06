# i18n Implementation (EN / ID) — Status

Continuation checkpoint for the EN / ID rollout planned in `docs/i18n/16-i18n-implementation-plan.md`.

- **Entry condition:** `READY_FOR_I18N_IMPLEMENTATION_EXCEPT_MONGODB`
  (`docs/status/I18N_STRUCTURAL_PREPARATION_STATUS.md`).
- **Translation authority:** `docs/i18n/12-en-id-translation-dataset-final.csv` (texts) and
  `13-optifleet-translation-glossary-final.md` (terminology).
- **New strings and plural forms:** only through `docs/i18n/17-i18n-additions.csv`
  (`translation_key, source_text_en, translated_text_id, context, module, reason`).
- **MongoDB exclusion:** the 25 Intelligence rows marked `BLOCKED_BY_MONGODB_TEST_ENVIRONMENT` are not
  generated and their code is not touched (`DEFERRED_MONGODB_I18N_STRUCTURAL_CLOSURE`).

## Phases

| # | Phase | Status |
|---:|---|---|
| 1 | Locale contract + resource generation | DONE |
| 2 | Frontend / backend i18n foundation | DONE |
| 3 | User / tenant locale preference + language selector | DONE |
| 4 | Shared / global UI | DONE |
| 5+ | Business modules | IN PROGRESS |
| D | Printed documents | DONE |
| N | Notifications | DONE |
| QA | Hard-coded audit, full regression, report | — |

## Phase 1 — Locale contract and resource generation

**Locale contract**
- Supported locales: `en`, `id` (BCP 47 primary tags; `Intl` uses `en-US` / `id-ID`).
- English is the system fallback.
- Locale keys are codes, never display names.
- Frontend: `AppLocale` in `src/i18n/locale.ts`. Backend: `DocumentLocale::SUPPORTED` / `FALLBACK`.

**Generator: `frontend/scripts/i18n/generate.mjs`**
- Commands:
  - `npm run i18n:generate` writes the resources;
  - `npm run i18n:check` validates them and fails if the committed resources are stale.
- **Pipeline:** dataset `12` + additions `17` → generator → generated resources:
  - `frontend/src/i18n/locales/{en,id}/<namespace>.json`, nested by key path; the namespace is the
    first key segment, giving 44 namespace files (e.g. `common`, `nav`, `status`, `workOrder`, `tire`).
  - `backend/lang/{en,id}/catalog.php`: a flat `full.key => text` map for the backend message lookup.
- **Single source of truth:** the CSVs. The resources are generated, never edited by hand, and the
  check keeps them in sync.
- **Validation:**
  - duplicate keys (additions);
  - additions redefining a dataset key (only plural `_one` / `_other` / `_zero` variants may be added);
  - invalid keys or namespaces;
  - empty English or Indonesian text;
  - `{{param}}` mismatch;
  - rich-text tag mismatch (`<link>`, `<position>`);
  - a key colliding with a parent key in the nested layout.
- **Normalization:** legacy single-brace `{param}` becomes `{{param}}`.
- **Excluded (reported each run):**
  - 147 superseded keys (retired fragments);
  - 22 MongoDB-deferred keys (the other 3 blocked rows are also superseded);
  - 5 non-key rows: seeder configuration change summaries, historical metadata stored as is.
- **Result:** 5,281 keys per locale.

**Tests**
- Frontend `tests/unit/i18nResources.test.ts`:
  - the dataset generates cleanly;
  - the resources are up to date;
  - every validation rule fires on fixtures;
  - exclusions and placeholder normalization;
  - the CSV parser.
- Backend `Unit/TranslationCatalogTest`: identical keys and `{{params}}` in `en` / `id`.
- Frontend: type-check, build, 29 unit tests, lint (0 errors, 27 existing warnings).

## Phase 2 — Frontend and backend i18n foundation

**Frontend (i18next 26 + react-i18next 17; no other i18n library)**
- `src/i18n/i18n.ts`: one i18next instance. Keys are used with their full dotted path:
  `t('common.actions.save')`.
- **Loading:**
  - English is bundled and initialized synchronously before the first render (no flash).
  - Indonesian loads on first selection, as one lazy chunk per namespace (`src/i18n/resources.ts`).
- **Fallback:** a key missing in `id` falls back to English.
- **Missing keys:** a key missing in every locale is recorded in `missingKeys` and warned in
  development; the coverage test keeps it empty.
- **Locale switch:** `changeLocale(locale)`
  - unsupported values fall back to `en` without failing;
  - loads resources once;
  - updates `appLocale()` (the date / number formatters) and `<html lang>`.
- **Re-render:** the app root subscribes to language changes, so a switch re-renders every page.
- **Registries translate through i18n**, with their English constants as fallback when the runtime is
  not initialized (pure unit tests):
  - status labels;
  - default workflow action verbs;
  - automated actions;
  - whole-sentence messages.
- **Workflow labels:** stored labels are English, so `isDefaultActionLabel` keeps comparing against the
  English verb in any locale.
- `ISSUED` without a domain uses the document key (`status.issued` was superseded by the document /
  stock split).
- **API language contract:** every request sends `Accept-Language: <active locale>`. Messages come back
  localized; codes never are.

**Backend**
- `Messages::text(key, params, locale = 'en')` renders English by default. That is the language of text
  stored in records (notes, default complaints, inspection reasons, seeded names), which stays as stored
  (D2), and of the MongoDB-deferred Intelligence code, which is not touched.
- `Messages::localized()` renders in the request locale from `lang/<locale>/catalog.php`, falling back to
  English per key. Nested and list parameters render in the same locale.
- `CodedValidationException` uses it: the message is localized while `codes` stay identical.
- **Request locale** (`ResolveRequestLocale`, now on by default; also on `POST /auth/login`):
  1. user preferred locale;
  2. tenant default;
  3. `Accept-Language`;
  4. English.
  A client with no header and no stored preference gets English as before.
  `APP_RUNTIME_LOCALE_RESOLUTION=false` turns it off.

**Tests**
- Frontend `i18nRuntime.test.ts`:
  - EN default and ID on demand: `Simpan`, `Disetujui`, `Ditahan`, the verbs `Setujui` / `Tahan`, and
    `Batal` vs `Batalkan`;
  - English stored labels are recognized in ID;
  - localized sentence templates with parameters;
  - controlled-fixture fallback to English and missing-key detection;
  - an unsupported locale falls back to `en`.
- Frontend `i18nCoverage.test.ts`: every key referenced in code (`t()`, `i18nKey`, `translated()`,
  registry keys) exists in both locales, and the `en` / `id` key sets are identical.
- Backend:
  - `MessageLocalizationTest`: text stays English, localized follows the request locale, nested messages,
    fallback for a missing locale.
  - `ValidationLocalizationTest`: `Accept-Language`; preference over header; tenant default before
    header; login errors in `id`; the switch off.
  - `TireOperationTest`: a coded error is localized with an identical code.
- Frontend 36 unit tests, type-check, build, lint (0 errors, 27 existing warnings). Browser smoke:
  status, tabs, S4 checks pass.
- Full backend suite: 1164 passed, 1 failed. The failure was the backend `StatusLabels` mirror of the
  `ISSUED` change; it was aligned and re-run green (`StatusLabelsTest`, `WorkflowDefaultLabelsTest`).
  MongoDB suites NOT RUN.

## Phase 3 — Locale preference and language selector

**Data**
- `users.preferred_locale`: `en` | `id` | null. It existed since S6; null = follow the tenant default.
- `tenants.default_locale`: migration `2026_10_14_000001`.
  - Existing tenants are backfilled to `en`, the language they have always used, and the column default
    is `en`.
  - It stays nullable: null = no tenant default.
  - Idempotent; a tenant's own choice is kept.
- Values are codes, never display names; validated against `en` / `id`.

**API**
- `PATCH /auth/me/preferences {preferred_locale}`: any signed-in user, for their own preference only.
- `/auth/me` and the login response add `preferred_locale` and `tenant_default_locale`.
- The tenant default is set by:
  - a tenant admin, on `PUT /app/account/company` (`company.update`);
  - a platform admin, on `PUT /platform/tenants/{id}` (`tenant.update`, also accepted on create).

**Resolution (one contract, frontend `resolveUiLocale` = backend `ResolveRequestLocale`)**

user preference → tenant default → browser (`navigator.languages` / `Accept-Language`) → English.

- Before the first render the last resolved locale is applied from `localStorage` (`optifleet_locale`),
  or the browser's, so there is no language flash.
- After sign-in the user / tenant resolution is applied and cached.

**Language selector** (`components/LanguageSelector.tsx`)
- Placed in the tenant and platform headers and on the login page.
- Options are English and Bahasa Indonesia, each shown in its own language.
- Choosing switches the UI at once, with no reload. Signed in, it saves the preference, so it follows
  the user across reloads, sign-ins and devices.
- A failed save shows a localized error.
- **Company Profile:** "Default Language" (Default / English / Bahasa Indonesia). Saving re-applies the
  resolution.
- **Platform tenant UI:** a field for the tenant default is scheduled with the platform module
  migration; the API already accepts it.

**Authentication shell migrated** (first module)
- Login page: all texts via `t()`; labels associated with their inputs.
- Sign-in errors (`validation.auth.*`) and access errors in `TenantContextMiddleware`
  (`errors.http.*`) are localized; statuses are unchanged.
- The access errors are returned before the request locale is resolved, so they use the user's
  preference or `Accept-Language`.
- `Messages` now resolves any generated-catalog key, not only its built-in templates, so backend code
  can return any dataset message.

**New strings (controlled flow, `docs/i18n/17-i18n-additions.csv`)**
- `common.language.label`, `.en`, `.id`, `.default`, `.saveFailed`;
- `account.fields.defaultLanguage`;
- `platform.tenants.fields.defaultLanguage`.
- Terminology follows existing dataset usage ("Default", as in "Default Sistem").

**Tests**
- Backend `LocalePreferenceTest` (6 tests):
  - set / clear / validate the preference, kept across a new sign-in;
  - a platform user;
  - the tenant default via company profile with and without permission, and via platform;
  - the backfill (null → `en`, a chosen `id` kept, idempotent);
  - sign-in and access errors in `id` and `en` with unchanged statuses.
- Existing `AuthTest` and access suites: 49 passed.
- Frontend `localeResolution.test.ts`: preference wins; case C (tenant default); case D (browser, then
  English); display names ignored.
- **Browser `e2e_lang`, 13 checks:**
  - the login page switches language;
  - a sign-in error arrives in Indonesian;
  - A: selecting Indonesian switches at once, is persisted, statuses render in Indonesian, and it is
    kept after reload;
  - B: kept after a new sign-in, and switching back to English works;
  - C: tenant default;
  - D: browser → English;
  - no page errors.
- Frontend 40 unit tests, type-check, build, lint (0 errors, 27 existing warnings).
- **Backend full suite (MongoDB suites excluded), 1169 passed, 2 failed. Both were fixed and the
  targeted tests re-run:**
  - `MessagesTest`: when the test ran after a feature test, the stale facade application had no
    translator. `Messages` now checks that a translator is bound. The Unit suite run after a feature
    test passes 109/109.
  - `MaintenanceHistoryScopeTest` (query count): a pre-existing timing dependency. Sanctum writes
    `last_used_at` only when the second changes, which adds one UPDATE to a request that crosses a
    second boundary. The test now freezes time; 6/6 repeats pass.
    - The locale middleware no longer re-reads the tenant: `TenantContext` keeps
      `tenantDefaultLocale` from the row already loaded.

## Phase 4 — Shared / global UI

**Migration tool: `frontend/scripts/i18n/strings.mjs`**
- Commands: `audit [paths]` reports hard-coded user-facing strings; `migrate <paths>` rewrites
  unambiguous dataset matches to `t('key')`.
- Uses the TypeScript AST and matches:
  - JSX text and user-facing attributes (`label`, `placeholder`, `title`, `aria-*`, …);
  - string and template literals used as text;
  - mixed JSX children ("Page {n} of {m}") against `{{param}}` templates of the same shape.
- Key choice:
  - the dataset row whose `source_file` is this file wins, then `common.*`;
  - texts with different Indonesian translations stay **ambiguous** for a decision by hand (the
    Cancel heuristic handles the clear cases from `onClick`);
  - `status.*` keys are never picked for literals ("Open" button ≠ status Open);
  - module-level constants are reported, never rewritten (they would freeze one language).

**Navigation**
- **Sidebar (`tenantNav.ts`):** every group and item carries a `labelKey` (`nav.groups.*` /
  `nav.items.*`).
  - The English `label` stays the stable identity: React keys and the remembered expand state, so
    switching language does not collapse the menu.
  - `navLabel()` renders the label; menu search matches the shown (translated) label.
- **Platform nav and header dropdowns:** Account / Organization / Access use keys.
- **Breadcrumbs:** each static segment resolves to `breadcrumb.<camelCaseSegment>`.
  - `SEGMENT_LABELS` stays the English canonical text and fallback; a test enforces a 1:1 key and
    same-English mapping.
  - Record crumbs use `common.fields.valueDetail`: English singularizes the parent label ("Vehicle
    Detail"); Indonesian keeps the uninflected noun ("Detail Kendaraan").

**Shared components**
- Covered: Modal, ConfirmDialog, Pagination, RoleManager, AuditLogTable, Table / ScrollTable, States,
  SearchableSelect, FileUploadField / ImageUploadField, DocumentViewer, InfoTip, BackButton,
  RouteGuards, the layouts.
- 102 strings were migrated by the tool, plus the hand-resolved ones:
  - the dismiss Cancel in ConfirmDialog / RoleManager → `common.actions.cancel` (Batal);
  - Pagination and the permission counter as whole-sentence templates;
  - "Select all shown" / "Clear shown" / "Clear all" as whole keys, replacing a concatenated suffix;
  - ConfirmDialog's default confirm label;
  - file-rule descriptions (`labelKey`).
- **DocumentVersions** (print language / versions dialog): labels translated. Language names are
  endonyms (`common.language.en/id`).

**New strings (additions 17)**
- `common.actions.selectAllShown`, `clearShown`, `clearAll`;
- `documents.versions.button`, `title`, `generateNew`, `notPrinted`, `generated`, `open`.

**Audit after Phase 4 (components, layouts, navigation, App, auth, api, utils)**
- 0 ambiguous, 0 unmatched.
- Remaining findings are EXPECTED_NON_TRANSLATED:
  - the English identity labels in `tenantNav.ts` / `SEGMENT_LABELS` (rendered through keys);
  - CSS / key templates;
  - the "PDF" format name.

**Tests**
- Frontend `i18nSharedUi.test.ts`:
  - every sidebar entry has a key whose English equals its identity label;
  - Indonesian labels and search;
  - every breadcrumb segment maps to a key with the same English;
  - unknown segments are humanized;
  - no missing keys.
- Frontend unit tests 43/43, type-check, lint (0 errors, 27 existing warnings).
- **Browser `e2e_i18n_shared`, 12 checks:**
  - EN sidebar, header and breadcrumb;
  - switch to ID: sidebar groups, header dropdowns, logout, breadcrumb ("Beranda / Kendaraan") and the
    record breadcrumb all translated;
  - menu search matches "riwayat";
  - kept after reload;
  - no horizontal overflow on a 390 px viewport;
  - back to EN;
  - no page errors.
- Page bodies stay English until their module phase (5+).

## Phase 5 — Business modules (in progress)

**Commits**
- `5f72296` — helpers and backend message localization.
- `5353ca1` — platform, access, account, organization.
- `2a7a4c7` — vehicle, maintenance, work order, inspection.
- `ccc74f7` — inventory, procurement, partners, component assets.
- `6b767f2` — tire management (first pass).

**Backend: `ResponseMessageLocalizer` (response boundary, in `ResolveRequestLocale`)**
- English domain messages in `message` / `errors` are rendered in the request locale, matched by dataset
  English (exact or `{{param}}` template).
- 316 of the 322 backend message literals found by scan resolve to Indonesian. The rest are developer
  guards (immutability, workbook internals).
- `codes`, statuses and fields are unchanged; English requests are untouched.
- Ambiguous texts and templates that repeat a placeholder are skipped.

**Frontend patterns**
- `t('key')` for literals.
- `withLabels([...])` for option lists, `translatedRecord({...}, keys)` for code → label maps, label
  getters for nested constants.
- `TabDef.labelKey`: legacy `?tab=<English>` links still resolve.
- `workflowButtons`: module defaults translate; tenant renames are shown as configured.
- Money and dates follow the locale (D3).

**Dataset QA findings (reported; no re-translation)**
- `platform.modules.fields.no` translates the answer "No" as "No" → `common.fields.no` = Tidak (additions).
- 11 keys repeat a placeholder name (e.g. `tire.help.approvedValueValueDispositionFinalDisposition`).
  - They are never used by the codemod or the localizer.
  - The two screens that needed them use new keys with distinct placeholders.

**Validation**
- Backend full suite (MongoDB suites excluded): 1174 passed; locale tests re-run after the last
  localizer change: 20 passed.
- Frontend: type-check (tsconfig.app.json) clean, 46 unit tests pass, lint 0 errors.
- Browser checks (ID): platform 13 pages; vehicle / maintenance / WO / inspection crawl — no page errors.

**Further commits**
- `22b317c` — workshop, service invoices, warranty, master data.
- `b60615b` — configuration and the dataset plural rollout.
- tire follow-up: rich text through Trans; generator renames void-element tags (`<link>` → `<linkTo>`).
- analytics UI text (non-MongoDB).
- render fix: keyed labels through `labelText()`; `workflowButtons` output carries no labelKey.

**Pluralization**
- Count-based dataset plural rows used by the UI have `_one` / `_other` additions; the Indonesian is the
  dataset's single form. Unit-tested 0 / 1 / 2+ in EN and ID.
- Not converted: rows with two independent counts, or with list values ("module(s): A, B"). They keep
  the dataset wording, and the backend lists are rendered by the response localizer.

**Hard-coded audit (`npm run i18n:audit`, whole `src`)**
- Outside Intelligence: 0 unmatched, 0 ambiguous, 0 module-level.
- 24 EXPECTED_NON_TRANSLATED: units (kg, km/h, KB, mo), rule symbols (D_pull, A_max…), codes
  (FRONT_LEFT, NEW_STATUS, SEQ:N, CG-TYRE) and template paths (IN_APP.body).
- MONGODB_DEFERRED: `pages/tenant/intelligence` (81 matched, 7 unmatched), left untouched.

**Browser crawl (ID, all tenant routes)**
- No page errors.
- English left in UI chrome is either:
  - USER_GENERATED / tenant data: branch, product, template and worker-type names;
  - glossary terms kept in English: Work Order, Purchase Order, Purchase Request, Vendor, Workspace,
    Transfer, Total, Detail.

## Phase D — Printed documents

- **Language of a print:** explicit choice in the Print dialog → user preference → tenant default → `en`
  (`DocumentGenerationService::resolveLocale`). The dialog's default option sends no locale, so the server
  resolves it.
- **Template bodies:** `AddLocalizedDocumentTemplatesSeeder` publishes a NEW version of every platform
  default print template with `payload.locales.id.html`.
  - The body comes from `DocumentTemplateLocalizer`: text segments that are dataset English (tier 1
    `documents.*`, tier 2 any key with one translation) are replaced; markup and variables are unchanged.
  - A template whose English is not fully covered is left as it is (no half-translated body).
  - Tenant templates and published history are untouched. Idempotent.
- **Status values:** `{{x.status}}` → `{{x.status_label}}` (`StatusLabels::localized`, per document
  locale). The code stays available as `{{x.status}}`.
- **Dates and numbers:** formatted for the generation's locale (`15.000,50` in `id`, `15,000.50` in `en`).
- **Reprint:** a generation keeps its locale and template version (TEMPLATE/LOCALE HISTORICAL,
  TRANSACTION DATA CURRENT). A later change of the user's preference does not change a reprint.
- **Platform invoice PDF:** `documents.platformInvoice.*` labels, status label and number formatting per
  locale (`?locale=`, else the request locale).
- **Tests:** `DocumentLocalizationTest` (3 tests), plus the document / print / template suites (124) and
  invoice / billing / payment suites (93) — PASS.

## Phase N — Notifications

- **Language per recipient:** `RecipientLocaleResolver`: user `preferred_locale` → tenant `default_locale`
  → `en`. A recipient addressed by email only (vendor contact, custom email) gets the tenant default.
- **Recorded:** `notification_delivery_logs.locale` (nullable, additive migration). Rows queued before it
  existed resolve the language when they are sent.
- **Escalations:** each escalation target gets their own language.
- **Rendering:** `SendNotificationJob` renders `payload.locales.<locale>.channels.<CHANNEL>` and falls back
  to the version's own channels. Email subject and body and in-app text are all per recipient. The
  fallback email subject is `app.labels.notification`.
- **Templates:** `AddLocalizedNotificationTemplatesSeeder` publishes a NEW version of every platform
  default notification template with `locales.id.channels` (exact `notifications.*` dataset English).
  - Edited wording without a translation is left as it is.
  - Tenant templates and published history are untouched. Idempotent.
  - All 6 platform defaults are covered.
- **Unchanged:** event codes, variables, canonical values in the context (e.g. severity `CRITICAL`) and
  tenant-entered data.
- **Tests:** `NotificationLocalizationTest` (6 tests) and the notification engine / form suites — 25
  passed.

**Remaining**
- Editors for `locales.<locale>` (template, workflow labels, notification form) — plan step 11, not
  started.
- Final full regression, final report.
