# 15 — Structural i18n Execution Plan

**This is a plan only. Nothing in it was implemented.** No code, migration, enum, workflow, permission, tab or schema change was made.

## Inputs

- `10-structural-i18n-preparation-required.md`
- `12-en-id-translation-dataset-final.csv`, which holds 786 rows with `implementation_status = STRUCTURAL_PREP_REQUIRED`
- `14-final-translation-qa-report.md`
- Actual OptiFleet architecture:
  - Laravel 11 + PostgreSQL. The operational source of truth.
  - React 19 + Vite + TypeScript.
  - Versioned configuration: `configuration_sets` / `configuration_versions.payload` (JSONB) for NUMBERING, TEMPLATE, WORKFLOW and NOTIFICATION.
  - Master data tables share the shape `code` + `name` + `is_system` + `tenant_id`, where system rows have `tenant_id = null` and are read-only for tenants.

## Blocker inventory

Rows per blocker in the final dataset. A row can carry several blockers.

| # | Area | Rows | Main locations |
|---|---|---:|---|
| 1 | Stable tab IDs | 21 | `WorkOrderDetailPage.tsx` (11), `TenantDetailPage.tsx` (5), `VehicleDetailPage.tsx` (5), plus the URL `?tab=Contract` built in `ContractDetailPage.tsx:35` |
| 2 | Status display label registry | 46 | `components/StatusBadge.tsx` (154 usages in 112 files), 56 raw `{row.status/type/…}` renders, and `components/intelligence/RiskBadge.tsx` |
| 3 | Full sentence templates | 167 (76 new templates + 91 retired fragments) | `TireImportService`, `UsedTireDecisionEngine`, `UsedTireInspectionService`, `SparePartSaleService`, `EntitlementService`, `RolePermissionService`, `wheelLayout.ts`, `tirePosition.ts`, `ImportTiresModal.tsx`, `PartRequestListPage.tsx` |
| 4 | Error code decoupling | 98 | `UsedTireDecisionEngine` (62 reasons), the Intelligence data-readiness and training services, the `TireImportService` header contract, and `TireOperationFormPage.tsx:422` (`notice.includes('no tire data')`) |
| 5 | Runtime label mapping | 101 | `WorkflowDefaultsSeeder` (`ucwords` labels), `utils/date.ts` (static MONTHS array), `hooks/useWorkflowTransitions.ts` (`humanize`), `DocumentConfigList.tsx`, `TransitionConfigurationPanel.tsx`, `ComponentAssetDetailPage.tsx`, `MaintenanceRequestDetailPage.tsx`, and code humanizers in `VehicleListPage.tsx` |
| 6 | Database localization | 420 | Workflow display names and action labels, print templates (14 platform defaults + tenant copies), notification templates and default rule names, seeded master data (modules, component taxonomy, product reference data, UoM, vehicle categories), stored notes and reasons |
| 7 | Laravel validation localization | 36 rules | 75 FormRequests and 227 `validate()` calls; 2 `messages()` methods and 0 `attributes()` methods |
| + | Workflow action label correction | 33 | Status-form `action_label` (e.g. "Approved" used as a button label). Verb proposals are in `workflow.actionVerb.*`. |
| + | Printed document locale snapshot (`PRINT_LOCALE_SNAPSHOT`, owner decision D1) | 7 print endpoints | `DocumentTemplateRenderService::render()` callers in the tenant controllers; `DocumentTemplateContextBuilder` (canonical `status` codes). No dataset rows: this is behaviour, not text. |

## Recommended execution order

Each step is ordered so it has no unmet technical dependency.

| Order | Area | Why here |
|---:|---|---|
| 0 | i18n foundation: locale resolution (user preference → tenant default → `en`; for print/export an explicit document language comes first — owner decision D1), message catalogs on both tiers | Every other step emits keys and needs somewhere to resolve them. This is a separate, explicitly approved implementation task. |
| 1 | Stable tab IDs | Pure frontend, very small blast radius. It removes a hidden coupling before any label changes. |
| 2 | Status display label registry | Shared by badges, filters, options, the workflow builder and print templates. Step 5 depends on it. |
| 3 | Runtime label mapping | Replaces humanizers with registry lookups from step 2. Dates move to `Intl` with an explicit locale. |
| 4 | Full sentence templates | Mechanical once catalogs exist (step 0). Independent of the database. |
| 5 | Error code decoupling | Needs step 4's templates (most reasons are parameterized) and the API error envelope from step 0. |
| 6 | Laravel validation localization | Needs backend locale resolution (step 0); independent of the frontend. |
| 7 | Database localization strategy | Largest change. Needs the owner decisions (`07`, terminology and D1/D2 decided), the status/action registry (step 2) and the workflow action verb correction. |
| 8 | Printed document locale (D1) | Needs step 0 (locale resolution), step 2 (status labels on documents) and step 7 (per-locale template labels). Adds the generation record that pins the locale. |

Steps 1 and 6 can run in parallel with steps 2–5.

---

## 1. Stable tab IDs

**Current problem**
- Tabs are stored and compared by their English label (`useState('Overview')`, `tab === 'Complaint'`).
- A deep link uses the label (`?tab=Contract`).
- Translating the label would break tab selection and links.

**Affected files/modules**
- `frontend/src/pages/tenant/workorders/WorkOrderDetailPage.tsx`: INTERNAL_TABS, EXTERNAL_MODE_TABS, and the order-aware `visibleTabs`.
- `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx`: TABS and TAB_PERMISSION.
- `frontend/src/pages/platform/tenants/TenantDetailPage.tsx`: TABS and initialTab.
- `frontend/src/pages/platform/contracts/ContractDetailPage.tsx:35`.
- Back-navigation trail context, if tabs are persisted there.

**Required change**
- Introduce a tab id (`overview`, `complaint`, …) and render the label from a key (`workOrder.sections.complaint`).
- Map legacy label-based query values to ids so that old links still work.
- Keep permission gating keyed by id.

**Dependency**: none, apart from step 0 for rendering keys. It can ship before the foundation by mapping id → current English label.

**Risk**: Low. The main risks are broken deep links and lost tab state on back-navigation.

**Testing strategy**
- Unit test the id ↔ label map.
- e2e: open each tab, deep-link `?tab=` with both the old label and the new id, back-navigation, and permission-hidden tabs (Wheels Configuration, External mode tabs).

**Order**: 1

## 2. Status display label registry

**Current problem**
- `StatusBadge` prints canonical codes (`UNDER_REVIEW`), uppercased by CSS.
- `RiskBadge` does the same for risk levels.
- 56 places render enum values raw.
- The same code means different things in different domains: ISSUED is "document issued" for PO/RFQ/invoice and "stock issued" for part requests.

**Affected files/modules**
- `components/StatusBadge.tsx`, `components/intelligence/RiskBadge.tsx`, the status option lists in list pages, the workflow builder status cards, and `MaintenanceRequestDetailPage`.
- Backend responses that already return `display_name` (workflow).

**Required change**
- A registry keyed by `(domain, code)` → translation key, for example `status.document.issued` / `status.stock.issued`. The dataset already contains the keys and Indonesian labels.
- Badge, filters and options read from it. An unknown code falls back to the code itself, with a dev warning.
- Canonical values and API contracts stay unchanged.

**Dependency**: step 0. Step 3 and the workflow-label part of step 7 build on it.

**Risk**: Medium. The large number of call sites (112 files) risks missed renders. Mitigate with a lint rule or a grep gate that bans raw `{x.status}` in JSX.

**Testing strategy**
- Snapshot test of the registry: every canonical status in the DB enums and workflow catalog has a key.
- Component tests for the badge per domain.
- e2e visual pass on list and detail pages.

**Order**: 2

## 3. Full sentence templates

**Current problem**: 80 code locations concatenate sentence fragments (`'Cannot enable module '.$code.': missing required module(s): '…`). Indonesian word order cannot be expressed piecewise.

**Affected files/modules**: see the table above. The 76 replacement keys (marked "NEW KEY") are in `12`, and the fragment rows carry `superseded_by`.

**Required change**
- Replace each concatenation with one parameterized message.
- Split ternary pieces into separate keys (e.g. repair vs retread form title, approve vs cancel confirm).
- Plurals use ICU plural forms on the English side.

**Dependency**: step 0.

**Risk**: Low to medium. Parameters could be passed incorrectly; the dataset's parameter names are the contract.

**Testing strategy**
- Unit test per template with sample parameters.
- Backend feature tests asserting the error text through the locale.
- Parameter-integrity check: compare the placeholders in the catalog against the call site.

**Order**: 4

## 4. Error code decoupling

**Current problem**
- The UI checks message text (`notice.includes('no tire data')`).
- The tire import contract requires English headers and the sheet name "Fill Here".
- The decision engine and intelligence services build and persist English reason sentences.

**Affected files/modules**: `UsedTireDecisionEngine`, `UsedTireUsageRestrictions`, `DataReadinessAssessmentService`, `TrainingPipelineService`, `TireImportService`, `TireOperationService:457` together with `TireOperationFormPage.tsx:422`, and the Intelligence controllers.

**Required change**
- API errors carry `{code, params, message}`; the UI branches on `code`.
- Reasons are stored as `{code, params}`, with the English text kept for history, and rendered through keys.
- Import accepts a stable column id (English header or Indonesian header). The template sheet can be bilingual, but the parser must stay backward compatible.

**Dependency**: steps 0 and 3.

**Risk**: Medium. Historical records already contain English sentences, so display must fall back to the stored text when no code exists.

**Testing strategy**
- Contract tests for the error envelope.
- Decision-engine tests asserting codes.
- Import tests with English and Indonesian headers and with old files.
- e2e for the "Open Vehicle Details" link that currently depends on message text.

**Order**: 5

## 5. Runtime label mapping

**Current problem**
- Labels are generated from codes: `ucwords(str_replace('_',' ',$code))` in seeders, `code.replace(/_/g,' ')` in React, and a hard-coded English MONTHS array.
- 76 `toLocaleString()` calls depend on the browser locale.

**Affected files/modules**: `WorkflowDefaultsSeeder`, `AddWorkOrderExternalStatusSeeder`, `ConfigurationDefaultsSeeder` (set names), `NotificationDefaultsSeeder`, `utils/date.ts`, `useWorkflowTransitions.ts`, `DocumentConfigList.tsx`, `TransitionConfigurationPanel.tsx`, and the component asset pages.

**Required change**
- Look labels up in the registry from step 2 (keys `workflow.status.*`, `documentType.*`, `workflow.actionVerb.*`).
- Format dates and numbers through `Intl` with an explicit app locale.
- Leave money formatting rules unchanged (decimal-safe).

**Dependency**: step 2.

**Risk**: Low.

**Testing strategy**
- Unit tests for the formatters in both locales.
- Search gate for `replace(/_/g` in render paths.

**Order**: 3

## 6. Laravel validation localization

**Current problem**: default messages come from `vendor/.../lang/en/validation.php`. Attribute names are derived from snake_case field names, and no `attributes()` exist.

**Affected files/modules**: 75 FormRequests and 227 inline `validate()` calls in controllers.

**Required change**
- Publish `lang/en/validation.php` and `lang/id/validation.php`; the 36 used rules are already translated in `12` with the `:attribute` placeholders intact.
- Add `validation.attributes` (field display names).
- Resolve the request locale from the authenticated user or tenant, never from client input alone.
- Move the 142 custom messages into domain lang files.

**Dependency**: step 0 (backend locale resolution).

**Risk**: Low to medium. The `errors` payload shape must stay identical; only the text changes.

**Testing strategy**
- Feature tests per locale on representative FormRequests.
- Snapshot of the 422 payload shape.

**Order**: 6 (can run in parallel)

## 7. Database localization strategy

**Current problem**
- User-visible labels are stored as data with no locale dimension:
  - workflow `display_name` / `action_label` inside `configuration_versions.payload` (JSONB, versioned, pinned per document);
  - print template HTML and notification subject/body in the same configuration payloads;
  - default notification rule names in `notification_rules.name`;
  - seeded master data `name` (modules, component groups, categories and subcategories, product reference data, UoM, vehicle categories);
  - notes and reasons appended to records (e.g. "Rejected: …", default complaints).

### Options compared

| Option | Description | Fit with OptiFleet |
|---|---|---|
| **A. Separate columns** (`name_en`, `name_id`) | One column per locale | Simple reads. But it changes many tables and needs a migration per new locale. It does not fit JSONB configuration payloads or tenant-edited documents. It doubles every `name` the tenant edits. |
| **B. Translation table** (`entity_type, entity_id, locale, field, value`) | Generic side table | Flexible and adds locales without schema change. But it needs a join on every list, and tenant isolation and RBAC must be enforced on a polymorphic table. It is awkward for versioned JSONB payloads, where translations must be versioned with the payload, and heavy for 20–30 seeded rows. |
| **C. Canonical code + application translation resources** | Display = `t('masterData.componentGroup.' + code)`, falling back to the stored `name` | Matches the existing `code` + `is_system` design. No schema change for system rows. Versioned with the code. Cannot cover text that tenants type themselves. |
| **C+. Localized map inside versioned payload** (a variant for configuration data) | `payload.locales.{en,id}` or `{display_name: {en, id}}` in the same JSONB version | Keeps translations versioned and pinned exactly like the configuration they belong to, through the same publish/draft flow. No new table. |

### Recommendation by entity type

| Entity | Recommendation | Reason |
|---|---|---|
| **System master data** (`is_system = true`): component groups, categories, subcategories, vehicle categories, UoM, product reference data, modules, system roles | **C**, keyed by `code`, falling back to the stored `name` | The rows are platform-owned, read-only for tenants, and already have stable codes. Translation ships with the application. |
| **Tenant-created master data and records** (`is_system = false`, tenant roles, tenant notification rule names, vendors, notes) | **Not translated.** Store and display the original value. | **Owner decision D2**: tenant-entered and user-generated data is not bilingual — no automatic translation, no separate EN/ID fields. If multilingual tenant-managed master data is introduced later as an optional capability (only with a clear business requirement), use **B** scoped by `tenant_id`. |
| **Workflow system defaults** (statuses, actions) | **C**: `workflow.status.<code>`, `workflow.actionVerb.<action_code>`; the stored `display_name` / `action_label` become fallbacks | Codes are canonical and catalog-bounded. This removes `ucwords` and fixes the status-form actions at the same time. |
| **Tenant custom workflow labels** | **C+**: optional `{en, id}` map per status and transition inside the version payload | Tenants can rename labels. Keeping them in the payload preserves versioning and pinning. |
| **Print templates** (platform defaults and tenant copies) | **C+**: per-locale HTML variants in the version payload (`payload.locales.id.html`), with the document-language rule of owner decision D1 (see *7a*) | Whole documents cannot be key-translated because tenants edit the HTML. One language per printed document, not both at once (D1). |
| **Notification templates** | **C+**: per-locale subject/body per channel in the payload; the recipient's locale picks the variant | Same versioning model as templates. Recipients may have different languages. |
| **Default rule and set names** (seeded) | **C**, keyed by event code or set code, falling back to the stored name | Platform-owned. |
| **Stored notes and reasons** appended by code | Store `{code, params}` (step 5) and render through **C**; keep existing English history as-is | Historical text cannot be re-translated reliably. |

**Overall**: use **C** for everything platform-owned and code-identified, and **C+** for versioned configuration that tenants can edit. Avoid **A**. **B** is not needed now (D2); it stays reserved for a future, optional multilingual tenant master-data capability. This keeps PostgreSQL the operational source of truth, keeps tenant isolation within existing scoped tables, and needs no migration for system data. The C+ changes are additive JSONB payload fields with a backward-compatible reader.

**Dependency**
- Owner terminology decisions (`07`).
- The status/action registry (step 2).
- The workflow action verb correction.
- Owner decisions D1 (printed document language) and D2 (tenant-entered data): both decided, see *7a* and *7b*.

**Risk**: High. It touches versioned, pinned configuration and print output. Existing published versions must render unchanged, so the reader must fall back to the current fields.

**Testing strategy**
- Seeder idempotency tests.
- Configuration version read compatibility: old payloads render identically.
- Workflow engine tests confirming that transitions still resolve on codes.
- Template render tests per locale.
- Notification dispatch per recipient locale.
- Tenant-isolation tests on any new lookup.

**Order**: 7

## 7a. Printed document locale (owner decision D1)

**Decision**: printed and exported documents render in **one** locale. They do not show English and Indonesian at the same time. Do not assume every template must contain both languages.

**Locale resolution for print/export**, in priority order:
1. The language the user explicitly selects in the Print/Export action.
2. The user's preferred locale.
3. The tenant's default locale.
4. The system fallback, `en`.

**What follows the document locale, and what does not**

| Follows the selected locale | Stays unchanged (dynamic business data) |
|---|---|
| Static labels, section titles, document instructions, table headers | Document numbers, dates and amounts as values, quantities |
| Status labels (rendered from the canonical code) | Vendor, vehicle, product and party names |
| System-generated messages on the document | Tenant-entered text: findings, notes, remarks, descriptions, reasons (D2) |
| Predefined / reference values (system master data, UoM, categories) through **C** | Canonical codes and numbering tokens |

**Current state** (verified in code):
- 7 print endpoints (e.g. `PurchaseOrderController::print`, `WorkOrderController::print`) call `DocumentTemplateRenderService::render()` and stream the PDF.
- The render returns `template_version_id`, but the caller discards it. **Nothing about a print is persisted**: no locale, no template version. Every reprint re-renders against the current effective template.
- `DocumentTemplateContextBuilder` passes canonical codes such as `status` (`APPROVED`), not display labels.
- Money and quantities are pre-formatted by `DisplayFormat`, which uses a fixed format.

**Structural requirement — new blocker `PRINT_LOCALE_SNAPSHOT`** (not implemented; a schema change, needs explicit approval):
- The Print/Export request accepts an optional `locale`, validated against the supported locales. The server resolves the effective locale with the chain above. The tenant default comes from server-side tenant context, never from client input.
- Persist a tenant-scoped generation record per generated document: document type and id, `locale`, `template_version_id`, generated by/at. A reprint of a historical document reuses the recorded locale and template version unless the user explicitly asks for a new generation, so historical/audit output stays consistent.
- Template label text resolves per locale: platform defaults through **C+** (per-locale label maps or HTML variants in the version payload); tenant copies fall back to their single stored HTML when no variant exists for the locale. Existing published versions render unchanged.
- The context builder adds locale-resolved display labels next to the canonical values (e.g. keep `status`, add `status_label`), so existing templates using `{{status}}` keep working.

**Testing**: render tests per locale; the fallback chain (explicit > user > tenant > `en`); reprint keeps the recorded locale; tenant isolation on the generation record; old template versions render identically.

**Open point (not decided, not assumed)**: whether date and number *formatting* (e.g. `1.234,56` vs `1,234.56`, month names) follows the document locale. The values themselves stay unchanged either way.

## 7b. Tenant-entered data (owner decision D2)

**Decision**: tenant-entered and user-generated data is **not** required to be bilingual.
- Store and display the original value the user entered: findings, notes, remarks, descriptions, comments, reasons, free-text instructions, user-entered transaction descriptions.
- No automatic translation and no separate EN/ID fields.

**Localization scope**: only system-controlled text — labels, statuses, actions, validations, notifications, document labels and predefined/reference values.

**Mixed text**: where code appends a system prefix to user text (e.g. `"Rejected: " + reason`), only the system part is localized (stored as `{code, params}`, step 5). The user's reason stays verbatim.

**Dataset impact**: none. All 420 `DATABASE_LOCALIZATION` rows in `12` are seeded or system data (configuration defaults, workflow defaults, master and reference data, modules, notification defaults). Tenant/demo data was already DO_NOT_TRANSLATE. No row was reclassified.

**Later, optional**: multilingual tenant-managed master data is a possible future capability, only with a clear business requirement. It would use option **B** scoped by `tenant_id`.

## Open decisions before execution

1. ~~The terminology and style decisions in `07`~~: decided (43 terms + 6 style). Remaining: 4 correction items (Tire, Warranty Claim, Wheels Configuration, Hold).
2. ~~Language of printed documents~~: decided (D1, see 7a).
3. ~~Whether tenant-entered data must be bilingual~~: decided, not bilingual (D2, see 7b).
4. English verb wording for workflow actions (`workflow.actionVerb.*`).
5. Whether date/number formatting on printed documents follows the document locale (7a).
6. Approval of the schema change for the document generation record (`PRINT_LOCALE_SNAPSHOT`).
