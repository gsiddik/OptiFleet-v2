# 16 — i18n Implementation Plan (EN / ID rollout)

**Status:** plan only. Nothing here is implemented yet.

**Entry condition:** `READY_FOR_I18N_IMPLEMENTATION_EXCEPT_MONGODB` (see
`docs/status/I18N_STRUCTURAL_PREPARATION_STATUS.md`). The 25 MongoDB-backed Intelligence rows stay
blocked until an environment with MongoDB can run the Analytics / Intelligence suites. Step 9 below
schedules them.

**Sources of truth:**
- `12-en-id-translation-dataset-final.csv`: keys, English, final Indonesian, blocker and rollout
  flags;
- `13-optifleet-translation-glossary-final.md`;
- `14-final-translation-qa-report.md`;
- the structural registries built in S1–S8.

## Principles carried over

- Keys come from dataset `12`; text is never re-translated at rollout. Every rollout step adds keys to
  `12` first, then generates resources.
- Canonical values never change: status / action / role / module codes, enums, API identifiers,
  template variables, numbering tokens.
- Tenant-entered data stays single-language and is shown as stored (D2).
- Documents are single-language (D1). Reprints keep their generation's locale and template version
  (D4). Dates and numbers are formatted by locale at render time (D3).
- No generic translation table. System values use code + translation resource; tenant-editable
  configuration uses `payload.locales.<locale>` inside its own version.
- English stays the fallback at every level.

## Implementation order

The dependency order follows the recommended sequence. Step 2 comes before the frontend and backend
foundations because both consume the generated resources.

### 1. Locale contract and locale resolution

- **Contract:** `AppLocale = 'en' | 'id'`.
  - Resolution: explicit choice → `users.preferred_locale` → `tenants.default_locale` → `en`.
  - The backend already has this in `DocumentLocale::resolve` and `ResolveRequestLocale`.
- **API:**
  - Expose the resolved locale on `/auth/me`.
  - Add `PATCH` endpoints for `users.preferred_locale` (self) and `tenants.default_locale` (tenant
    admin, permission-gated). Both columns exist already.
- **Switch on** `APP_RUNTIME_LOCALE_RESOLUTION` only when steps 2–4 are deployed.
- **Tests:**
  - the resolution chain;
  - permission on the tenant default;
  - `Accept-Language` stays ignored (owner decision);
  - the S7 suite.

### 2. Translation resource generation

- **Generator** (scripted, CI-checked) from dataset `12`:
  - frontend namespaces `locales/{en,id}/<module>.json`;
  - backend `lang/{en,id}/*.php`, or JSON for the `Messages` catalog keys.
- **Exclusions:** rows that are superseded, `DO_NOT_TRANSLATE`, or `BLOCKED_BY_MONGODB_TEST_ENVIRONMENT`
  until done.
- **Placeholders:** convert `{{param}}`, and rich-text tags `<position>` / `<link>`, to the library
  syntax.
- **Parity checks** (extend the existing dataset parity tests):
  - every key referenced in code exists in both locales;
  - placeholders match;
  - no orphan keys.

### 3. Frontend i18n foundation

- **Library:** i18next + react-i18next, with ICU or i18next plurals and `Trans` for rich text. Load
  namespaces lazily per route.
- **Locale state:** feed `i18n/locale.ts` (`appLocale()`) from the resolved user locale.
  - `formatDate`, `formatNumber`, `formatMoney` and `monthNames` already take the locale.
  - Remove the hard-coded default.
- **Language selector:** in the user menu, saved to `users.preferred_locale`. A tenant-default control
  goes in tenant settings.
- **Tests:**
  - unit tests for the init and the fallback to `en`;
  - the existing unit suites (tabs, status registry, formatting, messages, workflow labels).

### 4. Backend localization foundation

- **`Messages::text()`:** render through the translator for the request locale, falling back to the
  English catalog. Keep the `{code, params}` contract.
- **Coded errors:** `CodedValidationException` and the decision-engine reason codes are rendered per
  locale; stored `reason_codes` are rendered on read. Old rows without codes show their stored text.
- **Queues and console:** pass an explicit locale (recipient or tenant), never the process default.

### 5. Global and shared UI

- Navigation, breadcrumbs (explicit labels from S3), page titles, tabs (stable ids from S1, labels
  via keys).
- Common actions: the cancel split `common.actions.cancel` = Batal and `cancelRecord` = Batalkan.
- Tables, pagination, empty states, confirmations, file viewer, `DocumentVersions` dialog.
- **Regression:** tab deep links and old `?tab=` values; breadcrumb labels.

### 6. Status and action mapping

- **Status labels:** `statusRegistry.ts` and `StatusLabels.php`, with `status.*` keys and
  domain-aware labels (ISSUED: documents vs stock).
- **Workflow action labels:**
  - default verbs `workflow.actionVerb.*`;
  - tenant labels: `WorkflowLabels::actionLabel(payload, transition, locale)`;
  - legacy status-form defaults are mapped by `isDefaultActionLabel`.
- **Automated actions:** `workflowAutomatedActions.ts`.
- **Reference values:** `ReferenceLabels::key()` for seeded system values and system roles.
  - Expose `name_key` / `code` in the relevant APIs.
  - A renamed value is shown as stored.

### 7. Validation

- Turn on `lang/id/validation.php` (S7) through the request locale.
- Add `attributes` display names for the fields with user-visible errors (dataset keys).
- Frontend forms show server messages as delivered and use `codes` (S5) for logic, never text.

### 8. API and system messages

- `Messages` catalog keys: errors, entitlement, notifications validation, tire and work order rules.
- Coded responses: the `codes` field stays the contract; `message` is localized.
- Additive only, for backward compatibility: no field is renamed or removed.

### 9. Business modules

Move module by module, each with its own commit and regression:
- Vehicles, Maintenance, Work Orders, Workshop, Inventory, Procurement, Tires, Contracts, Platform.
- **Intelligence / Analytics** run in a MongoDB environment: first finish the 25 blocked rows
  (UPPER_SNAKE_CASE codes beside the English, no business-logic change), then localize.
- Per module:
  - replace literals with keys from `12`;
  - keep whole-sentence templates (S4);
  - no string concatenation.

### 10. Rich-text translations

- `tire.help.missingTireDataNotice` (ACCEPTED_I18N_ROLLOUT_ITEM) via `Trans`:
  - `<position>` → `PositionLabel`;
  - `<link>` → router `Link`, or plain text when no vehicle.
- Apply the same rule to any other sentence with inline components: one key, tagged components, never
  split.

### 11. Database-configured localized data

- **Seeded defaults:**
  - add `payload.locales.id` to the platform default template, workflow and notification versions as
    **new published versions**;
  - tenants' pinned versions are untouched;
  - publishing re-runs the existing validators.
- **Editors:**
  - the template editor gains a language switch saving `locales.<locale>.html`;
  - the workflow builder edits `locales.<locale>.action_labels` (it already preserves unknown keys);
  - the notification form edits `locales.<locale>.channels`.
- **Tenant data:** stays as entered.

### 12. Printed documents

- Template bodies per locale come from step 11. Context labels (statuses, return options) come through
  the registries for the generation's locale.
- **Language choice:** the Print / Generate New Version locale already exists (S6). Default it to the
  resolved user locale.
- **Characteristic kept:** TEMPLATE/LOCALE HISTORICAL, TRANSACTION DATA CURRENT. If a legally immutable
  document is required, decide separately on stored output (`file_path` / `checksum`).
- **Tests:** cases A–D, the concurrency test, D3 formatting per locale.

### 13. Notifications

- Render with the recipient's resolved locale: `NotificationTemplateService::render(..., $locale)`
  already falls back to the version's own channel content.
- Email subject / body and in-app text are rendered per recipient. Escalations use the escalation
  target's locale.

### 14. Pluralization and full regression

- **Pluralization:** convert the 31 `I18N_PLURALIZATION_ROLLOUT_ITEM` rows to ICU `_one` / `_other`
  (English). Indonesian keeps a single form.
- **Full regression:**
  - backend suite plus the MongoDB suites;
  - frontend type-check, build, unit tests, lint;
  - browser checks in both locales: tabs, statuses, workflow actions, validation, documents (reprint
    keeps the locale), notifications, rich text, plurals.
- **Pseudo-locale run:** catch untranslated literals and truncation.

## Risks to manage during rollout

- Layout length: Indonesian strings are often longer. Check tables, buttons and badges in `id`.
- Cached permission or role data must not cache localized labels; cache codes only.
- Mixed-language screens while modules migrate. Ship per module behind the locale flag, or keep `en`
  until a module is complete.
- Historical records: stored English system text (notes, default complaints) stays as stored and is
  not re-localized (D2).
